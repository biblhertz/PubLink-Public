<?php
namespace Biblhertz\Publink\om;

use Biblhertz\Publink\om\presentation\TaskPresentation;
use Biblhertz\Publink\utilities\PDODatabase;
use PDOStatement;

/**
 * Task
 *
 * Represents a named, configurable operation that the PubLink system can
 * execute on behalf of a user, typically following the pattern:
 *
 *   Input File → Process → Output File
 *
 * Tasks are defined in the `task` database table and are associated with:
 * - A set of permitted input {@see FileType}s (via `task_file_type`).
 * - A per-user execution permission (via `user_details_task`).
 * - An action handler class/identifier used by the dispatcher to run the task.
 *
 * When a user triggers a Task it becomes a {@see Job}, which is queued in
 * Beanstalk and processed asynchronously by the worker process.
 *
 * Presentation is fully delegated to {@see TaskPresentation}, keeping display
 * logic out of the domain object.
 *
 * @package  Biblhertz\Publink\om
 * @author   Chris Tomlinson
 * @since    May 2023
 */
class Task extends BHObject {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var string Human-readable description of what this task does */
    private string $description = "";

    /**
     * @var FileType[] List of file types that are valid inputs for this task.
     *                 Populated from the `task_file_type` join table on construction.
     */
    private array $allowedFileTypes = [];

    /**
     * @var string URL/path-safe version of the task name, used as an identifier
     *             in code and routing contexts (spaces replaced with underscores,
     *             lowercased). Derived from $name on construction.
     */
    private string $codeName = "";

    /**
     * @var string Identifier for the server-side handler class or method that
     *             executes this task (e.g. a controller action name or class FQCN).
     */
    private string $actionHandler = "";

    /**
     * @var string Label displayed on the submit/start button in the task UI
     *             (e.g. 'Run', 'Convert', 'Import').
     */
    private string $actionText = "";

    /**
     * @var string Describes the expected input modality for this task.
     *             Controls which input widget is shown in the task form
     *             (e.g. 'file', 'object', 'none').
     */
    private string $inputType = "";


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs a Task by fetching its record and associated file types from the database.
     *
     * All scalar fields are conditionally set from the `task` row. The
     * $codeName is derived programmatically from $name (lowercased, spaces
     * replaced with underscores) rather than stored as a separate column.
     *
     * Allowed file types are loaded in a single JOIN query against
     * `task_file_type` and `file_type`, and stored as an array of
     * {@see FileType} value objects.
     *
     * @param PDODatabase $objDB Active database connection
     * @param int         $id    Primary key of the `task` row to load
     */
    public function __construct(PDODatabase $objDB, int $id) {
        $this->tableName = "task";
        $this->objDB     = $objDB;

        $this->id = $id;
        $row = $this->fetchItem();
        if ($row === null) throw new \RuntimeException("Task not found: $id");

        if (isset($row['name']))           $this->name          = $row['name'];
        if (isset($row['description']))    $this->description   = $row['description'];
        if (isset($row['action_handler'])) $this->actionHandler = $row['action_handler'];
        if (isset($row['action_text']))    $this->actionText    = $row['action_text'];
        if (isset($row['input_type']))     $this->inputType     = $row['input_type'];

        // Derive a code-safe identifier from the task name
        if (isset($row['name']))
            $this->codeName = str_replace(" ", "_", strtolower($this->name));

        // Load the permitted input file types from the join table
        $types = $this->objDB->preparedSelect(
            "select file_type_id, file_type.name
               from task_file_type, file_type
              where file_type_id = file_type.id
                and task_id = ?",
            [$this->id]
        );
        while ($type = $types->fetch()) {
            $this->allowedFileTypes[] = new FileType($type['file_type_id'], $type['name']);
        }
    }


    /****************************************************************/
    /*  ACCESSOR METHODS                                            */
    /****************************************************************/

    /**
     * Returns the human-readable description of this task.
     *
     * @return string
     */
    public function getDescription(): string {
        return $this->description;
    }

    /**
     * Returns the code-safe identifier derived from the task name.
     *
     * Example: "Convert JATS XML" → "convert_jats_xml"
     *
     * @return string
     */
    public function getCodeName(): string {
        return $this->codeName;
    }

    /**
     * Overrides the code name with a custom value.
     *
     * @param string $name New code name
     */
    public function setCodeName(string $name): void {
        $this->codeName = $name;
    }

    /**
     * Returns the action handler identifier used by the dispatcher to execute
     * this task (e.g. a controller action name or class FQCN).
     *
     * @return string
     */
    public function getActionHandler(): string {
        return $this->actionHandler;
    }

    /**
     * Returns the label for the submit/start button rendered in the task UI.
     *
     * @return string e.g. 'Run', 'Convert', 'Import'
     */
    public function getActionText(): string {
        return $this->actionText;
    }

    /**
     * Returns the input modality expected by this task.
     *
     * Controls which input widget is displayed in the task form.
     *
     * @return string e.g. 'file', 'object', 'none'
     */
    public function getInputType(): string {
        return $this->inputType;
    }

    /**
     * Returns the list of file types permitted as input for this task.
     *
     * @return FileType[]
     */
    public function getAllowedFileTypes(): array {
        return $this->allowedFileTypes;
    }


    /****************************************************************/
    /*  ACCESS-CONTROL METHODS                                      */
    /****************************************************************/

    /**
     * Task editing is always permitted — tasks are shared system definitions
     * managed at the application level rather than per-user resources.
     *
     * @param int $id User ID (unused)
     * @return bool   Always true
     */
    public function canEdit(int $id): bool {
        return true;
    }

    /**
     * Task creation is always permitted.
     *
     * Note: canCreate() is not part of the BHObject abstract contract; it is
     * defined here as an additional interface specific to Task.
     *
     * @param int $id User ID (unused)
     * @return bool   Always true
     */
    public function canCreate(int $id): bool {
        return true;
    }

    /**
     * Task deletion is always permitted — tasks are shared system definitions.
     *
     * @param int $id User ID (unused)
     * @return bool   Always true
     */
    public function canDelete(int $id): bool {
        return true;
    }

    /**
     * Task viewing is always permitted — task definitions are visible to all
     * authenticated users regardless of execution rights.
     *
     * @param int $id User ID (unused)
     * @return bool   Always true
     */
    public function canView(int $id): bool {
        return true;
    }

    /**
     * Determines whether a specific user has been granted permission to execute
     * this task.
     *
     * Unlike the other access-control methods, execution rights are per-user
     * and stored in the `user_details_task` join table. A row must exist for
     * both this task's ID and the user's ID for the check to pass.
     *
     * @param User $user The user requesting execution
     * @return bool      True if a matching row exists in `user_details_task`
     */
    public function canExecute(User $user): bool {
        $canExecute = $this->objDB->preparedGetOne(
            "select id from user_details_task where task_id = ? and user_details_id = ?",
            [$this->id, $user->getID()]
        );
        return (int) $canExecute > 0;
    }


    /****************************************************************/
    /*  QUERY METHODS                                               */
    /****************************************************************/

    /**
     * Returns the set of files owned by a user that are valid inputs for this task.
     *
     * Delegates to {@see User::getMyFilesByFileTypesAsResultSet()} using this
     * task's $allowedFileTypes list. Returns false if the user has no matching
     * files, allowing callers to handle the empty case without iterating a
     * result set.
     *
     * @param User $user The user whose file store to query
     * @return mixed     PDOStatement result set on success, false if no files match
     */
    public function getAvailableFiles(User $user): mixed {
        $files = $user->getMyFilesByFileTypesAsResultSet($this->getAllowedFileTypes());
        if ($files->rowCount() === 0) return false;
        return $files;
    }

    /**
     * Returns all serialized objects owned by a user, available as task inputs.
     *
     * Used when $inputType indicates the task operates on a serialized domain
     * object (e.g. an Article) rather than a raw file. Delegates directly to
     * {@see User::getMyObjectsAsResultSet()}.
     *
     * @param User $user The user whose objects to query
     * @return PDOStatement Result set of serialized_object rows
     */
    public function getAvailableObjects(User $user): PDOStatement {
        return $user->getMyObjectsAsResultSet();
    }

    /**
     * Checks whether a given file type label is in this task's allowed types list.
     *
     * Performs a case-sensitive string comparison against each FileType's name.
     *
     * @param string $s File type label to search for (e.g. 'JATS xml', 'pdf')
     * @return bool     True if the type is permitted as input for this task
     */
    public function fileTypesContains(string $s): bool {
        foreach ($this->allowedFileTypes as $type) {
            if ($type->getName() === $s) return true;
        }
        return false;
    }


    /****************************************************************/
    /*  MAGIC METHODS                                               */
    /****************************************************************/

    /**
     * Returns a concise debug string identifying this task.
     *
     * @return string e.g. "Task ID :: 3 : Task Name :: Convert JATS XML"
     */
    public function __toString(): string {
        return "Task ID :: " . $this->getID() . " : Task Name :: " . $this->name;
    }


    /****************************************************************/
    /*  PRESENTATION METHODS                                        */
    /****************************************************************/

    /**
     * Returns this task rendered as an HTML table.
     *
     * Delegates all presentation logic to {@see TaskPresentation}, keeping
     * the domain object free of display concerns.
     *
     * @return string Rendered HTML table string
     */
    public function getAsTable(): string {
        $presentation = new TaskPresentation($this);
        return $presentation->getAsTable();
    }

    /**
     * Returns this task rendered as an HTML form targeting $address.
     *
     * Delegates to {@see TaskPresentation::getAsForm()}. The form includes the
     * appropriate input widget for this task's $inputType and the submit button
     * labelled with $actionText.
     *
     * @param string $address Form action URL or controller address
     * @return string         Rendered HTML form string
     */
    public function getAsForm(string $address): string {
        $presentation = new TaskPresentation($this);
        return $presentation->getAsForm($address);
    }
}
?>