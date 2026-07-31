<?php
namespace Biblhertz\Publink\om;

use Biblhertz\Publink\om\BHObject;
use Biblhertz\Publink\om\presentation\UserPresentation;
use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\FileType;
use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\PDODatabase;
use Biblhertz\Publink\utilities\Encryption;
use PDO;
use PDOStatement;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Publink\pages\Bibliotheca_Page;

/**
 * User
 *
 * Represents an authenticated system user in PubLink, corresponding to a row
 * in the `user_details` table. Encapsulates identity, credentials, group
 * membership, file store access, and task permissions.
 *
 * On construction the object eagerly loads:
 * - The user's file IDs into $files via {@see getMyFiles()}.
 * - The user's permitted Task objects into $tasks via {@see getMyTasks()}.
 *
 * Passwords are stored AES-128-CTR encrypted in the database and decrypted
 * transparently on load. ORCID OAuth tokens are stored as raw JSON strings.
 *
 * Presentation is fully delegated to {@see UserPresentation}, keeping display
 * logic out of the domain object.
 *
 * @package  Biblhertz\Publink\om
 * @author   Chris Tomlinson
 * @since    March 2023
 */
class User extends BHObject {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var string Login username (stored in `user_details.name`) */
    private string $user_name = "";

    /**
     * @var int Foreign key referencing `user_group.id`.
     *          Default 1 corresponds to the base user group.
     */
    private int $user_group = 1;

    /** @var string User's email address; doubles as the login name for local accounts */
    private string $email = "";

    /** @var string User's given name */
    private string $firstName = "";

    /** @var string User's family name */
    private string $lastName = "";

    /**
     * @var string Role label resolved from `user_group.name`
     *             (e.g. 'Super User', 'Editor'). Used for display and admin checks.
     */
    private string $role = "";

    /**
     * @var bool Whether this account is currently active.
     *           Maps to `user_details.current` = 't' (true) or other (false).
     */
    private bool $accountEnabled = false;

    /**
     * @var mixed ORCID OAuth token stored as a JSON string, or empty string
     *            if no token has been issued. Typed mixed because null is also
     *            a possible DB value.
     */
    private mixed $token = "";

    /**
     * @var bool True if the user has not yet completed their first login flow.
     *           Maps to `user_details.first_login`.
     */
    private bool $firstLogin = false;

    /**
     * @var string Authentication method for this account.
     *             'local' = password-based; 'orcid' = OAuth via ORCID.
     */
    private string $loginType = "local";

    /**
     * @var string Decrypted plaintext password.
     *             Empty string for ORCID-authenticated accounts which store
     *             no password. Never transmitted or logged.
     */
    private string $password = "";

    /**
     * @var int[] Array of `file.id` values owned by this user.
     *            Populated eagerly in the constructor via {@see getMyFiles()}.
     */
    private array $files = [];

    /**
     * @var Task[] Array of Task objects this user is permitted to execute.
     *             Populated eagerly in the constructor via {@see getMyTasks()}.
     */
    private array $tasks = [];


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs a User by fetching the matching `user_details` row and
     * eagerly loading associated files and tasks.
     *
     * When $id is 0 or not set, a shell User is created with id=0 and no
     * properties populated — used as a safe empty-user sentinel in some callers.
     *
     * Password decryption uses AES-128-CTR via {@see Encryption} with the
     * application key from {@see Bibliotheca_Page::getKey()}. ORCID accounts
     * store an empty password string and are unaffected.
     *
     * @param PDODatabase $objDB Active database connection
     * @param int         $id    Primary key of the `user_details` row to load;
     *                           pass 0 to create a shell/guest User
     */
    public function __construct(PDODatabase $objDB, int $id) {
        $this->tableName = "user_details";
        $this->objDB     = $objDB;

        if ($id > 0) {
            $this->id  = $id;
            $row = $this->fetchItem();

            $this->user_name   = $row["name"];
            $this->user_group  = $row["user_group_id"];
            $this->firstName   = $row['first_name'];
            $this->lastName    = $row['last_name'];
            $this->email       = $row['email'];
            $this->firstLogin  = $row['first_login'];
            $this->loginType   = $row['login_type'];
            $this->token       = $row['token'];

            // Resolve the human-readable role label from the user_group table
            $this->role = $this->objDB->preparedGetOne(
                "select name from user_group where id = ?",
                [$this->user_group]
            );

            // Combine first and last name into the inherited $name field
            if (isset($row['first_name']) && isset($row['last_name'])) {
                $this->name = $row['first_name'] . " " . $row['last_name'];
            }

            // Map the 't' flag to a boolean; any other value means disabled
            if ($row['current'] == 't') $this->accountEnabled = true;

            // Decrypt the stored password for local accounts; skip for ORCID/empty
            if (isset($row['password']) && strcmp($row['password'], "")) {
                $e = new Encryption('aes-128-ctr', Bibliotheca_Page::getKey());
                $this->password = $e->decrypt($row['password']);
            }

            // Eagerly load file IDs and permitted tasks into instance arrays
            $this->getMyFiles();
            $this->getMyTasks();

        } else {
            // Shell / guest user — safe sentinel with no data
            $this->id = 0;
        }
    }


    /****************************************************************/
    /*  ACCESSOR METHODS                                            */
    /****************************************************************/

    /**
     * Returns the primary key of this user.
     *
     * Overrides BHObject::getID() to ensure it is always available even on
     * shell users (returns 0).
     *
     * @return int
     */
    public function getID(): int {
        return $this->id;
    }

    /**
     * Returns the login username stored in `user_details.name`.
     *
     * For local accounts this is the email address; for ORCID accounts it is
     * the ORCID iD string.
     *
     * @return string
     */
    public function getUserName(): string {
        return $this->user_name;
    }

    /**
     * Sets the login username.
     *
     * @param string $n New username value
     */
    public function setUserName(string $n): void {
        $this->user_name = $n;
    }

    /**
     * Returns the user group ID (foreign key to `user_group`).
     *
     * @return int
     *
     */
    public function getUserGroup(): int {  // BUG in original: declared :string, property is int
        return $this->user_group;
    }

    /**
     * Returns the user's email address.
     *
     * @return string
     */
    public function getEmail(): string {
        return $this->email;
    }

    /**
     * Sets the user's email address.
     *
     * @param string $e
     */
    public function setEmail(string $e): void {
        $this->email = $e;
    }

    /**
     * Returns the user's full name (first + last).
     *
     * Delegates to the inherited $name property, which is set in the constructor.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Alias for {@see getName()} — returns the full name.
     *
     * @return string
     */
    public function getFullName(): string {
        return $this->name;
    }

    /**
     * Returns the role label resolved from `user_group.name`.
     *
     * @return string e.g. 'Super User', 'Editor'
     */
    public function getRole(): string {
        return $this->role;
    }

    /**
     * Returns the ORCID OAuth token as a JSON string, or empty string/null
     * if no token has been set.
     *
     * @return mixed JSON string or empty string
     */
    public function getAccessToken(): mixed {
        return $this->token;
    }

    /**
     * Returns whether this user account is currently active.
     *
     * @return bool True if `user_details.current` = 't'
     */
    public function getAccountEnabled(): bool {
        return $this->accountEnabled;
    }

    /**
     * Returns the user's given (first) name.
     *
     * @return string
     */
    public function getFirstName(): string {
        return $this->firstName;
    }

    /**
     * Returns the user's family (last) name.
     *
     * @return string
     */
    public function getLastName(): string {
        return $this->lastName;
    }

    /**
     * Returns true if this user has not yet completed the first-login flow.
     *
     * @return bool
     */
    public function isFirstLogin(): bool {
        return $this->firstLogin;
    }

    /**
     * Returns the authentication method for this account.
     *
     * @return string 'local' for password auth, 'orcid' for ORCID OAuth
     */
    public function getLoginType(): string {
        return $this->loginType;
    }

    /**
     * Returns the decrypted plaintext password for local accounts.
     *
     * Empty string for ORCID-authenticated users who have no stored password.
     * security risk and unused
     *
     * @return string
     */
    /**public function getPassword(): string {
        return $this->password;
    }**/

    /**
     * Fields excluded from reflection-based form and table rendering.
     * Prevents sensitive values (plaintext password, OAuth token) from
     * being sent to the client.
     *
     * @return string[]
     */
    public function getDisallowedFields(): array {
        return ['password', 'token'];
    }

    /**
     * Returns true if this user belongs to the 'Super User' group.
     *
     * @return bool
     */
    public function isAdmin(): bool {
        return strcmp($this->role, "Super User") === 0;
    }

    /**
     * Stores an ORCID OAuth access token for this user if one is not already set.
     *
     * Persists the token to `user_details.token` immediately. The guard
     * prevents an existing token from being overwritten inadvertently.
     *
     * @param string $t JSON-encoded ORCID token string
     */
    public function setAccessToken(string $t): void {
        if (empty($this->token)) {
            $this->token = $t;
            $vals = ['token' => $t];
            $this->objDB->update("user_details", $vals, "id=" . $this->id);
        }
    }

    /**
     * Marks this user's first-login flag as completed in the database.
     *
     * Sets `user_details.first_login` to 0, preventing the first-login
     * onboarding flow from triggering on subsequent sessions.
     */
    public function hasLoggedInForFirstTime(): void {
        $vals = ['first_login' => 0];
        $this->objDB->update("user_details", $vals, "id=" . $this->id);
    }


    /****************************************************************/
    /*  ACCESS-CONTROL METHODS                                      */
    /****************************************************************/

    /**
     * Determines whether a given user may edit this User record.
     *
     * Only users in the administrator group (Config::$ADMINISTRATOR) may
     * edit user records.
     *
     * @param int $id ID of the user requesting edit access
     * @return bool
     *
     */
    public function canEdit(int $id): bool {
        $user = new User($this->objDB, $id);    // BUG: original has args reversed: new User($id, $this->objDB)
        return $user->getUserGroup() === Config::$ADMINISTRATOR;
    }

    /**
     * Determines whether a given user may create new User records.
     *
     * Restricted to administrator-group users only.
     *
     * @param int $id ID of the user requesting create access
     * @return bool
     *
     */
    public function canCreate(int $id): bool {
        $user = new User($this->objDB, $id);    // BUG: original has args reversed
        return $user->getUserGroup() === Config::$ADMINISTRATOR;
    }

    /**
     * User deletion is always forbidden through the object model.
     *
     * User records should be deactivated (setting current = 'f') rather than
     * hard-deleted to preserve referential integrity with jobs, files, and logs.
     *
     * @param int $id User ID (unused)
     * @return bool   Always false
     */
    public function canDelete(int $id): bool {
        return false;
    }

    /**
     * User profiles are always visible within the application.
     *
     * @param int $id User ID (unused)
     * @return bool   Always true
     */
    public function canView(int $id): bool {
        return true;
    }


    /****************************************************************/
    /*  FILE STORE METHODS                                          */
    /****************************************************************/

    /**
     * Returns the absolute filesystem path to this user's file store directory.
     *
     * The path follows the pattern: <FILE_STORE_PATH>/user/<id>/
     * The directory is not created by this method — use {@see getMyFileStoreDirectory()}
     * when creation is also required.
     *
     * @return string Absolute directory path with trailing separator
     */
    public function getMyFileStoreDirectoryPath(): string {
        return Config::$FILE_STORE_PATH . DIRECTORY_SEPARATOR . "user" . DIRECTORY_SEPARATOR . $this->id . DIRECTORY_SEPARATOR;
    }

    /**
     * Returns the absolute path to this user's file store, creating it if absent.
     *
     * Creates the full directory tree (including intermediate directories) with
     * permissions 0755 if it does not yet exist. Safe to call multiple times.
     *
     * @return string Absolute directory path with trailing separator
     */
    public function getMyFileStoreDirectory(): string {
        if (!is_dir($this->getMyFileStoreDirectoryPath())) {
            mkdir($this->getMyFileStoreDirectoryPath(), 0755, true);
        }
        return $this->getMyFileStoreDirectoryPath();
    }

    /**
     * Populates $this->files with the IDs of all non-log files owned by this user.
     *
     * Called eagerly in the constructor. Only file IDs are stored (not full
     * File objects) to keep construction lightweight.
     */
    public function getMyFiles(): void {
        $files = $this->getMyFilesAsResultSet();
        while ($file = $files->fetch()) {
            array_push($this->files, $file['id']);
        }
    }

    /**
     * Returns a result set of all non-log files owned by this user.
     *
     * The query JOINs `file` and `file_type` to include extension and icon
     * metadata, and excludes files of type 'log' which are retrieved
     * separately via {@see getMyLogFilesAsResultSet()}.
     *
     * @return PDOStatement Rows from `file` JOIN `file_type`
     */
    public function getMyFilesAsResultSet(): PDOStatement {
        return $this->objDB->preparedStatement(
            "select file.id, file.file_type_id, file.thumbnail_path, file.user_details_id,
                    file.name, file.size, file.type, file.timestamp, file.path,
                    file_type.name as file_extension, file_type.thumbnail as icon
               from file, file_type
              where user_details_id = ?
                and file_type_id = file_type.id
                and file_type_id <> (select id from file_type where name = ?)",
            [$this->id, "log"]
        );
    }

    /**
     * Returns a result set of all log files owned by this user.
     *
     * Log files are generated by background tasks and separated from the main
     * file store for display purposes.
     *
     * @return PDOStatement Rows from `file` JOIN `file_type` where type = 'log'
     */
    public function getMyLogFilesAsResultSet(): PDOStatement {
        return $this->objDB->preparedStatement(
            "select file.id, file.file_type_id, file.thumbnail_path, file.user_details_id,
                    file.name, file.size, file.type, file.timestamp, file.path,
                    file_type.name as file_extension, file_type.thumbnail as icon
               from file, file_type
              where user_details_id = ?
                and file_type_id = file_type.id
                and file_type_id = (select id from file_type where name = ?)",
            [$this->id, "log"]
        );
    }

    /**
     * Returns a result set of JATS XML files owned by this user, with optional exclusions.
     *
     * Used by tasks that require a JATS file as input. The $exclude array
     * allows specific file IDs to be omitted (e.g. files already bound to a
     * serialized object). IDs are interpolated directly into the SQL IN clause
     * — they should always come from internal DB queries, not user input.
     *
     * @param int[]|null $exclude Array of `file.id` values to exclude, or null for none
     * @return PDOStatement        Rows from `file` JOIN `file_type` where type = 'JATS xml'
     */
    public function getMyJATSFilesAsResultSet(array $exclude = null): PDOStatement {
        $exclusionClause = "";
        if (isset($exclude) && is_array($exclude)) {
            // Build an IN (...) clause; IDs are integers from internal queries, not user input
            $exclusionClause = " and file.id not in (" . implode(",", $exclude) . ")";
        }
        return $this->objDB->preparedStatement(
            "select file.id, file.file_type_id, file.thumbnail_path, file.user_details_id,
                    file.name, file.size, file.type, file.timestamp, file.path,
                    file_type.name as file_extension, file_type.thumbnail as icon
               from file, file_type
              where user_details_id = ? $exclusionClause
                and file_type_id = file_type.id
                and file_type_id = (select id from file_type where name = ?)",
            [$this->id, "JATS xml"]
        );
    }

    /**
     * Returns a result set of all serialized objects owned by this user.
     *
     * Covers all object types (Articles, ReferenceCollections, etc.).
     * Use the typed variants below for type-specific queries.
     *
     * @return PDOStatement Rows from `serialized_object`
     */
    public function getMyObjectsAsResultSet(): PDOStatement {
        return $this->objDB->preparedStatement(
            "select * from serialized_object where user_details_id = ?",
            [$this->id]
        );
    }

    /**
     * Returns a result set of Article-type serialized objects owned by this user.
     *
     * @return PDOStatement Rows from `serialized_object` where type = 'Article'
     */
    public function getMyArticlesAsResultSet(): PDOStatement {
        return $this->objDB->preparedStatement(
            "select * from serialized_object where user_details_id = ? and type = ?",
            [$this->id, 'Article']
        );
    }

    /**
     * Returns a result set of ReferenceCollection-type serialized objects owned by this user.
     *
     * @return PDOStatement Rows from `serialized_object` where type = 'Reference Collection'
     */
    public function getMyReferenceCollectionsAsResultSet(): PDOStatement {
        return $this->objDB->preparedStatement(
            "select * from serialized_object where user_details_id = ? and type = ?",
            [$this->id, 'Reference Collection']
        );
    }

    /**
     * Returns a result set of image files owned by this user.
     *
     * When $jp2 is false (the default), JPEG2000 (jp2) files are excluded —
     * they are handled separately due to browser rendering limitations.
     * Pass true to include all image types including jp2.
     *
     * @param bool $jp2 True to include jp2 files; false (default) to exclude them
     * @return PDOStatement Rows from `file` JOIN `file_type` where file_type.type = 'image'
     */
    public function getMyImageFilesAsResultSet(bool $jp2 = false): PDOStatement {
        if (!$jp2) {
            // Exclude jp2 — typically served via IIIF rather than direct download
            return $this->objDB->preparedStatement(
                "select file.id, file.file_type_id, file.thumbnail_path, file.user_details_id,
                        file.name, file.size, file.type, file.timestamp, file.path,
                        file_type.name as file_extension, file_type.thumbnail as icon
                   from file, file_type
                  where user_details_id = ?
                    and file_type_id = file_type.id
                    and file_type.type = ?
                    and file_type.name <> ?",
                [$this->id, 'image', 'jp2']
            );
        }

        return $this->objDB->preparedStatement(
            "select file.id, file.file_type_id, file.thumbnail_path, file.user_details_id,
                    file.name, file.size, file.type, file.timestamp, file.path,
                    file_type.name as file_extension, file_type.thumbnail as icon
               from file, file_type
              where user_details_id = ?
                and file_type_id = file_type.id
                and file_type.type = ?",
            [$this->id, 'image']
        );
    }

    /**
     * Returns a result set of JPEG2000 (jp2) image files owned by this user.
     *
     * jp2 files are used for IIIF image delivery and are queried separately
     * from other image types.
     *
     * @return PDOStatement Rows from `file` JOIN `file_type` where file_type.name = 'jp2'
     */
    public function getMyJp2FilesAsResultSet(): PDOStatement {
        return $this->objDB->preparedStatement(
            "select file.id, file.file_type_id, file.thumbnail_path, file.user_details_id,
                    file.name, file.size, file.type, file.timestamp, file.path,
                    file_type.name as file_extension, file_type.thumbnail as icon
               from file, file_type
              where user_details_id = ?
                and file_type_id = file_type.id
                and file_type.type = ?
                and file_type.name = ?",
            [$this->id, 'image', 'jp2']
        );
    }

    /**
     * Returns a result set of files owned by this user filtered to a specific set of file types.
     *
     * Builds a dynamic OR clause for each provided FileType. If $fileTypes is
     * empty the method returns false immediately to avoid a malformed query.
     *
     * @param FileType[] $fileTypes Array of FileType objects defining the permitted types
     * @return PDOStatement|false   Result set on success, false if $fileTypes is empty
     */
    public function getMyFilesByFileTypesAsResultSet(array $fileTypes): mixed {
        if (empty($fileTypes)) return false;

        // Build a parameterised OR clause: (file_type_id = ? or file_type_id = ? ...)
        $conditions = [];
        $vals       = [$this->id];
        foreach ($fileTypes as $type) {
            $conditions[] = "file_type_id = ?";
            $vals[]       = $type->getID();
        }
        $typeClause = "(" . implode(" or ", $conditions) . ")";

        $sql = "select file.id, file.user_details_id, file.file_type_id, file.thumbnail_path,
                       file.name, file.size, file.type, file.timestamp, file.path,
                       file_type.name as file_extension, file_type.thumbnail as icon
                  from file, file_type
                 where file.user_details_id = ?
                   and file_type_id = file_type.id
                   and $typeClause";

        $files = $this->objDB->preparedStatement($sql, $vals);
        if ($this->objDB->numRows() == 0) return false;
        return $files;
    }

    /**
     * Checks whether a given filename already exists on the filesystem.
     *
     * @param string $fileName Absolute path to check
     * @return bool            True if the file exists
     */
    public function isUniqueFileName(string $fileName): bool {
        return !file_exists($fileName);  
    }

    /**
     * Generates a unique filename by appending a uniqid() suffix until no
     * collision exists on the filesystem.
     *
     * Preserves the original directory and extension. Loops until a non-existent
     * path is found, adding a new suffix on each iteration if needed.
     *
     * @param string $fileName Starting absolute path (may already exist)
     * @return string          Collision-free absolute path
     */
    public function getUniqueFileName(string $fileName): string {
        while (file_exists($fileName)) {
            $parts    = pathinfo($fileName);
            $fileName = $parts['dirname'] . DIRECTORY_SEPARATOR
                      . $parts['filename'] . "_" . uniqid()
                      . "." . $parts['extension'];
        }
        return $fileName;
    }

    /**
     * Deletes all files and serialized objects owned by this user.
     *
     * Iterates over objects, regular files, and log files in sequence, calling
     * the appropriate delete method on each. Deletion is subject to the
     * individual canDelete() checks within SerializedObject and File.
     *
     * !! This operation is irreversible. Use with extreme caution.
     *
     */
    public function deleteAllFilesAndObjects(): void {
        $objects = $this->getMyObjectsAsResultSet();
        $files   = $this->getMyFilesAsResultSet();
        $logs    = $this->getMyLogFilesAsResultSet();

		$this->objDB->startTransaction();
        // Delete serialized objects first to release file_id foreign key constraints
        while ($object = $objects->fetch()) {
            $so = new SerializedObject($this->getObjDB(), $object['id']);
            $so->deleteObject($this->getID());
        }

        // Delete regular files
        while ($f = $files->fetch()) {
            $file = new File($this->getObjDB(), $f['id']);
            $file->deleteFile($this->getID());
        }

        // Delete log files
        while ($f = $logs->fetch()) {
            $file = new File($this->getObjDB(), $f['id']);
            $file->deleteFile($this->getID());
        }
		$this->objDB->commit();
    }


    /****************************************************************/
    /*  TASK METHODS                                                */
    /****************************************************************/

    /**
     * Populates $this->tasks with Task objects this user is permitted to execute.
     *
     * Called eagerly in the constructor. Only public tasks (task.public = 1)
     * that have a corresponding row in `user_details_task` are included.
     */
    private function getMyTasks(): void {
        $tasks = $this->getMyTasksAsResultSet();
        while ($task = $tasks->fetch()) {
            array_push($this->tasks, new Task($this->getObjDB(), $task['id']));
        }
    }

    /**
     * Returns a result set of tasks this user is authorised to execute.
     *
     * Only public tasks (task.public = 1) with a matching entry in
     * `user_details_task` are returned.
     *
     * @return PDOStatement Rows from `task` JOIN `user_details_task`
     */
    private function getMyTasksAsResultSet(): PDOStatement {
        return $this->objDB->preparedStatement(
            "select distinct task.id from task, user_details_task
              where public = 1
                and user_details_id = ?
                and task_id = task.id",
            [$this->id]
        );
    }

    /**
     * Returns the array of Task objects this user is permitted to execute.
     *
     * @return Task[]
     */
    public function getTasks(): array {
        return $this->tasks;
    }


    /****************************************************************/
    /*  STATIC METHODS                                              */
    /****************************************************************/

    /**
     * Checks whether an active user account exists for the given username.
     *
     * Queries `user_details` for a row with a matching name and a current
     * status of 't' (active) or 'c' (candidate/pending). Returns the user's
     * database ID on success, or 0 if not found.
     *
     * A new PDODatabase connection is created if $objDB is not provided,
     * allowing this method to be called before a connection is established
     * in the session context.
     *
     * @param string           $username Username to look up
     * @param PDODatabase|null $objDB    Optional database connection; created if null
     * @return int                       User ID if found and active, 0 otherwise
     */
    public static function userExists(string $username, PDODatabase $objDB = null): int {
        $sql = "select id FROM user_details WHERE name = ? AND (current = 't' OR current = 'c')";
        if (!isset($objDB)) $objDB = new PDODatabase();
        $result = $objDB->preparedGetOne($sql, [$username]);
        return ($result > 0) ? $result : 0;
    }

    /**
     * Alias for {@see userExists()} with identical behaviour.
     *
     * @param string           $username Username to look up
     * @param PDODatabase|null $objDB    Optional database connection; created if null
     * @return int                       User ID if found and active, 0 otherwise
     *
     */
    public static function userNameExists(string $username, PDODatabase $objDB = null): int {
        return self::userExists($username,$objDB);
    }


    /****************************************************************/
    /*  MAGIC METHODS                                               */
    /****************************************************************/

    /**
     * Returns a concise debug string identifying this user.
     *
     * @return string e.g. "User ID :: 5 : User Name :: jsmith"
     */
    public function __toString(): string {
        return "User ID :: " . $this->getID() . " : User Name :: " . $this->user_name;
    }


    /****************************************************************/
    /*  PRESENTATION METHODS                                        */
    /****************************************************************/

    /**
     * Returns this user rendered as an HTML table.
     *
     * Delegates all presentation logic to {@see UserPresentation}.
     *
     * @return string Rendered HTML table string
     */
    public function getAsTable(): string {
        $presentation = new UserPresentation($this);
        return $presentation->getAsTable();
    }

    /**
     * Returns this user rendered as an HTML form targeting $address.
     *
     * Delegates to {@see UserPresentation::getAsForm()}.
     *
     * @param string $address Form action URL or controller address
     * @return string         Rendered HTML form string
     */
    public function getAsForm(string $address): string {
        $presentation = new UserPresentation($this);
        return $presentation->getAsForm($address);
    }


    /****************************************************************/
    /*  DATABASE UPDATE METHODS                                     */
    /****************************************************************/

    /**
     * Replaces this user's task permissions with a new set.
     *
     * Deletes all existing public-task permissions for this user from
     * `user_details_task`, then inserts a fresh row for each task ID in $tasks.
     * Only public tasks are affected; private tasks are not touched.
     *
     * @param int[] $tasks Array of `task.id` values to grant to this user
     */
    public function updateTasks(array $tasks): void {
        $vals = ['user_details_id' => $this->id];

        // Remove only public-task permissions to avoid touching private task grants
        $this->getObjDB()->preparedSelect(
            "delete from user_details_task where user_details_id = ? and task_id in (select id from task where public = 1)",
            [$this->id]
        );

        foreach ($tasks as $task) {
            $vals['task_id'] = $task;
            $this->getObjDB()->insert("user_details_task", $vals);
        }
    }

    /**
     * Persists updated user details to the database, or creates a new user record.
     *
     * Distinguishes between insert (id == 0) and update (id > 0) by checking
     * $this->id. On insert, all public tasks are automatically granted to the
     * new user. Passwords are AES-128-CTR encrypted before storage; ORCID
     * accounts store an empty password string instead.
     *
     * @param array $values Associative array of user fields from a form submission.
     *                      Expected keys: first_name, last_name, email, user_name,
     *                      password1, login_type, current, user_group_id.
     * @return int          The id of the created or updated user record
     */
    public function updateUser(array $values): int {
        $vals = [];
        $vals['first_name']    = $values['first_name'];
        $vals['last_name']     = $values['last_name'];
        $vals['email']         = $values['email'];
        $vals['login_type']    = $values['login_type'];
        $vals['current']       = $values['current'];
        $vals['user_group_id'] = $values['user_group_id'];

        if (!strcmp($values['login_type'], "orcid")) {
            // ORCID accounts use the ORCID iD as the username; no password stored
            $vals['name']     = $values['user_name'];
            $vals['password'] = "";
        } else {
            // Local accounts use the email as the username; password is encrypted
            $vals['name']     = $values['email'];
            $e = new Encryption('aes-128-ctr', Bibliotheca_Page::getKey());
            $vals['password'] = $e->encrypt($values['password1']);
        }

        if ($this->id == 0) {
            // New user — insert and grant permissions for all existing public tasks
            $id = $this->getObjDB()->insert("user_details", $vals);

            $taskPermission = ['user_details_id' => $id];
            $tasks = $this->getObjDB()->select("select * from task");
            while ($task = $tasks->fetch()) {
                $taskPermission['task_id'] = $task['id'];
                $this->getObjDB()->insert("user_details_task", $taskPermission);
            }
            return $id;

        } else {
            // Existing user — update in place
            $this->getObjDB()->update("user_details", $vals, "id=" . $this->id);
            return $this->id;
        }
    }


    /**
     * Persists updated user details to the database, run from initialisation.
     *
     * @param array $values Associative array of user fields from init.sh script.
     *                      Expected keys: user_name password1
     * @return int          The id of the created or updated user record
     */
    public function updateCredentials(array $values): int {
        $vals = [];
        $vals['name']    = $values['username'];
        $vals['email']   = $values['username'];
            
        // Local accounts use the email as the username; password is encrypted
        $e = new Encryption('aes-128-ctr', Bibliotheca_Page::getKey());
        $vals['password'] = $e->encrypt($values['password']);

        // Existing user — update in place
        $this->getObjDB()->update("user_details", $vals, "id=" . $this->id);
        return $this->id;
    }
}
?>