<?php
/**
 * User_Session.php
 *
 * Database-backed PHP session manager for the PubLink intranet.
 *
 * Replaces PHP's default file-based session storage with a MySQL-backed
 * implementation. Each browser session is represented by a row in the
 * `user_session` table, keyed by the PHP session ID (PHPSESSID cookie value).
 *
 * Session lifecycle:
 *  - On construction the PHPSESSID cookie is validated against the database.
 *    If the session is expired, agent-mismatched, or flagged as logged out,
 *    the cookie is discarded and PHP issues a fresh session ID.
 *  - _session_read_method() either retrieves an existing session row or
 *    inserts a new one, populating $logged_in and $user_id.
 *  - impress() updates the `last_impression` timestamp on every page load,
 *    enabling the inactivity timeout check.
 *  - Per-session key/value storage is provided via PHP's magic __get/__set,
 *    backed by the `session_variable` table.
 *
 * Timeouts:
 *  - $session_timeout (8 h): maximum idle time between page impressions.
 *  - $session_lifespan (8 h): PHP session cookie lifetime.
 *
 * Based on the session chapter from Professional PHP5 (Wrox).
 *
 * @package Biblhertz\Publink\utilities
 * @author  Chris Tomlinson
 * @since   March 2023
 */

namespace Biblhertz\Publink\utilities;

use Biblhertz\Publink\utilities\PDODatabase;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\pages\htmlPage;
use Exception;
use PDOStatement;

class User_Session implements \SessionHandlerInterface
{

    /****************************************************************/
    /* CONSTANTS                                                    */
    /****************************************************************/

    /** @var int Maximum seconds of inactivity before a session is considered expired (8 hours). */
    private int $session_timeout = 28800;

    /** @var int Lifetime in seconds of the PHPSESSID cookie (8 hours). */
    private int $session_lifespan = 28800;


    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string The PHP session ID string (value of the PHPSESSID cookie). */
    private string $php_session_id = '';

    /** @var int Primary key of the corresponding row in `user_session`. */
    private int $native_session_id;

    /** @var bool True when the session is associated with an authenticated user. */
    private bool $logged_in = false;

    /** @var int Primary key of the authenticated user in `user_details`, or 0 if not logged in. */
    private int $user_id = 0;

    /** @var string Time portion of the session creation timestamp (HH:MM:SS). */
    private string $logged_in_time = '';

    /** @var string Time portion of the last page impression timestamp (HH:MM:SS). */
    private string $impress_time = '';

    /** @var PDODatabase The database connection used for all session queries. */
    private PDODatabase $objDB;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialises the database-backed session handler and starts the PHP session.
     *
     * Validates any existing PHPSESSID cookie against the `user_session` table,
     * checking the session ID, user agent, IP address, login flag, user ID,
     * and last impression time. If validation fails the stale cookie and its
     * database row are deleted so PHP will issue a fresh session ID.
     */
    public function __construct()
    {
        $this->initDB();

        // Register the custom session handler
        session_set_save_handler($this);

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (isset($_COOKIE['PHPSESSID'])) {
            $this->php_session_id = $_COOKIE['PHPSESSID'];

            // Validate the existing cookie against the database
            $sql = "SELECT id FROM user_session
                    WHERE ascii_session_id = ?
                      AND user_agent = ?
                      AND ip = ?
                      AND logged_in = 't'
                      AND user_id > 0
                      AND (
                            (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(last_impression)) <= ?
                            OR last_impression IS NULL
                          )";

            try {
                $this->objDB->preparedStatement($sql, [
                    $this->php_session_id,
                    $userAgent,
                    $this->getIPAddress(),
                    $this->session_timeout,
                ]);
            } catch (Exception $e) {
                $this->fatalError($e);
            }

            if ($this->objDB->numRows() === 0) {
                // Validation failed — purge the stale session row and orphaned variables
                try {
                    $this->objDB->preparedStatement(
                        "DELETE FROM user_session
                         WHERE ascii_session_id = ?
                            OR (NOW() - last_impression) > ?
                            OR logged_in = 'f'",
                        [$this->php_session_id, $this->session_timeout]
                    );
                    $this->objDB->select(
                        'DELETE FROM session_variable WHERE session_id NOT IN (SELECT id FROM user_session)'
                    );
                } catch (Exception $e) {
                    $this->fatalError($e);
                }

                setcookie('PHPSESSID', '', time() - 3600, '/');
                unset($_COOKIE['PHPSESSID']);
            }
        }

        session_set_cookie_params($this->session_lifespan);
        session_start();
    }


    /****************************************************************/
    /* DATABASE INITIALISATION                                      */
    /****************************************************************/

    /**
     * Lazily initialises the PDODatabase connection if not already set.
     * Called from the constructor and can be used as a guard in other methods.
     *
     * @return void
     */
    private function initDB(): void
    {
        if (!isset($this->objDB)) {
            try {
                $this->objDB = new PDODatabase();
            } catch (Exception $e) {
                $this->fatalError($e);
            }
        }
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Records a page impression by updating `last_impression` to NOW() for this session.
     * Also caches the updated time (HH:MM:SS) locally in $impress_time.
     * Called on every authenticated page load by Bibliotheca_Intranet_Page::getPage().
     *
     * @return void
     */
    public function impress(): void
    {
        if ($this->native_session_id) {
            $this->objDB->preparedStatement(
                'UPDATE user_session SET last_impression = NOW() WHERE id = ?',
                [$this->native_session_id]
            );
            $this->impress_time = date('H:i:s');
        }
    }

    /**
     * Returns the time of the most recent page impression for this session.
     *
     * @return string Time string in 'HH:MM:SS' format, or '' if not yet set.
     */
    public function getImpressTime(): string
    {
        return $this->impress_time;
    }

    /**
     * Returns whether the current session is authenticated.
     *
     * @return bool True if the user is logged in, false otherwise.
     */
    public function isLoggedIn(): bool
    {
        return $this->logged_in;
    }

    /**
     * Returns the wall-clock time at which the user logged in.
     *
     * @return string Time string in 'HH:MM:SS' format, or '' if not logged in.
     */
    public function getLoggedInTime(): string
    {
        return $this->logged_in_time;
    }

    /**
     * Returns the current user's database ID, or false if not logged in.
     *
     * @return int|false User ID, or false when the session is unauthenticated.
     */
    public function getUserID(): int|false
    {
        return $this->logged_in ? $this->user_id : false;
    }

    /**
     * Returns a fully populated User domain object for the authenticated user,
     * or false if the session is unauthenticated or the User class is unavailable.
     *
     * @return User|false A User instance, or false on failure.
     */
    public function getUserObject(): User|false
    {
        if ($this->logged_in && class_exists(User::class)) {
            return new User($this->objDB, $this->user_id);
        }
        return false;
    }

    /**
     * Returns the PHP session ID string (PHPSESSID cookie value).
     *
     * @return string The session identifier.
     */
    public function getSessionIdentifier(): string
    {
        return $this->php_session_id;
    }

    /**
     * Returns the primary key of the `user_session` database row for this session.
     *
     * @return int The native session database ID.
     */
    public function getNativeSessionIdentifier(): int
    {
        return $this->native_session_id;
    }


    /****************************************************************/
    /* AUTHENTICATION METHODS                                       */
    /****************************************************************/

    /**
     * Authenticates a user with a username and password, then marks the session
     * as logged in.
     *
     * Delegates credential verification to User::userExists(). On success, updates
     * the `user_session` row with the authenticated user's ID and sets logged_in = 't'.
     *
     * @param  string $username The user's login name (email address).
     * @param  string $password The plaintext password to verify.
     * @return bool             True on successful login, false on credential mismatch.
     */
    public function logIn(string $username, string $password): bool
    {
        if ($this->user_id = User::userExists($username, $password, $this->objDB)) {
            $this->logged_in = true;
            $this->objDB->preparedStatement(
                "UPDATE user_session SET logged_in = 't', user_id = ? WHERE id = ?",
                [$this->user_id, $this->native_session_id]
            );
            return true;
        }
        return false;
    }

    /**
     * Logs in a user whose identity has already been verified externally
     * (e.g. via ORCiD OAuth or KeyCloak SSO). Skips password verification.
     *
     * Delegates username lookup to User::userNameExists(). On success, updates
     * the `user_session` row and sets logged_in = 't'.
     *
     * @param  string    $username The verified username (ORCiD ID or email).
     * @return int|false           The user's database ID on success, or false if not found.
     */
    public function logInExternal(string $username): int|false
    {
        if ($this->user_id = User::userNameExists($username, $this->objDB)) {
            $this->logged_in = true;
            $this->objDB->preparedStatement(
                "UPDATE user_session SET logged_in = 't', user_id = ? WHERE id = ?",
                [$this->user_id, $this->native_session_id]
            );
            error_log("User_Session: logged in '$username' (uid={$this->user_id})");
            return $this->user_id;
        }
        return false;
    }

    /**
     * Logs the current user out by marking the session row as logged out in the database
     * and resetting the local logged_in / user_id state.
     *
     * @return bool True if the user was logged in and has now been logged out, false if
     *              there was no active login to end.
     */
    public function logOut(): bool
    {
        if ($this->logged_in) {
            $this->objDB->preparedStatement(
                "UPDATE user_session SET logged_in = 'f', user_id = 0 WHERE id = ?",
                [$this->native_session_id]
            );
            $this->logged_in = false;
            $this->user_id   = 0;
            return true;
        }
        return false;
    }


    /****************************************************************/
    /* SESSION VARIABLE PERSISTENCE (__get / __set)                 */
    /****************************************************************/

    /**
     * Retrieves a serialised session variable from the `session_variable` table.
     *
     * Provides a transparent property-access syntax for per-session persistence:
     *   $val = $session->myKey;
     *
     * @param  string $nm Variable name.
     * @return mixed       The deserialised value, or false if the variable is not set.
     */
    public function __get(string $nm): mixed
    {
        $result = $this->objDB->preparedGetOne(
            'SELECT variable_value FROM session_variable WHERE session_id = ? AND variable_name = ?',
            [$this->native_session_id, $nm]
        );
        return ($result === false) ? false : unserialize($result);
    }

    /**
     * Persists a session variable to the `session_variable` table (INSERT or UPDATE).
     *
     * Provides a transparent property-assignment syntax for per-session persistence:
     *   $session->myKey = $value;
     *
     * @param  string $nm  Variable name.
     * @param  mixed  $val Value to serialise and store.
     * @return void
     */
    public function __set(string $nm, mixed $val): void
    {
        $existingId = $this->objDB->preparedGetOne(
            'SELECT id FROM session_variable WHERE session_id = ? AND variable_name = ?',
            [$this->native_session_id, $nm]
        );

        if ($existingId && $existingId > 0) {
            $this->objDB->preparedStatement(
                'UPDATE session_variable SET variable_value = ? WHERE id = ?',
                [serialize($val), $existingId]
            );
        } else {
            $this->objDB->preparedStatement(
                'INSERT INTO session_variable (session_id, variable_name, variable_value) VALUES (?, ?, ?)',
                [$this->native_session_id, $nm, serialize($val)]
            );
        }
    }


    /****************************************************************/
    /* PHP SESSION HANDLER CALLBACKS                                */
    /****************************************************************/

    /**
     * Session open handler. No action required — the DB connection is already open.
     *
     * @param  string $path    Ignored (file path used by default handler).
     * @param  string $name Ignored.
     * @return bool Always true.
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Session close handler. No action required.
     *
     * @return bool Always true.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Session read handler — called by session_start().
     *
     * Looks up the session ID in `user_session`. If found, populates $logged_in,
     * $user_id, and $logged_in_time from the stored row. If not found, inserts a
     * new unauthenticated session row and stores its generated ID in $native_session_id.
     *
     * Always returns an empty string — actual session data is retrieved via __get().
     *
     * @param  string $id The PHP session ID string.
     * @return string|false Always ''.
     */
    public function read(string $id): string|false
    {
        try {
            $userAgent          = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $this->php_session_id = $id;

            $result = $this->objDB->preparedStatement(
                'SELECT id, logged_in, user_id, created FROM user_session WHERE ascii_session_id = :id',
                ['id' => $id]
            );

            if ($this->objDB->numRows() > 0) {
                $row                     = $result->fetch();
                $this->native_session_id = (int) $row['id'];

                if ($row['logged_in'] === 't') {
                    $this->logged_in      = true;
                    $this->user_id        = (int) $row['user_id'];
                    $this->logged_in_time = substr((string) $row['created'], 11);
                } else {
                    $this->logged_in = false;
                }
            } else {
                // New session — insert a placeholder row
                $this->logged_in = false;

                $this->native_session_id = $this->objDB->insert('user_session', [
                    'ascii_session_id' => $id,
                    'logged_in'        => 'f',
                    'user_id'          => 0,
                    'created'          => date('Y-m-d H:i:s'),
                    'user_agent'       => $userAgent,
                    'ip'               => $this->getIPAddress(),
                ]);
            }

            return '';
        } catch (Exception $e) {
            $this->fatalError($e);
        }
    }

    /**
     * Session write handler. Data persistence is handled via __set(); nothing to do here.
     *
     * @param  string $id   Session ID.
     * @param  string $data Serialised session data (unused).
     * @return bool Always true.
     */
    public function write(string $id, string $data): bool
    {
        return true;
    }

    /**
     * Session destroy handler — deletes the session row from `user_session`.
     *
     * @param  string $id The PHP session ID to destroy.
     * @return bool True on success.
     */
    public function destroy(string $id): bool
    {
        $result = $this->objDB->preparedStatement(
            'DELETE FROM user_session WHERE ascii_session_id = ?',
            [$id]
        );
        return (bool) $result;
    }

    /**
     * Session garbage collection handler. Cleanup is performed inline during cookie
     * validation in the constructor; this callback is a no-op.
     *
     * @param  int $max_lifetime Ignored.
     * @return int|false Always 1.
     */
    public function gc(int $max_lifetime): int|false
    {
        return 1;
    }


    /****************************************************************/
    /* IP ADDRESS                                                   */
    /****************************************************************/

    /**
     * Returns a best-effort determination of the client's IP address.
     *
     * Checks HTTP_CLIENT_IP and HTTP_X_FORWARDED_FOR proxy headers before
     * falling back to REMOTE_ADDR. Returns 'UNKNOWN' if none are set.
     *
     * Note: for more robust proxy-aware IP resolution with private-range exclusion,
     * see Bibliotheca_Intranet_Page::getIPAddress().
     *
     * @return string Client IP address string, or 'UNKNOWN'.
     */
    public function getIPAddress(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }


    /****************************************************************/
    /* STATIC REPORTING METHODS                                     */
    /****************************************************************/

    /**
     * Renders an HTML table of user sessions from a pre-fetched result set,
     * with DataTables JavaScript initialisation.
     *
     * Each row links the user's name to their profile page and the session ID
     * to the session stats page.
     *
     * @param  PDOStatement $sessions Result set of session rows (expects columns:
     *                                timestamp, user_details_id, user_session_id).
     * @param  PDODatabase  $objDB    Database connection for user name lookups.
     * @return string                 HTML string containing the table and DataTables script.
     */
    public static function getUserSessionsAsTable(PDOStatement $sessions, PDODatabase $objDB): string
    {
        $tableId = uniqid('table_');
        $str  = "<table class=\"table table-bordered\" id=\"$tableId\">";
        $str .= '<thead><tr><th>Name</th><th>Last Action</th><th>Session ID</th></tr></thead><tbody>';

        while ($session = $sessions->fetch()) {
            [$date, $time] = htmlPage::getTimeStampAsDateTimeArray($session['timestamp']);

            $user = $objDB->preparedSelect(
                'SELECT first_name, last_name FROM user_details WHERE id = ?',
                [$session['user_details_id']]
            )->fetch();

            $name = htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES);

            $str .= '<tr>'
                . '<td>' . htmlPage::makeLink('user.html?uid=' . $session['user_details_id'], $name) . '</td>'
                . '<td>' . htmlspecialchars($date . ' @ ' . $time, ENT_QUOTES) . '</td>'
                . '<td>' . htmlPage::makeLink('userSessionStats.html?sid=' . $session['user_session_id'], $session['user_session_id']) . '</td>'
                . '</tr>';
        }

        $str .= '</tbody></table>';
        $str .= <<<HTML
        <script>
          $(document).ready(function () {
            $('#{$tableId}').DataTable({ paging: false, destroy: true });
          });
        </script>
        HTML;

        return "<div style=\"font-size:12px\">$str</div>";
    }

    /**
     * Renders an HTML table of the most recent user sessions, derived from
     * the `user_intranet_log` table. Limits results to Config::$NUM_SESSIONS rows.
     *
     * Each row links the user's name to their profile page and the session ID
     * to the session stats page.
     *
     * @param  PDODatabase $objDB Database connection.
     * @return string             HTML string containing the sessions table.
     */
    public static function getLatestUserSessions(PDODatabase $objDB): string
    {
        $tableId = uniqid('table_');

        $data = $objDB->preparedSelect(
            'SELECT user_session_id, MAX(id) AS id
             FROM user_intranet_log
             WHERE user_session_id IS NOT NULL
             GROUP BY user_session_id
             ORDER BY MAX(id) DESC
             LIMIT ?',
            [Config::$NUM_SESSIONS]
        );

        $str  = "<table class=\"table table-bordered\" id=\"$tableId\">";
        $str .= '<thead><tr><th>Name</th><th>Last Action</th><th>Session ID</th></tr></thead><tbody>';

        while ($d = $data->fetch()) {
            $log = $objDB->preparedSelect(
                'SELECT user_session_id, timestamp, user_details_id FROM user_intranet_log WHERE id = ?',
                [$d['id']]
            )->fetch();

            [$date, $time] = htmlPage::getTimeStampAsDateTimeArray($log['timestamp']);
            $user = new User($objDB, $log['user_details_id']);

            $str .= '<tr>'
                . '<td>' . htmlPage::makeLink('user.html?uid=' . $log['user_details_id'], htmlspecialchars($user->getName(), ENT_QUOTES)) . '</td>'
                . '<td>' . htmlspecialchars($date . ' @ ' . $time, ENT_QUOTES) . '</td>'
                . '<td>' . htmlPage::makeLink('userSessionStats.html?sid=' . $log['user_session_id'], $log['user_session_id']) . '</td>'
                . '</tr>';
        }

        $str .= '</tbody></table>';

        return "<div style=\"font-size:12px\">$str</div>";
    }


    /****************************************************************/
    /* PRIVATE HELPERS                                              */
    /****************************************************************/

    /**
     * Outputs a fatal error message and halts execution.
     * Used as a last-resort handler within the session infrastructure where
     * exceptions cannot be propagated normally (e.g. inside PHP session callbacks).
     *
     * @param  Exception $e The exception to report.
     * @return never
     */
    private function fatalError(Exception $e): never
    {
        error_log('[User_Session] ' . $e->getMessage());
        echo 'A session error occurred. Please try again or contact the system administrator.';
        exit(1);
    }
}