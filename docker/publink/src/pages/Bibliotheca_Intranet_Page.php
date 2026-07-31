<?php
/**
 * Bibliotheca_Intranet_Page.php
 *
 * Abstract base class for all authenticated Bibliotheca intranet pages.
 * Extends Bibliotheca_Page to add session management, role-based access control,
 * page impression logging, and client IP resolution.
 *
 * Authentication flow (constructor):
 *  1. A User_Session is instantiated; if a valid session exists the user object is retrieved.
 *  2. The current script name is looked up in the `intranet_scripts` table to determine the
 *     minimum user_group_id required to access it (defaults to 21 if not found).
 *  3. If the user's group is below the required level, an exception is thrown and the request
 *     is halted with an authorisation error.
 *  4. If no session exists and $login is falsy (i.e. this is not the login page itself),
 *     the client is redirected to the site root.
 *
 * Page impression logging:
 *  Every successful page load records a row in `user_intranet_log` capturing the script,
 *  user, session, timestamp, IP address, serialised GET parameters, user-agent, and
 *  current PHP memory usage. Service scripts (paths containing "services") are excluded.
 *
 * @package Biblhertz\Publink\pages
 * @author  Chris Tomlinson
 * @since   March 2023
 */

namespace Biblhertz\Publink\pages;

use Biblhertz\Publink\pages\Bibliotheca_Page;
use Biblhertz\Publink\utilities\User_Session;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\om\User;
use Exception;

abstract class Bibliotheca_Intranet_Page extends Bibliotheca_Page
{

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var User_Session Active session wrapper for the current request. */
    private User_Session $user_Session;

    /**
     * @var string Basename of the PHP script currently being executed (e.g. 'dashboard.php').
     *             Used to look up the required user group in `intranet_scripts`.
     */
    private string $script;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialises the page, validates the user session, enforces role-based access control,
     * and logs the page impression.
     *
     * Pass $login = 1 (or any truthy int) when constructing a login page so that the
     * redirect-if-not-logged-in guard and impression logging are both suppressed.
     *
     * @param  int $login 0 for normal authenticated pages; non-zero for the login page itself.
     * @throws Exception If the current user's group is below the required level for this script.
     */
    public function __construct(int $login = 0)
    {
        parent::__construct();

        $this->user_Session = new User_Session();

        if ($user = $this->user_Session->getUserObject()) {
            // Determine which script is running
            $this->script = basename($_SERVER['PHP_SELF']);

            // Look up the minimum user_group_id required to access this script
            $sql              = 'SELECT user_group_id FROM intranet_scripts WHERE name = ?';
            $restricted_group = $this->getObjDB()->preparedGetOne($sql, [$this->script]);

            // Default to group 21 if the script is not registered or the value is non-numeric
            if (!isset($restricted_group) || !is_numeric($restricted_group) || $restricted_group <= 0) {
                $restricted_group = 21;
            }

            // Reject users whose group level is below the script's requirement
            if ($user->getUserGroup() < $restricted_group) {
                $this->addToLog(1); // Log as a security breach attempt
                $this->handleException(
                    new Exception("You are not authorised to view this page.<br>{$this->script}")
                );
                exit(1);
            }

            // Log normal page impressions (login pages are excluded via $login flag)
            if (!$login) {
                $this->addToLog();
            }

        } elseif (!$login && !$this->user_Session->isLoggedIn()) {
            // No session and not on the login page — redirect to site root
            header('Location: ' . Bibliotheca_Page::getSiteRoot());
            exit;
        }
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Logs the current user out by destroying their session.
     *
     * @return bool True on success, false on failure.
     */
    public function logOut(): bool
    {
        return $this->user_Session->logOut();
    }

    /**
     * Returns whether the current request has an active authenticated session.
     *
     * @return bool True if logged in, false otherwise.
     */
    public function isLoggedIn(): bool
    {
        return $this->user_Session->isLoggedIn();
    }

    /**
     * Returns the User domain object for the currently authenticated user.
     *
     * @return User The authenticated user.
     */
    public function getUser(): User
    {
        if (!isset($this->user_Session)) {
            throw new Exception('Session not initialised — constructor did not complete.');
        }
        $user = $this->user_Session->getUserObject();
        if ($user === null) {
            throw new Exception('No authenticated user in session.');
        }
        return $user;
    }

    /**
     * Returns the intranet root URL (alias of Bibliotheca_Page::getSiteRoot()).
     *
     * @return string The configured site root URL.
     */
    public static function getIntranetRoot(): string
    {
        return Bibliotheca_Page::getSiteRoot();
    }

    /**
     * Returns the raw User_Session instance for the current request.
     * Use this when you need direct access to session-level operations
     * beyond what the convenience methods here expose.
     *
     * @return User_Session The active session wrapper.
     */
    public function getUserSession(): User_Session
    {
        return $this->user_Session;
    }

    /**
     * Returns the database primary key of the currently authenticated user.
     *
     * @return int User ID.
     */
    public function getID(): int
    {
        return $this->getUser()->getID();
    }


    /****************************************************************/
    /* PAGE RENDERING                                               */
    /****************************************************************/

    /**
     * Records a session impression tick and delegates to the parent getPage()
     * if the session is still valid. Redirects to the site root if the session
     * has expired between the constructor check and render time.
     *
     * Subclasses (e.g. Bibliotheca_Content_Page) override this method to
     * provide the actual HTML document; this implementation acts as a
     * session-validity guard around the parent's render.
     *
     * @return string|void The rendered HTML page, or void after a redirect.
     */
    public function getPage(): string
    {
        $this->user_Session->impress();

        if ($this->user_Session->isLoggedIn()) {
            return parent::getPage();
        }

        header('Location: ' . Bibliotheca_Page::getSiteRoot());
        exit;
    }


    /****************************************************************/
    /* PRIVATE HELPERS                                              */
    /****************************************************************/

    /**
     * Writes a page impression record to the `user_intranet_log` table.
     *
     * Records the script path, user ID, session ID, timestamp, client IP,
     * serialised GET parameters, HTTP user-agent string, and current PHP
     * memory usage in MB.
     *
     * Calls to service endpoints (paths containing "/services") are silently
     * skipped to avoid polluting the log with background AJAX polling requests.
     *
     * @param  int $security_breach Pass 1 when logging an unauthorised access attempt; 0 for normal impressions.
     * @return void
     */
    private function addToLog(int $security_breach = 0): void
    {
        $vals = [];
        $vals['script'] = $_SERVER['PHP_SELF'];

        // Exclude AJAX service calls from impression logging
        if (str_contains($vals['script'], 'services')) {
            return;
        }

        $vals['security_breach']  = $security_breach;
        $vals['user_details_id']  = $this->user_Session->getUserObject()->getID();
        $vals['user_session_id']  = $this->user_Session->getSessionIdentifier();
        $vals['timestamp']        = date('Y-m-d H:i:s');
        $vals['ip_address']       = $this->getIPAddress();

        // Serialise GET parameters; normalise encoding and cap at 254 chars
        $rawGet           = serialize($_GET);
        $serialised       = mb_convert_encoding($rawGet, 'UTF-8', mb_detect_encoding($rawGet) ?: 'UTF-8');
        $vals['params']   = substr($serialised, 0, 254);
        if ($vals['params'] === 's:0:"";') {
            $vals['params'] = null;
        }

        $rawAgent         = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $vals['client']   = mb_convert_encoding($rawAgent, 'UTF-8', mb_detect_encoding($rawAgent) ?: 'UTF-8');
        $vals['memory_usage'] = (memory_get_usage(true) / 1024) / 1024;

        $this->getObjDB()->insert('user_intranet_log', $vals);
    }


    /****************************************************************/
    /* NETWORK / IP UTILITY METHODS                                 */
    /****************************************************************/

    /**
     * Attempts to determine the client's real public IP address.
     *
     * Inspects a series of HTTP proxy headers in priority order, validating
     * each candidate via validate_ip() (which rejects private and reserved ranges).
     * Falls back to REMOTE_ADDR if no valid public IP is found in any header.
     *
     * Headers checked (in order):
     *   HTTP_CLIENT_IP → HTTP_X_FORWARDED_FOR (comma-separated list) →
     *   HTTP_X_FORWARDED → HTTP_X_CLUSTER_CLIENT_IP →
     *   HTTP_FORWARDED_FOR → HTTP_FORWARDED → REMOTE_ADDR
     *
     * @return string The best-guess public IP address of the client.
     */
    public static function getIPAddress(): string
    {
        // Shared internet / ISP IP
        if (!empty($_SERVER['HTTP_CLIENT_IP']) && Bibliotheca_Page::validate_ip($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }

        // X-Forwarded-For may contain a comma-separated chain of IPs; use the first valid one
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $ip) {
                if (Bibliotheca_Intranet_Page::validate_ip(trim($ip))) {
                    return trim($ip);
                }
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED']) && Bibliotheca_Page::validate_ip($_SERVER['HTTP_X_FORWARDED'])) {
            return $_SERVER['HTTP_X_FORWARDED'];
        }

        if (!empty($_SERVER['HTTP_X_CLUSTER_CLIENT_IP']) && Bibliotheca_Page::validate_ip($_SERVER['HTTP_X_CLUSTER_CLIENT_IP'])) {
            return $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'];
        }

        if (!empty($_SERVER['HTTP_FORWARDED_FOR']) && Bibliotheca_Page::validate_ip($_SERVER['HTTP_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_FORWARDED_FOR'];
        }

        if (!empty($_SERVER['HTTP_FORWARDED']) && Bibliotheca_Page::validate_ip($_SERVER['HTTP_FORWARDED'])) {
            return $_SERVER['HTTP_FORWARDED'];
        }

        // REMOTE_ADDR is always present but may be a proxy or load balancer address
        return $_SERVER['REMOTE_ADDR'];
    }

    /**
     * Validates that an IP address is syntactically valid (IPv4 or IPv6)
     * and does not fall within a private or reserved network range.
     *
     * Private ranges excluded by PHP's FILTER_FLAG_NO_PRIV_RANGE:
     *   10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, ::1, fc00::/7
     *
     * Reserved ranges excluded by PHP's FILTER_FLAG_NO_RES_RANGE:
     *   0.0.0.0/8, 169.254.0.0/16, 192.0.2.0/24, 224.0.0.0/4, etc.
     *
     * @param  string $ip The IP address string to validate.
     * @return bool True if the address is a valid public IP, false otherwise.
     */
    public static function validate_ip(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}