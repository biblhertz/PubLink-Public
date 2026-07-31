<?php
namespace Biblhertz\Publink\utilities;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDODatabase
 *
 * A lightweight PDO abstraction layer providing parameterised query helpers,
 * dynamic INSERT/UPDATE builders, and transaction management for the PubLink
 * application.
 *
 * All query methods use prepared statements (except {@see select()} and
 * {@see getOne()}, which accept trusted internal SQL strings) and store the
 * last row count in $rowCount for callers that need it via {@see numRows()}.
 *
 * Connection configuration is hardcoded as private defaults but the static
 * $host can be overridden at bootstrap time via {@see setHost()} to support
 * different deployment environments (e.g. Docker service names vs localhost).
 *
 * Debug output is controlled by the $DEBUG and $INSERT_DEBUG flags, which
 * write SQL statements and bound values to the PHP error log when enabled.
 *
 * Transaction usage pattern:
 * ```php
 * $db->startTransaction();
 * try {
 *     $db->insert(...);
 *     $db->update(...);
 *     $db->commit();
 * } catch (Exception $e) {
 *     $db->rollBack();
 *     throw $e;
 * }
 * ```
 *
 * @package  Biblhertz\Publink\utilities
 * @author   Chris Tomlinson
 * @since    12th March 2019
 */
class PDODatabase {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var PDO The underlying PDO connection instance */
    private PDO $db;

    /**
     * @var string MySQL username. Static so it can be set once at bootstrap via
     *             {@see setUser()} before the first PDODatabase instance is created.
     */
    private static string $user = "";

    /**
     * @var string MySQL password. Static so it can be set once at bootstrap via
     *             {@see setPassword()} before the first PDODatabase instance is created.
     */
    private static string $password = "";

    /**
     * @var string MySQL hostname or Docker service name.
     *             Static so it can be set once at bootstrap via {@see setHost()}
     *             before the first PDODatabase instance is created.
     */
    private static string $host = "mysql";

    /**
     * @var string Target database/schema name. Static so it can be set once at bootstrap
     *             via {@see setDatabaseName()} before the first PDODatabase instance is created.
     */
    private static string $database_name = "";

    /**
     * @var bool When true, all SQL statements and their bound values are
     *           written to the PHP error log. Useful during development;
     *           should be false in production.
     */
    private bool $DEBUG = false;

    /**
     * @var bool When true, INSERT statements and their values are additionally
     *           written to the error log independently of $DEBUG.
     */
    private bool $INSERT_DEBUG = false;

    /**
     * @var int Row count from the most recently executed statement.
     *          Updated after every query; retrieved via {@see numRows()}.
     */
    private int $rowCount;

    /**
     * @var PDOStatement|null The most recently prepared/executed statement.
     *                        Retained so {@see rollBack()} can close the cursor
     *                        before rolling back.
     */
    private $statement;


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Opens a PDO connection to the configured MySQL database.
     *
     * Uses utf8mb4 charset, ERRMODE_EXCEPTION error handling, associative
     * fetch mode, and disables emulated prepares to ensure real server-side
     * prepared statements are used.
     *
     * @throws PDOException If the connection cannot be established
     */
    public function __construct() {
        $dsn     = "mysql:host=" . PDODatabase::$host . ";dbname=" . PDODatabase::$database_name . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,  // Use real prepared statements
        ];

        $this->db = new PDO($dsn, PDODatabase::$user, PDODatabase::$password, $options);
    }


    /****************************************************************/
    /*  CONNECTION METHODS                                          */
    /****************************************************************/

    /**
     * Returns the raw PDO connection instance.
     *
     * Exposed for callers that need direct PDO access (e.g. driver-specific
     * features not covered by this abstraction layer).
     *
     * @return PDO
     */
    public function getConnection(): PDO {
        return $this->db;
    }

    /**
     * Returns a safely quoted string literal for use in unprepared SQL.
     *
     * Delegates to PDO::quote(). Prefer parameterised methods over manual
     * quoting wherever possible.
     *
     * @param string $str The value to quote
     * @return string     Quoted and escaped string including surrounding quotes
     */
    public function quote(string $str): string {
        return $this->db->quote($str);
    }

    /**
     * Returns the row count from the most recently executed statement.
     *
     * For SELECT statements this reflects the number of rows returned by
     * PDOStatement::rowCount(), which may not be reliable for all drivers.
     * For INSERT/UPDATE/DELETE it reflects rows affected.
     *
     * @return int
     */
    public function numRows(): int {
        return $this->rowCount;
    }

    /**
     * Overrides the MySQL host used for new connections.
     *
     * Must be called before the first PDODatabase instance is constructed.
     * Allows the host to be set from a config file or environment variable
     * to support different deployment targets (e.g. 'mysql' in Docker,
     * 'localhost' for direct installs).
     *
     * @param string $host Hostname or Docker service name
     */
    public static function setHost(string $host): void {
        PDODatabase::$host = $host;
    }

    /**
     * Overrides the MySQL username used for new connections.
     * Must be called before the first PDODatabase instance is constructed.
     *
     * @param string $user MySQL username
     */
    public static function setUser(string $user): void {
        PDODatabase::$user = $user;
    }

    /**
     * Overrides the MySQL password used for new connections.
     * Must be called before the first PDODatabase instance is constructed.
     *
     * @param string $password MySQL password
     */
    public static function setPassword(string $password): void {
        PDODatabase::$password = $password;
    }

    /**
     * Overrides the target database/schema name used for new connections.
     * Must be called before the first PDODatabase instance is constructed.
     *
     * @param string $name Database/schema name
     */
    public static function setDatabaseName(string $name): void {
        PDODatabase::$database_name = $name;
    }


    /****************************************************************/
    /*  PREPARED STATEMENT METHODS                                  */
    /****************************************************************/

    /**
     * Executes a parameterised SQL statement and returns the PDOStatement.
     *
     * The core prepared-statement executor used by most other methods.
     * Uses a forward-only cursor for efficiency. Updates $rowCount after
     * execution. When $DEBUG is enabled, logs the SQL and bound values.
     *
     * Suitable for any SQL verb (SELECT, INSERT, UPDATE, DELETE). For SELECT
     * use {@see preparedSelect()} as a semantic alias; for scalar results use
     * {@see preparedGetOne()}.
     *
     * @param string $sql  Parameterised SQL string with ? placeholders
     * @param array  $vals Ordered array of values to bind to the placeholders
     * @return PDOStatement The executed statement (ready for fetch calls)
     * @throws \Exception   On query execution failure
     */
    public function preparedStatement(string $sql, array $vals): PDOStatement {
        if ($this->DEBUG) error_log($sql . " :: " . implode(" ", $vals));

        $this->statement = $this->db->prepare($sql, [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
        $this->statement->execute($vals);
        $this->rowCount  = $this->statement->rowCount();

        return $this->statement;
    }

    /**
     * Semantic alias for {@see preparedStatement()} for SELECT queries.
     *
     * Identical in behaviour; the name distinction signals read-only intent
     * to the caller. Returns the PDOStatement for subsequent fetch() calls.
     *
     * @param string $sql  Parameterised SELECT statement
     * @param array  $vals Bound parameter values
     * @return PDOStatement
     */
    public function preparedSelect(string $sql, array $vals): PDOStatement {
        return $this->preparedStatement($sql, $vals);
    }

    /**
     * Executes a parameterised query and returns the first column of the first row.
     *
     * Designed for queries that return a single scalar value (e.g. COUNT(*),
     * a single ID, or a single field lookup). Updates $rowCount after execution.
     *
     * @param string $sql  Parameterised SQL string
     * @param array  $vals Bound parameter values
     * @return mixed       Scalar value from the first column of the first row,
     *                     or false if no rows were returned
     * @throws \Exception  On query execution failure
     */
    public function preparedGetOne(string $sql, array $vals): mixed {
        if ($this->DEBUG) error_log($sql . " :: " . implode(" ", $vals));

        $this->statement = $this->db->prepare($sql, [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
        $this->statement->execute($vals);
        $this->rowCount  = $this->statement->rowCount();

        return $this->statement->fetchColumn();
    }

    /**
     * Executes a parameterised query and returns the first row as an associative array.
     *
     * Useful when exactly one row is expected (e.g. fetching a record by primary
     * key). Returns false if no rows match.
     *
     * @param string $sql  Parameterised SQL string
     * @param array  $vals Bound parameter values
     * @return array|false Associative array of the first row, or false if empty
     * @throws \Exception  On query execution failure
     */
    public function preparedGetRow(string $sql, array $vals): array|false {
        if ($this->DEBUG) error_log($sql . " :: " . implode(" ", $vals));

        $this->statement = $this->db->prepare($sql, [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
        $this->statement->execute($vals);
        $this->rowCount  = $this->statement->rowCount();

        return $this->statement->fetch();
    }


    /****************************************************************/
    /*  UNPREPARED STATEMENT METHODS                                */
    /****************************************************************/

    /**
     * Executes a raw (unprepared) SQL SELECT query.
     *
     * Intended for trusted, internally constructed SQL strings — typically
     * schema inspection queries such as SHOW COLUMNS that cannot be
     * parameterised. Never pass user-supplied values into $sql.
     *
     * @param string $sql Raw SQL SELECT string (no ? placeholders)
     * @return PDOStatement The executed statement
     * @throws PDOException On query failure
     */
    public function select(string $sql): PDOStatement {
        $sql = trim($sql);
        if ($this->DEBUG) error_log($sql);

        $result         = $this->db->query($sql);
        $this->rowCount = $result->rowCount();

        return $result;
    }

    /**
     * Executes a raw SQL query and returns the first column of the first row.
     *
     * Like {@see select()}, intended for trusted internal SQL only. Useful for
     * simple aggregate lookups (e.g. MAX(id)) where parameterisation is not needed.
     *
     * @param string $sql Raw SQL string returning a single scalar value
     * @return mixed      First column of the first row, or false if no rows returned
     * @throws PDOException On query failure
     */
    public function getOne(string $sql): mixed {
        $sql = trim($sql);
        if ($this->DEBUG) error_log($sql);

        $q              = $this->db->query($sql);
        $this->rowCount = $q->rowCount();

        return $q->fetchColumn();
    }


    /****************************************************************/
    /*  IDENTIFIER QUOTING                                         */
    /****************************************************************/

    /**
     * Wraps a table or column identifier in backticks and escapes any
     * backticks within the name, preventing SQL injection via identifier
     * interpolation.
     *
     * @param  string $name Raw identifier (table or column name)
     * @return string       Safely backtick-quoted identifier
     */
    private function quoteIdentifier(string $name): string {
        return '`' . str_replace('`', '``', $name) . '`';
    }


    /****************************************************************/
    /*  INSERT / UPDATE METHODS                                     */
    /****************************************************************/

    /**
     * Executes a dynamic parameterised UPDATE statement.
     *
     * Builds the SET clause from the $values array keys and an optional WHERE
     * clause from $where. The WHERE string is interpolated directly into the
     * SQL — it should always be an internally constructed expression, never
     * user-supplied input.
     *
     * Returns 0 immediately if $values is empty to avoid a malformed query.
     *
     * @param string     $table      Table name to update
     * @param array      $values     Associative array of column => value pairs to set
     * @param mixed|null $where      Optional WHERE clause with ? placeholders (e.g. "id = ?"),
     *                               or null to update all rows (use with caution)
     * @param array      $whereVals  Ordered values to bind to the WHERE clause placeholders
     * @return int                   Number of rows affected
     * @throws PDOException          On query failure
     */
    public function update(string $table, array $values, mixed $where = null, array $whereVals = []): int {
        if (empty($values)) {
            $this->rowCount = 0;
            return 0;
        }

        // Build "col1 = ?, col2 = ?, ..." and collect values in order
        $setClauses = [];
        $vals       = [];
        foreach ($values as $key => $value) {
            $setClauses[] = $this->quoteIdentifier($key) . " = ?";
            $vals[]       = $value;
        }

        $sql = "UPDATE " . $this->quoteIdentifier($table) . " SET " . implode(", ", $setClauses);
        if (isset($where)) {
            $sql   .= " WHERE $where";
            $vals   = array_merge($vals, $whereVals);
        }

        if ($this->DEBUG) error_log($sql . " :: " . implode(" ", $vals));

        $this->statement = $this->db->prepare($sql);
        $this->statement->execute($vals);
        $this->rowCount  = $this->statement->rowCount();

        return $this->rowCount;
    }

    /**
     * Executes a dynamic parameterised INSERT statement.
     *
     * Builds column list and VALUES placeholder list from the $values array
     * keys. Returns 0 immediately if $values is empty. On success returns the
     * auto-incremented primary key of the inserted row.
     *
     * @param string $table  Table name to insert into
     * @param array  $values Associative array of column => value pairs
     * @return int           Last insert ID on success, 0 if $values was empty
     * @throws PDOException  On query failure
     */
    public function insert(string $table, array $values): int {
        if (empty($values)) {
            $this->rowCount = 0;
            return 0;
        }

        // Build column list and matching ? placeholder list
        $cols         = [];
        $placeholders = [];
        $vals         = [];
        foreach ($values as $key => $value) {
            $cols[]         = $this->quoteIdentifier($key);
            $placeholders[] = "?";
            $vals[]         = $value;
        }

        $sql = "INSERT INTO " . $this->quoteIdentifier($table) . " (" . implode(",", $cols) . ") VALUES (" . implode(",", $placeholders) . ")";

        if ($this->DEBUG || $this->INSERT_DEBUG)
            error_log($sql . "\n" . implode(" ", $values));

        $this->statement = $this->db->prepare($sql);
        $this->statement->execute($vals);
        $this->rowCount  = $this->statement->rowCount();

        return (int) $this->db->lastInsertId();
    }


    /****************************************************************/
    /*  TRANSACTION METHODS                                         */
    /****************************************************************/

    /**
     * Begins a database transaction.
     *
     * Disables auto-commit and calls PDO::beginTransaction() only if a
     * transaction is not already active (guarding against nested calls).
     *
     * Typical usage:
     * ```php
     * $db->startTransaction();
     * try {
     *     $db->insert(...);
     *     $db->update(...);
     *     $db->commit();
     * } catch (Exception $e) {
     *     $db->rollBack();
     *     throw $e;
     * }
     * ```
     *
     * @return bool True on success or if a transaction is already active (no-op)
     * @throws \Exception On failure to begin the transaction
     */
    public function startTransaction(): bool {
        $this->db->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);
        if (!$this->db->inTransaction()) {
            return $this->db->beginTransaction();
        }
        return true;
    }

    /**
     * Commits the current transaction and re-enables auto-commit.
     *
     * Only commits if a transaction is currently active. Always re-enables
     * ATTR_AUTOCOMMIT regardless of whether a commit occurred, to restore
     * normal behaviour for subsequent queries.
     *
     * @return bool Always true on success
     * @throws \Exception On commit failure
     */
    public function commit(): bool {
        if ($this->db->inTransaction()) $this->db->commit();
        $this->db->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
        return true;
    }

    /**
     * Rolls back the current transaction.
     *
     * Closes the cursor on the most recently executed statement first (if any)
     * to release server-side resources before the rollback. Only rolls back if
     * a transaction is currently active.
     *
     * @throws \Exception On rollback failure
     */
    public function rollBack(): void {
        // Close any open cursor before rolling back to avoid lock contention
        if (isset($this->statement)) {
            $this->statement->closeCursor();
        }
        if ($this->db->inTransaction()) $this->db->rollBack();
        $this->db->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
    }
}