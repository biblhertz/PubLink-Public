<?php
namespace Biblhertz\Publink\om;

use Biblhertz\Publink\utilities\PDODatabase;

/**
 * BHObject
 *
 * Abstract base class for all PubLink domain objects (publications, files,
 * users, etc.). Provides a common interface for identity, naming, database
 * access, access-control enforcement, and MySQL ENUM rendering.
 *
 * Subclasses must implement the three abstract security methods (canEdit,
 * canDelete, canView) and should set $tableName and $disallowedFields in
 * their constructors before calling any inherited persistence helpers.
 *
 * @package  Biblhertz\Publink\om
 * @author   Chris Tomlinson
 * @since    July 2023
 */
abstract class BHObject {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var int Primary key of this object's row in $tableName */
    protected int $id;

    /** @var string Human-readable name or title for this object */
    protected string $name = "";

    /** @var PDODatabase Active database connection wrapper */
    protected PDODatabase $objDB;

    /**
     * @var string Name of the database table that backs this object.
     *             Must be set by each concrete subclass constructor.
     */
    protected string $tableName = "";

    /**
     * @var array List of column names that should be excluded from generic
     *            read/write operations (e.g. password hashes, internal flags).
     *            Populated by subclasses as needed.
     */
    protected array $disallowedFields = [];


    /****************************************************************/
    /*  INTERFACE / ACCESSOR METHODS                                */
    /****************************************************************/

    /**
     * Returns the primary key of this object.
     *
     * @return int
     */
    public function getID(): int {
        return $this->id;
    }

    /**
     * Sets the primary key of this object.
     *
     * @param int $id
     */
    public function setID(int $id): void {
        $this->id = $id;
    }

    /**
     * Returns the human-readable name or title of this object.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Sets the human-readable name or title of this object.
     *
     * @param string $name
     */
    public function setName(string $name): void {
        $this->name = $name;
    }

    /**
     * Returns the database connection wrapper used by this object.
     *
     * @return PDODatabase
     */
    public function getObjDB(): PDODatabase {
        return $this->objDB;
    }

    /**
     * Replaces the database connection wrapper.
     *
     * Useful when migrating an object between database contexts or during
     * testing with a mock/stub connection.
     *
     * @param PDODatabase $obj New database connection
     */
    public function setObjDB(PDODatabase $obj): void {
        $this->objDB = $obj;
    }

    /**
     * Compares this object to another BHObject by primary key.
     *
     * Two objects are considered equal when both are initialised and share
     * the same integer ID, regardless of their concrete type.
     *
     * @param BHObject $obj Object to compare against
     * @return bool         True if both objects have the same non-null ID
     */
    public function isEqualTo(BHObject $obj): bool {
        //if (!isset($obj)) return false;
        $oid = $obj->getID();
        //if (!isset($oid)) return false;
        return $this->id === $oid;
    }

    /**
     * Returns the list of column names excluded from generic operations.
     *
     * @return array
     */
    public function getDisallowedFields(): array {
        return $this->disallowedFields;
    }


    /****************************************************************/
    /*  ABSTRACT SECURITY METHODS                                   */
    /*  Subclasses must override these with object-specific logic   */
    /****************************************************************/

    /**
     * Determines whether a given user may edit this object.
     *
     * @param int $id User ID to check
     * @return bool
     */
    abstract public function canEdit(int $id): bool;

    /**
     * Determines whether a given user may delete this object.
     *
     * @param int $id User ID to check
     * @return bool
     */
    abstract public function canDelete(int $id): bool;

    /**
     * Determines whether a given user may view this object.
     *
     * @param int $id User ID to check
     * @return bool
     */
    abstract public function canView(int $id): bool;


    /****************************************************************/
    /*  PERSISTENCE METHODS                                         */
    /****************************************************************/

    /**
     * Fetches this object's row from its backing database table.
     *
     * Uses $this->id and $this->tableName to build a parameterised SELECT.
     * Returns null if the ID is not numeric, or if the query returns anything
     * other than exactly one row (guarding against missing or duplicate records).
     *
     * @return mixed Associative array of column values, or null on failure
     */
    public function fetchItem(): mixed {
        if (!is_numeric($this->id)) return null;

        $sql  = "select * from " . $this->tableName . " where id = ?";
        $item = $this->objDB->preparedStatement($sql, [$this->id]);

        // Require exactly one result; zero or multiple rows both indicate a problem
        if ($item->rowCount() == 0 || $item->rowCount() > 1) return null;

        return $item->fetch();
    }

    /**
     * Returns a Bootstrap-styled HTML table representation of this object.
     *
     * The base implementation renders a placeholder. Subclasses should override
     * this to display their own fields in a meaningful layout.
     *
     * @return string HTML table string
     */
    public function getAsTable(): string {
        return "<table class=\"table table-bordered\"><tr><th>No table implemented</th></tr></table>";
    }

    /**
     * Returns a minimal string representation of this object for debugging.
     *
     * Format: "<id> :: <name>"
     *
     * @return string
     */
    public function toString(): string {
        return $this->id . " :: " . $this->name;
    }


    /****************************************************************/
    /*  ENUM RENDERING METHODS                                      */
    /*  Helpers for rendering MySQL ENUM columns as HTML controls   */
    /****************************************************************/

    /**
     * Renders a MySQL ENUM column as an HTML <select> (pull-down) element.
     *
     * Retrieves the allowed values for the specified ENUM column via
     * getEnumVals(), then delegates rendering to htmlPage::makeOptionFromArray().
     *
     * @param string $name     HTML name attribute for the <select> element
     * @param string $table    Database table containing the ENUM column
     * @param string $field    ENUM column name within $table
     * @param mixed  $selected Currently selected value, or 0 for none
     * @param mixed  $onclick  Optional JavaScript onclick handler string, or null
     * @return string          Rendered HTML <select> element
     */
    public function getEnumAsPullDown(string $name, string $table, string $field, mixed $selected = 0, mixed $onclick = null): string {
        $vals = $this->getEnumVals($table, $field);
        $opts = [];
        $c    = 0;

        // Build a two-column [value, label] array; for ENUMs these are identical
        foreach ($vals as $val) {
            $opts[$c][0] = $opts[$c][1] = $val;
            $c++;
        }

        return htmlPage::makeOptionFromArray($name, $opts, $selected, $onclick);
    }

    /**
     * Retrieves the allowed values defined in a MySQL ENUM column.
     *
     * Issues a SHOW COLUMNS query and parses the Type field (e.g.
     * "enum('draft','published','archived')") with a regex to extract the
     * individual quoted option strings.
     *
     * @param string $table Database table name
     * @param string $field ENUM column name within $table
     * @return array        Flat array of option strings, e.g. ['draft','published','archived']
     */
    public function getEnumVals(string $table, string $field): array {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) return [];
        $sql    = "SHOW COLUMNS FROM $table LIKE '$field'";
        $fields = $this->getObjDB()->select($sql);
        $row    = $fields->fetch();

        // The Type column contains a string like: enum('val1','val2','val3')
        // Extract each quoted value with a non-greedy match
        $regex = "/'(.*?)'/";
        preg_match_all($regex, $row["Type"], $enum_array);

        // $enum_array[1] holds the captured groups (the values without quotes)
        return $enum_array[1];
    }

    /**
     * Renders a MySQL ENUM column as a set of HTML radio buttons.
     *
     * Each allowed ENUM value becomes a labelled radio button. The button whose
     * value matches $selected is pre-checked.
     *
     * @param string $name     HTML name attribute shared by all radio inputs
     * @param string $table    Database table containing the ENUM column
     * @param string $field    ENUM column name within $table
     * @param mixed  $selected Currently selected value, or 0 for none
     * @return string          Concatenated HTML string of label + radio input pairs
     */
    public function getEnumAsRadioButtons(string $name, string $table, string $field, mixed $selected = 0): string {
        $vals = $this->getEnumVals($table, $field);
        $str  = "";

        foreach ($vals as $val) {
            // Render "Label <input type='radio' ...>" for each ENUM option
            $str .= "$val " . htmlPage::makeRadioButton($name, $val, ((string)$selected === (string)$val) ? 1 : 0) . "  ";
        }

        return $str;
    }


    /****************************************************************/
    /*  COMMENTED-OUT CODE (retained for reference)                 */
    /****************************************************************/

    /*
     * getDatePullDown() — renders a day/month/year pull-down set from a SQL date string.
     * Commented out; uses ICHTBObject::$FUTURE_YEARS which belongs to an older class hierarchy.
     *
     * getYesNoCheckBox() — renders Yes / No / Unknown radio buttons.
     * Commented out; superseded by getEnumAsRadioButtons() for boolean-style fields.
     *
     * cleanseInputString() — strips spaces and escapes a string for MySQL.
     * Commented out; input sanitisation is now handled by PDO prepared statements.
     */
}
?>