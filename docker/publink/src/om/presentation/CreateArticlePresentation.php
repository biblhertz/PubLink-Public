<?php
namespace Biblhertz\Publink\om\presentation;

use Biblhertz\Publink\components\BootstrapTabbedPanel;
use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\FileType;
use Biblhertz\Publink\om\presentation\FilePresentation;
use Biblhertz\Publink\om\presentation\TaskPresentation;
use Biblhertz\Publink\pages\htmlPage;

/**
 * CreateArticlePresentation
 *
 * Presentation class for the "Create Article" task in the PubLink publishing
 * workflow. Extends TaskPresentation to render a two-tab Bootstrap panel that
 * allows a user to:
 *
 * - View task details (tab 0: "Details").
 * - Select input files and submit the task (tab 1: "Run <TaskName>").
 *
 * The file selection tab presents three distinct file pickers:
 * 1. JATS XML file  — the primary source document (radio button selection).
 * 2. Cover image    — an optional cover image (radio button selection).
 * 3. Galley files   — any additional publication files, e.g. PDFs (checkbox selection).
 *
 * An OJS username input is also provided so the article can be associated with
 * the correct OJS account on submission.
 *
 * The submit button is hidden on page load and revealed by a JavaScript function
 * (named <codeName>_onclick()) which subclasses or calling code can trigger at
 * an appropriate point (e.g. after a confirmation step).
 *
 * @package Biblhertz\Publink\om\presentation
 * @author  Chris Tomlinson
 * @since   May 2023
 */
class CreateArticlePresentation extends TaskPresentation
{

    /********************************************************************/
    /*  CONSTRUCTOR                                                     */
    /********************************************************************/

    /**
     * Construct a CreateArticlePresentation for the given task.
     *
     * Delegates to TaskPresentation::__construct(), which stores the task
     * and display flag as instance properties.
     *
     * @param Task  $task    The task instance to present.
     * @param bool  $display When true, the panel opens on tab 1 (the file picker)
     *                       rather than tab 0 (details). Defaults to false.
     */
    public function __construct(Task $task, $display = false)
    {
        parent::__construct($task, $display);
    }


    /********************************************************************/
    /*  RENDER                                                          */
    /********************************************************************/

    /**
     * Render the full Create Article task UI as a Bootstrap tabbed panel.
     *
     * Builds a two-tab panel:
     * - Tab 0 "Details": the task metadata table from TaskPresentation::getAsTable().
     * - Tab 1 "Run <name>": a POST form containing three file pickers and an OJS
     *   username field. Each file picker section is only shown if matching files
     *   exist for the user; otherwise a "no files available" message is displayed.
     *
     * A random theme from $this->themes is applied to the panel on each render.
     *
     * Note: $this->task->setCodeName() is temporarily overwritten to "cover_file"
     * during the cover image file list render so that the radio button inputs are
     * named correctly, then restored to the original code name afterwards. This is
     * a side effect on the task object and could cause issues if renderTask() is
     * called in a context where the task's code name is read concurrently.
     *
     * @param User $user The user whose files are presented for selection.
     * @return string Rendered HTML string of the BootstrapTabbedPanel component.
     */
    public function renderTask(User $user): string
    {
        $panel = new BootstrapTabbedPanel();
        $panel->setTitle($this->task->getName());
        $panel->setName($this->task->getCodeName() . "_panel");

        // Apply a randomly selected visual theme to differentiate task panels.
        $theme = $this->themes[rand(0, count($this->themes) - 1)];
        $panel->setTheme($theme);

        $headings = array("Details", "Run " . $this->task->getName());
        $tabContent = array();

        // Capture the original code name — it is temporarily mutated during cover
        // image rendering and must be restored before the galley files section.
        $name     = $this->task->getCodeName();
        $text     = $this->task->getActionText();
        $action   = $this->task->getActionHandler();
        $buttonid = $name . "_button";
        $funcid   = $name . "_onclick()";

        // Fetch JATS XML files using the task's configured allowed file types.
        $files = $user->getMyFilesByFileTypesAsResultSet($this->task->getAllowedFileTypes());

        if ($files !== false) {
            // --- JATS XML file picker ---
            $fileRender  = "<form action=\"$action\" method=\"POST\">"
                         . htmlPage::makeHiddenInput("task_id", $this->task->getID());
            $fileRender .= "<b>JATS XML File</b>"
                         . FilePresentation::getFileListAsRadioButtonTable($files, $this, false)
                         . "<hr/>";

            // --- Cover image file picker ---
            // Fetch image files specifically by querying the file_type table for type='image'.
            $fileTypes = array();
            $types = $this->task->getObjDB()->preparedSelect("select * from file_type where type = ?",array('image'));
            while ($type = $types->fetch()) {
                array_push($fileTypes, new FileType($type['id'], $type['name']));
            }
            $files = $user->getMyFilesByFileTypesAsResultSet($fileTypes);

            // Temporarily rename code name so cover image inputs are distinctly named in the form.
            $this->task->setCodeName("cover_file");
            if ($files !== false) {
                $fileRender .= "<b>Cover Image</b>"
                             . FilePresentation::getFileListAsRadioButtonTable($files, $this, false, false)
                             . "<hr/>";
            } else {
                $fileRender .= "<b>Cover Image</b><p>No image files available</p>";
            }

            // Restore original code name before rendering further sections.
            $this->task->setCodeName($name);

            // --- Galley files picker ---
            // Fetch all file types except 'log' and 'JATS xml' for galley file selection.
            $fileTypes = array();
            $types = $this->task->getObjDB()->preparedSelect("select * from file_type where name <> ? and name <> ?",array('log','JATS xml'));
            while ($type = $types->fetch()) {
                array_push($fileTypes, new FileType($type['id'], $type['name']));
            }
            $files = $user->getMyFilesByFileTypesAsResultSet($fileTypes);
            if ($files !== false) {
                $fileRender .= "<b>Galley Files</b>"
                             . FilePresentation::getFileListAsCheckBoxTable($files, $this, false)
                             . "<hr/>";
            } else {
                $fileRender .= "<b>Galley Files</b><p>No galley files available</p>";
            }

            // --- OJS username input ---
            // The OJS user name is needed to associate the created article with the
            // correct account on the target OJS installation.
            $fileRender .= "<table width=\"100%\" class=\"table table-bordered\">"
                         . "<tr><th width=\"50%\">OJS User Name</th>"
                         . "<td>" . htmlPage::makeInput("ojs_user", 30, 30) . "</td>"
                         . "</tr></table><hr/>";

            // --- Submit button ---
            // The button is hidden on page load; it is shown by calling <codeName>_onclick()
            // from external JS (e.g. after a user confirmation dialog).
            $fileRender .= "<div class=\"d-grid gap-2 d-md-flex justify-content-md-end\">"
                         . "<button class=\"btn btn-outline-primary\" name=\"$buttonid\" id=\"$buttonid\" type=\"SUBMIT\">$text</button>"
                         . "</div></form>";
            $fileRender .= "<script type=\"text/javascript\">"
                         . "\$( document ).ready(function() { \$(\"#$buttonid\").hide(); }); "
                         . "function " . $funcid . "{ \$(\"#" . $buttonid . "\").show(); }"
                         . "</script>";

        } else {
            $fileRender = "<p>No files of the correct file types available</p>";
        }

        // Tab 0: task details metadata table.
        $tabContent[0] = "<div class=\"card-body p-0\" id=\"" . $this->task->getCodeName() . "_taskdetails\">"
                       . $this->getAsTable()
                       . "</div>";

        // Tab 1: file picker form.
        $tabContent[1] = "<div class=\"card-body p-0\" id=\"" . $this->task->getCodeName() . "_filechooser\">"
                       . $fileRender
                       . "</div>";

        $panel->setTabNames($headings);
        $panel->setTabContent($tabContent);

        // Open on tab 1 directly if $display is true (e.g. returning after a form submission).
        if ($this->display) $panel->setOpenTab(1);

        return $panel->getComponent();
    }
}
?>