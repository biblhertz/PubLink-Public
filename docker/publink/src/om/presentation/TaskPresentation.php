<?php
namespace Biblhertz\Publink\om\presentation;

use Biblhertz\Publink\components\BootstrapTabbedPanel;
use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\presentation\FilePresentation;
use Biblhertz\Publink\pages\htmlPage;
use PDOStatement;

/**
 * TaskPresentation
 *
 * Base presentation class for Task objects in the PubLink publishing workflow.
 * Renders a task as a two-tab Bootstrap panel with a details view and an
 * interactive file/object selection form for submitting the task.
 *
 * Subclasses (e.g. CreateArticlePresentation) override renderTask() to provide
 * task-specific file picker layouts. This base implementation handles three
 * generic input type modes driven by Task::getInputType():
 *
 * | Input Type                  | Selection UI                                      |
 * |-----------------------------|---------------------------------------------------|
 * | Single File                 | Radio button list of files or objects             |
 * | Multiple File               | Checkbox list of files or objects                 |
 * | Single File and Single Object| Two radio button lists with combined visibility  |
 *
 * The $themes array provides Bootstrap colour variants applied randomly to each
 * rendered panel, and is available to subclasses via the protected access modifier.
 *
 * @package Biblhertz\Publink\om\presentation
 * @author  Chris Tomlinson
 * @since   May 2023
 */
class TaskPresentation
{

    /********************************************************************/
    /*  INSTANCE VARIABLES                                              */
    /********************************************************************/

    /** @var Task The task instance this presentation object wraps. */
    protected Task $task;

    /**
     * @var array Bootstrap colour theme names available for panel styling.
     *            One is selected at random each time renderTask() is called.
     */
    protected array $themes = array("primary", "secondary", "success", "danger", "warning", "info", "light", "dark");

    /**
     * @var bool When true, the rendered panel opens on tab 1 (the file picker)
     *           rather than tab 0 (details). Typically set when returning to
     *           the task after a form submission.
     */
    protected bool $display;


    /********************************************************************/
    /*  CONSTRUCTOR                                                     */
    /********************************************************************/

    /**
     * Construct a TaskPresentation for the given task.
     *
     * @param Task $task    The task to present.
     * @param bool $display When true, the panel opens on the file picker tab. Defaults to false.
     */
    public function __construct(Task $task, bool $display = false)
    {
        $this->task    = $task;
        $this->display = $display;
    }

    /**
     * Return the wrapped Task instance.
     *
     * Used by FilePresentation and SerializedObjectPresentation static methods
     * to access the task's code name, action handler, and other properties.
     *
     * @return Task
     */
    public function getTask(): Task
    {
        return $this->task;
    }


    /********************************************************************/
    /*  MAGIC METHODS                                                   */
    /********************************************************************/

    /**
     * Return a brief string representation of this presentation object.
     *
     *
     * @return string e.g. "Task ID :: 5 : Task Name :: Convert JATS"
     */
    public function __toString(): string
    {
       return "Task ID :: " . htmlspecialchars($this->task->getID(), ENT_QUOTES, 'UTF-8') . " : Task Name :: " . htmlspecialchars($this->task->getName(), ENT_QUOTES, 'UTF-8');
    }


    /********************************************************************/
    /*  TABLE & FORM METHODS                                            */
    /********************************************************************/

    /**
     * Render the task metadata as a Bootstrap bordered table.
     *
     * Displays: Name, Description, and Allowed File Types (as a comma-separated
     * list of type names). The trailing comma is stripped before output.
     *
     * @return string HTML Bootstrap table string.
     */
    public function getAsTable(): string
    {
        // Build comma-separated list of allowed file type names.
        $tstr = "";
        foreach ($this->task->getAllowedFileTypes() as $type) {
            $tstr .= htmlspecialchars($type->getName(), ENT_QUOTES, 'UTF-8') . ", ";
        }
        if (strlen($tstr)) $tstr = substr($tstr, 0, strlen($tstr) - 2);

        $str = "<table class=\"table table-bordered\" style=\"font-size:12px;\">
                    <tr><th>Name</th><td>" . htmlspecialchars($this->task->getName(), ENT_QUOTES, 'UTF-8') . "</td></tr>
                    <tr><th>Description</th><td>" . htmlspecialchars($this->task->getDescription(), ENT_QUOTES, 'UTF-8') . "</td></tr>
                    <tr><th>Allowed File Types</th><td>$tstr</td></tr>
                ";

        return $str . "</table>";
    }

    /**
     * Render the task as an editable POST form.
     *
     * Presents Name and Description fields and a Save button that posts to $address.
     * The form is embedded inside a Bootstrap bordered table for consistent layout.
     *
     * Note: A `<form>` element inside a `<table>` (outside `<td>`) is invalid HTML
     * per the HTML spec. The form tag should be placed outside the table, or the
     * table replaced with a div layout.
     *
     * @param string $address Form action URL.
     * @return string HTML form string.
     */
    public function getAsForm(string $address): string
    {
        $str  = "<form action=\"" . htmlspecialchars($address, ENT_QUOTES, 'UTF-8') . "\" method=\"POST\">"."<table class=\"table table-bordered\">";
        $str .= "<tr><th>Name</th><td>" . htmlPage::makeInput("name", 30, "EDIT", 50, $this->task->getName()) . "</td></tr>";
        $str .= "<tr><th>Description</th><td>" . htmlPage::makeTextArea("description", 5, 50, $this->task->getDescription()) . "</td></tr>";
        $str .= "<tr><th>Save</th><th>" . htmlPage::makeButton("updateTask", "Save") . "</th></tr>";
        $str .= "</table></form>";

        return $str;
    }


    /********************************************************************/
    /*  RENDER TASK                                                     */
    /********************************************************************/

    /**
     * Render the task as a two-tab Bootstrap panel for file/object submission.
     *
     * Tab layout:
     * - Tab 0 (index 0): Task details via getAsTable().
     * - Tab 1 (index 1): File/object picker form, built according to
     *   Task::getInputType().
     *
     * Note: Tab indices are assigned in reverse order ($tabContent[1] = details,
     * $tabContent[0] = file picker) despite the headings array ordering them as
     * ["Details", "Run <name>"]. This means tab 0 shows the file picker and
     * tab 1 shows the details — the opposite of what the headings suggest.
     * This should be corrected by swapping the $tabContent[0] and $tabContent[1]
     * assignments.
     *
     * Input type handling:
     *
     * "Single File" — Radio button list. Uses object list if the task's file
     * types contain "object", file list otherwise.
     *
     * "Multiple File" — Checkbox list. Same object/file branching as above.
     *
     * "Single File and Single Object" — Two separate radio button lists (one for
     * objects, one for files) rendered in a single form. The submit button is
     * hidden until one selection is made in each list, controlled by a jQuery
     * change handler. The task code name is temporarily mutated to produce
     * distinct radio group names for each list, then restored.
     *
     * @param User $user The user whose files and objects are presented.
     * @return string Rendered HTML of the BootstrapTabbedPanel component.
     */
    public function renderTask(User $user): string
    {
        $panel = new BootstrapTabbedPanel();
        $panel->setTitle($this->task->getName());
        $panel->setName($this->task->getCodeName() . "_panel");

        // Apply a random Bootstrap theme to visually differentiate task panels.
        $theme = $this->themes[rand(0, count($this->themes) - 1)];
        $panel->setTheme($theme);

        $headings   = array("Details", "Run " . htmlspecialchars($this->task->getName(), ENT_QUOTES, 'UTF-8'));
        $tabContent = array();

        $allowedFileTypes = $this->task->getAllowedFileTypes();
        $files = $user->getMyFilesByFileTypesAsResultSet($allowedFileTypes);

        // If the task accepts "object" type, also check for available objects.
        if ($this->task->fileTypesContains("object")) {
            $objects = $user->getMyObjectsAsResultSet();
            // Treat files as available if objects exist, even if no conventional files do.
            if ($this->task->getObjDB()->numRows() && !$files) $files = true;
        }

        if ($files != false) {
            $fileRender = "";
            $buttonid   = $this->task->getCodeName() . "_button";

            switch ($this->task->getInputType()) {

                case "Single File":
                    // Radio button selection — objects take precedence over files if applicable.
                    if ($this->task->fileTypesContains("object")) {
                        if (!$this->task->getAvailableObjects($user))
                            $fileRender = "No objects currently exist";
                        else
                            $fileRender = SerializedObjectPresentation::getObjectListAsRadioButtonTable($user->getMyObjectsAsResultSet(), $this);
                    } else {
                        if (!$this->task->getAvailableFiles($user))
                            $fileRender = "No files of the correct type currently exist";
                        else
                            $fileRender = FilePresentation::getFileListAsRadioButtonTable($files, $this);
                    }
                    break;

                case "Multiple File":
                    // Checkbox selection — objects take precedence over files if applicable.
                    if ($this->task->fileTypesContains("object")) {
                        if (!$this->task->getAvailableObjects($user))
                            $fileRender = "No objects currently exist";
                        else
                            $fileRender = SerializedObjectPresentation::getObjectListAsCheckBoxTable($user->getMyObjectsAsResultSet(), $this);
                    } else {
                        if (!$this->task->getAvailableFiles($user))
                            $fileRender = "No files of the correct type currently exist";
                        else
                            $fileRender = FilePresentation::getFileListAsCheckBoxTable($files, $this);
                    }
                    break;

                case "Single File and Single Object":
                    // Requires at least one file AND one object. Both must be selected
                    // before the submit button is revealed.
                    if (!$this->task->getAvailableObjects($user) || !$this->task->getAvailableFiles($user)) {
                        $fileRender = "You need at least one file of the correct type AND at least one object to perform this task";
                    } else {
                        $funcid   = $this->task->getCodeName() . "_func()";
                        $codeName = $this->task->getCodeName();

                        $safeAction     = htmlspecialchars($this->task->getActionHandler(), ENT_QUOTES, 'UTF-8');
                        $safeButtonId   = htmlspecialchars($buttonid, ENT_QUOTES, 'UTF-8');
                        $safeActionText = htmlspecialchars($this->task->getActionText(), ENT_QUOTES, 'UTF-8');

                        $fileRender  = "<form action=\"$safeAction\" method=\"POST\">"
                                     . htmlPage::makeHiddenInput("task_id", $this->task->getID());

                        // Object picker — code name is temporarily mutated for distinct group naming.
                        $fileRender .= "<h5>Select Object to Put into JATS XML</h5>";
                        $buttonGroup1 = $codeName . "_objectList";
                        $this->task->setCodeName($buttonGroup1);
                        $fileRender .= SerializedObjectPresentation::getObjectListAsRadioButtonTable($user->getMyObjectsAsResultSet(), $this, false, false);

                        // File picker — code name mutated again for the second group.
                        $fileRender .= "<h5>Select JATS XML file to alter</h5>";
                        $buttonGroup2 = $codeName . "_fileList";
                        $this->task->setCodeName($buttonGroup2);
                        $fileRender .= FilePresentation::getFileListAsRadioButtonTable($files, $this, false, false);

                        // Restore original code name before generating the button and script.
                        $this->task->setCodeName($codeName);

                        $fileRender .= "<div class=\"d-grid gap-2 d-md-flex justify-content-md-end\">"
                                     . "<button class=\"btn btn-outline-primary\" name=\"$safeButtonId\" id=\"$safeButtonId\" type=\"SUBMIT\">"
                                     . $safeActionText
                                     . "</button></div></form>";

                        // jQuery change handler: reveals submit button only when both groups have a selection.
                        // Note: $buttonid, $funcid, $buttonGroup1, $buttonGroup2 are developer-controlled
                        // code name identifiers. htmlspecialchars is applied defensively.
                        $fileRender .= "<script type=\"text/javascript\">"
                                     . "\$( document ).ready(function() { \$(\"#" . htmlspecialchars($buttonid, ENT_QUOTES, 'UTF-8') . "\").hide(); }); "
                                     . "function " . htmlspecialchars($funcid, ENT_QUOTES, 'UTF-8') . " { \$(\"#" . htmlspecialchars($buttonid, ENT_QUOTES, 'UTF-8') . "\").show(); } "
                                     . "\$(\"input[type=radio]\").on(\"change\", function () {"
                                     . "var checked1 = false, checked2 = false;"
                                     . "\$(\"input[name='" . htmlspecialchars($buttonGroup1, ENT_QUOTES, 'UTF-8') . "']\").each(function(){ if(\$(this).is(':checked')) checked1=true; });"
                                     . "\$(\"input[name='" . htmlspecialchars($buttonGroup2, ENT_QUOTES, 'UTF-8') . "']\").each(function(){ if(\$(this).is(':checked')) checked2=true; });"
                                     . "if(checked1&&checked2){ console.log('Show Button'); " . htmlspecialchars($funcid, ENT_QUOTES, 'UTF-8') . " }"
                                     . "else console.log('Checked :: '+checked1+' :: '+checked2);"
                                     . "});</script>";
                    }
                    break;
            }
        } else {
            $fileRender = "No files of the correct file types available";
        }

    
        $safeCodeName = htmlspecialchars($this->task->getCodeName(), ENT_QUOTES, 'UTF-8');
        $tabContent[0] = "<div class=\"card-body p-0\" id=\"{$safeCodeName}_filechooser\">"
                       . $fileRender . "</div>";
        $tabContent[1] = "<div class=\"card-body p-0\" id=\"{$safeCodeName}_taskdetails\">"
                       . $this->getAsTable() . "</div>";
       

        $panel->setTabNames($headings);
        $panel->setTabContent($tabContent);
        if ($this->display) $panel->setOpenTab(1);

        return $panel->getComponent();
    }


    /********************************************************************/
    /*  STATIC UTILITY METHODS                                          */
    /********************************************************************/

    /**
     * Render a PDOStatement result set of tasks as a Bootstrap bordered table.
     *
     * Each row shows the task name linked to task.html?tid=<id> alongside the
     * task's own getAsTable() output.
     *
     * Note: $objDB is referenced inside the loop but is not declared or passed
     * as a parameter, which will cause an "Undefined variable" notice at runtime.
     * A PDODatabase parameter should be added to the method signature.
     *
     * @param PDOStatement $tasks Result set of task rows (must include 'id').
     * @return string HTML Bootstrap table string.
     */
    public static function getTaskListAsTable(PDOStatement $tasks, PDODatabase $objDB): string
    {
        $str = "<table class=\"table table-bordered\">";
        while ($task = $tasks->fetch()) {
            $task = new Task($objDB, $task['id']);
            $str .= "<tr><th>"
                  . htmlPage::makeLink("task.html?tid=" . $task->getID(), $task->getName())
                  . "</th><td>" . $task->getAsTable() . "</td></tr>";
        }
        $str .= "</table>";
        return $str;
    }
}
?>