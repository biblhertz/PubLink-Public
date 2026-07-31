<?php
namespace Biblhertz\Publink\om\presentation;

use Biblhertz\Publink\om\File;
use Biblhertz\Publink\components\BootstrapRadioButton;
use Biblhertz\Publink\pages\htmlPage;
use PDOStatement;
use Biblhertz\Publink\utilities\PDODatabase;

/**
 * FilePresentation
 *
 * Presentation layer for File objects in the PubLink publishing workflow.
 * Extends File to add HTML rendering methods, and provides a suite of static
 * factory methods for generating file list UI components used within task forms.
 *
 * Static methods cover four distinct rendering modes:
 *
 * | Method                            | Output type           | Selection type  |
 * |-----------------------------------|-----------------------|-----------------|
 * | getFileListAsTable()              | DataTable             | None (view only)|
 * | getFileListAsCheckBoxTable()      | Task form table       | Checkboxes      |
 * | getFileListAsRadioButtonTable()   | Task form table       | Radio buttons   |
 * | getFileListAsRBTable()            | Bare table (no form)  | Radio buttons   |
 * | getFileListAsRBTableFromArray()   | Bare table (no form)  | Radio buttons   |
 * | getImageFileListAsCheckBoxTable() | Image preview table   | Checkboxes      |
 *
 * Methods that accept a TaskPresentation parameter derive their form action,
 * button labels, and input names from the task's code name and action handler,
 * keeping the rendering logic decoupled from task-specific configuration.
 *
 * @package Biblhertz\Publink\om\presentation
 * @author  Chris Tomlinson
 * @since   June 2023
 */
class FilePresentation extends File
{

    /********************************************************************/
    /*  INHERITED INSTANCE METHODS                                      */
    /********************************************************************/

    /**
     * Render this file as a minimal Bootstrap striped table showing its ID and name.
     *
     * Implements the abstract/inherited getAsTable() contract from File.
     *
     * @return string HTML table string.
     */
    public function getAsTable(): string
    {
        return "<table class=\"table striped\"><tr><th>ID</th><th>" . $this->getName() . "</th></tr></table>";
    }


    /********************************************************************/
    /*  STATIC RENDERING METHODS                                        */
    /********************************************************************/

    /**
     * Render a DataTable-enhanced file list from a PDOStatement result set.
     *
     * Each row shows a file icon or image thumbnail (linked to the image viewer
     * for image files), the file name (linked to the download URL), file extension,
     * and upload timestamp. An optional delete button column can be enabled via
     * $delete; the button is suppressed for files that have a linked object.
     *
     * The delete button triggers a JavaScript `delClick(fid, log)` function which
     * must be defined in the calling page. Log files pass `log=1` to distinguish
     * them from other file types.
     *
     * Image thumbnails are embedded as inline base64 data URIs read from the
     * file's thumbnail path.
     *
     * Note: The DataTable initialisation script calls DataTable() twice — once
     * inside $(document).ready() and once immediately after. The second call is
     * redundant and may trigger a "Cannot reinitialise DataTable" warning in the
     * browser console. The ready() call alone is sufficient.
     *
     * @param PDOStatement $files  Result set of file rows (must include 'id', 'icon', 'timestamp').
     * @param PDODatabase  $objDB  Active database connection used to instantiate File objects.
     * @param bool         $delete When true, adds a delete button column. Defaults to false.
     * @return string HTML string containing the table, DataTable init script, and wrapper div.
     */
    public static function getFileListAsTable(PDOStatement $files, PDODatabase $objDB, bool $delete = false): string
    {
        $tableId = uniqid("table_");
        $str  = "<table class=\"table table-sm responsive\" id=\"$tableId\">";
        $str .= "<thead><tr class=\"small\"><th>File</th><th>File Name</th><th>Ext</th><th>Uploaded</th>";
        if ($delete) $str .= "<th></th>";
        $str .= "</tr></thead><tbody>";

        while ($file = $files->fetch()) {
            $fileObj = new File($objDB, $file['id']);
            $fid     = $fileObj->getID();
            $type    = $fileObj->getType();

            $href  = "profile.html?uid=" . $fileObj->getUserID() . "&&fileDownload=$fid";
            $href2 = "image.html?viewImage=$fid";

            if (!strcmp($type, "image")) {
                // Embed thumbnail as inline base64 data URI for image files.
                $imageData = file_get_contents($fileObj->getThumbNailPath());
                $img       = "<img src=\"$imageData\" class=\"img-thumbnail\" style=\"max-height:50px;\">";
                $img       = htmlPage::makeLink($href2, $img, "viewImage_$fid");
                $str .= "<tr class=\"small\"><td>$img</td>"
                      . "<td><a href=\"$href\">" . $fileObj->getName() . "</a></td>"
                      . "<td>" . $fileObj->getFileExtension() . "</td>";
            } else {
                // Use the file type icon for non-image files.
                $img  = htmlPage::getImageRoot() . $file['icon'];
                $img  = "<img src=\"$img\" height=50 />";
                $str .= "<tr class=\"small\"><td>$img</td>"
                      . "<td><a data-toggle=\"tooltip\" data-placement=\"top\" title=\"\" href=\"$href\">"
                      . $fileObj->getName() . "</a></td>"
                      . "<td>" . $fileObj->getFileExtension() . "</td>";
            }

            if (isset($file['timestamp'])) {
                $timestamp = htmlPage::getTimeStampAsDateTimeArray($fileObj->getTimeStamp());
                $date      = $timestamp[0];
                $time      = $timestamp[1];
                $str .= "<td>$date @ $time</td>";
            } else {
                $str .= "<td></td>";
            }

            $lid = "link_" . $fid;
            if ($delete) {
                // Log files are flagged so delClick() can apply different confirmation logic.
                $log = (!strcmp($fileObj->getFileExtension(), "log")) ? 1 : 0;
                if ($fileObj->hasLinkedObject()) {
                    $str .= "<td></td>"; // Linked files cannot be deleted.
                } else {
                    $str .= "<td><a href=\"javascript:void(0)\" class=\"btn btn-danger btn-sm\" id=\"$lid\" onclick=\"delClick($fid,$log)\">Del</a></td>";
                }
            }

            $str .= "</tr>";
        }

        $str .= "</tbody></table>";
        $str .= "<script>
            $(document).ready(function() {
                $('#$tableId').DataTable({paging: true, destroy: true});
            });
            $('#$tableId').DataTable({paging: true, destroy: true});
        </script>";

        return "<div style=\"font-size:12px;\">" . $str . "</div>";
    }

    /**
     * Render a checkbox file selection table for use inside a task submission form.
     *
     * When $button is true, the method wraps the table in a POST form and appends
     * a submit button (hidden on load) plus a JavaScript snippet that reveals the
     * button only when at least one checkbox is checked. When $button is false,
     * the bare table is returned for embedding in a parent form.
     *
     * Checkbox input names follow the pattern `<codeName>_cb[]` so the entire
     * selection is submitted as an array.
     *
     * The onClick handler on each checkbox calls `<codeName>_onclick()`, which
     * the injected script defines to show/hide the submit button based on
     * checked count.
     *
     * @param PDOStatement     $files            Result set of file rows ('id', 'name', 'file_extension', 'user_details_id').
     * @param TaskPresentation $taskpresentation Task context providing code name, action, and button text.
     * @param bool             $button           When true, wraps output in a form with a submit button. Defaults to true.
     * @return string HTML string of the checkbox table (and optional form wrapper + script).
     */
    public static function getFileListAsCheckBoxTable(PDOStatement $files, TaskPresentation $taskpresentation, bool $button = true): string
    {
        $task     = $taskpresentation->getTask();
        $name     = $task->getCodeName();
        $text     = $task->getActionText();
        $action   = $task->getActionHandler();
        $buttonid = $name . "_button";
        $funcid   = $name . "_onclick()";
        $cbid     = $name . "_cb";
        $str      = "";

        if ($button) {
            $str = "<form action=\"$action\" method=\"POST\">"
                 . htmlPage::makeHiddenInput("task_id", $task->getID());
        }

        $str .= "<table class=\"table table-sm\">";
        $str .= "<tr class=\"small\"><th width=\"15%\">Select</th><th width=\"70%\">File</th><th width=\"15%\">Ext</th></tr>";

        // The onclick attribute is only added to checkboxes when the button is managed by this method.
        $onclick = $button ? "onclick=\"$funcid\"" : "";

        while ($file = $files->fetch()) {
            $str .= "<tr class=\"small\"><td>"
                  . "<input type=\"checkbox\" id=\"{$cbid}[]\" name=\"{$cbid}[]\" value=\"{$file['id']}\" $onclick />"
                  . "</td>";
            if (strlen($file['name'] > 50)) $file['name'] = substr($file['name'], 0, 50);
            $str .= "<td><a href=\"user.html?uid={$file['user_details_id']}&&fileDownload={$file['id']}\">"
                  . $file['name'] . "</a></td>"
                  . "<td>" . $file['file_extension'] . "</td></tr>";
        }

        $str .= "</table>";

        if ($button) {
            $str .= "<div class=\"d-grid gap-2 d-md-flex justify-content-md-end\">"
                  . "<button class=\"btn btn-outline-primary\" name=\"$buttonid\" id=\"$buttonid\" type=\"SUBMIT\">$text</button>"
                  . "</div></form>";
            // Show button only when at least one checkbox is checked.
            $str .= "<script type=\"text/javascript\">"
                  . "\$( document ).ready(function() { \$(\"#$buttonid\").hide(); }); "
                  . "function $funcid {"
                  . "var total = \$('input[name=\"{$cbid}[]\"]').filter(':checked').length;"
                  . "if (total > 0) \$(\"#$buttonid\").show(); else \$(\"#$buttonid\").hide();"
                  . "}</script>";
        }

        return $str;
    }

    /**
     * Render a radio button file selection table for use inside a task submission form.
     *
     * Delegates the table rows to getFileListAsRBTable(). When $button is true,
     * wraps the output in a POST form with a submit button hidden on load and
     * revealed by the `<codeName>_onclick()` JavaScript function.
     *
     * When $button is false, the bare table is returned for embedding in a
     * parent form (as used by CreateArticlePresentation for multi-section forms).
     *
     * Note: The method lacks a return type declaration in the original. It always
     * returns a string and should be declared `: string`.
     *
     * @param PDOStatement     $files            Result set of file rows.
     * @param TaskPresentation $taskpresentation Task context providing code name, action, and button text.
     * @param bool             $button           When true, wraps output in a form with submit button. Defaults to true.
     * @return string HTML string of the radio button table (and optional form wrapper + script).
     */
    public static function getFileListAsRadioButtonTable(PDOStatement $files, TaskPresentation $taskpresentation, bool $button = true): string
    {
        $task     = $taskpresentation->getTask();
        $name     = $task->getCodeName();
        $text     = $task->getActionText();
        $action   = $task->getActionHandler();
        $buttonid = $name . "_button";
        $funcid   = $task->getCodeName() . "_onclick()";
        $formid   = $task->getCodeName() . "_form";
        $str      = "";

        if ($button) {
            $str = "<form action=\"$action\" method=\"POST\" id=\"$formid\">"
                 . htmlPage::makeHiddenInput("task_id", $task->getID());
        }

        $str .= FilePresentation::getFileListAsRBTable($files, $name, $funcid);

        if ($button) {
            $str .= "<div class=\"d-grid gap-2 d-md-flex justify-content-md-end\">"
                  . "<button class=\"btn btn-outline-primary\" name=\"$buttonid\" id=\"$buttonid\" type=\"SUBMIT\">$text</button>"
                  . "</div></form>";
            $str .= "<script type=\"text/javascript\">"
                  . "\$( document ).ready(function() { \$(\"#$buttonid\").hide(); }); "
                  . "function $funcid { \$(\"#$buttonid\").show(); }"
                  . "</script>";
        }

        return $str;
    }

    /**
     * Render a bare radio button file selection table without a form wrapper.
     *
     * Used internally by getFileListAsRadioButtonTable() and directly by callers
     * that manage their own form context (e.g. multi-section forms in
     * CreateArticlePresentation). Returns a "No files available" message if the
     * result set is empty.
     *
     * Each row uses a BootstrapRadioButton component with the group name set to
     * $name, so all buttons in the table form a single mutually exclusive group.
     * The value submitted is the file's database ID.
     *
     * @param PDOStatement $files   Result set of file rows ('id', 'name', 'file_extension', 'user_details_id').
     * @param string       $name    Radio button group name (also used as the POST parameter name).
     * @param mixed        $funcid  JavaScript function name to attach as the onClick handler,
     *                              or false to omit the onClick attribute. Defaults to false.
     * @return string HTML table string, or "No files available" if the result set is empty.
     */
    public static function getFileListAsRBTable(PDOStatement $files, string $name, mixed $funcid = false): string
    {
        if ($files->rowCount() == 0) return "No files available";

        $str  = "<table class=\"table table-sm\">";
        $str .= "<tr class=\"small\"><th width=\"15%\">Select</th><th width=\"70%\">File</th><th width=\"15%\">Ext</th></tr>";

        while ($file = $files->fetch()) {
            $radioButton = new BootstrapRadioButton();
            $radioButton->setGroupName($name);
            $radioButton->setName($name);
            $radioButton->setValue($file['id']);
            if ($funcid) $radioButton->setOnClick($funcid);

            $str .= "<tr class=\"small\"><td>" . $radioButton->getComponent() . "</td>";
            $str .= "<td><a href=\"profile.html?uid={$file['user_details_id']}&&fileDownload={$file['id']}\">"
                  . $file['name'] . "</a></td>"
                  . "<td>" . $file['file_extension'] . "</td></tr>";
        }

        $str .= "</table>";
        return $str;
    }

    /**
     * Render a bare radio button file selection table from a PHP array of file objects.
     *
     * Equivalent to getFileListAsRBTable() but accepts an array of objects rather
     * than a PDOStatement. Each object must expose getJatsID() (used as the radio
     * button value) and getName() (used as the row label).
     *
     * Returns "No files available" if the array is empty.
     *
     * @param array  $files   Array of file-like objects exposing getJatsID() and getName().
     * @param string $name    Radio button group name (also used as the POST parameter name).
     * @param mixed  $funcid  JavaScript function name for the onClick handler,
     *                        or false to omit. Defaults to false.
     * @return string HTML table string, or "No files available" if $files is empty.
     */
    public static function getFileListAsRBTableFromArray(array $files, string $name, mixed $funcid = false): string
    {
        if (count($files) == 0) return "No files available";

        $str  = "<table class=\"table table-sm\">";
        $str .= "<tr class=\"small\"><th width=\"30%\">Select</th><th width=\"70%\">File</th></tr>";

        foreach ($files as $file) {
            $radioButton = new BootstrapRadioButton();
            $radioButton->setGroupName($name);
            $radioButton->setName($name);
            $radioButton->setValue($file->getJatsID());
            if ($funcid) $radioButton->setOnClick($funcid);

            $str .= "<tr class=\"small\"><td>" . $radioButton->getComponent() . "</td>";
            $str .= "<td>" . $file->getName() . "</td><td></td></tr>";
        }

        $str .= "</table>";
        return $str;
    }

    /**
     * Render an image file checkbox selection table with inline thumbnails.
     *
     * Fetches each file as a File object and skips any non-image files silently.
     * Image thumbnails are embedded as inline base64 data URIs and linked to
     * the image viewer page.
     *
     * Checkbox input names follow the pattern `<name>[0]`, `<name>[1]`, etc.,
     * using a sequential counter rather than the file ID, so the selection can
     * be iterated by index on the server side.
     *
     * Note: Non-image files in the result set are silently skipped. If the result
     * set contains mixed types, the table may appear shorter than expected with
     * no explanation to the user.
     *
     * Returns "No files available" if the result set is empty.
     *
     * @param PDODatabase  $objDB Active database connection for File instantiation.
     * @param PDOStatement $files Result set of file rows (must include 'id').
     * @param string       $name  Base name for checkbox input IDs and names.
     * @return string HTML table string, or "No files available" if the result set is empty.
     */
    public static function getImageFileListAsCheckBoxTable(PDODatabase $objDB, PDOStatement $files, string $name): string
    {
        if ($files->rowCount() == 0) return "No files available";

        $str  = "<table class=\"table table-sm\">";
        $str .= "<tr class=\"small\">"
              . "<th width=\"10%\">Select</th>"
              . "<th width=\"50%\">File</th>"
              . "<th width=\"35%\">Name</th>"
              . "<th width=\"15%\">Ext</th>"
              . "</tr>";

        $c = 0;
        while ($file = $files->fetch()) {
            $fileObj = new File($objDB, $file['id']);
            $fid     = $fileObj->getID();
            $type    = $fileObj->getType();

            $href  = "profile.html?uid=" . $fileObj->getUserID() . "&&fileDownload=$fid";
            $href2 = "image.html?viewImage=$fid";

            if (!strcmp($type, "image")) {
                // Embed thumbnail inline; only image-type files are included in this table.
                $imageData = file_get_contents($fileObj->getThumbNailPath());
                $img       = "<img src=\"$imageData\" class=\"img-thumbnail\" style=\"max-height:50px;\">";
                $img       = htmlPage::makeLink($href2, $img, "viewImage_$fid");

                $str .= "<tr class=\"small\">"
                      . "<td><input type=\"checkbox\" id=\"{$name}[{$c}]\" name=\"{$name}[{$c}]\" value=\"{$file['id']}\"></td>"
                      . "<td>$img</td>"
                      . "<td><a href=\"$href\">" . $fileObj->getName() . "</a></td>"
                      . "<td>" . $fileObj->getFileExtension() . "</td>"
                      . "</tr>";
                $c++;
            }
        }

        $str .= "</table>";
        return $str;
    }
}
?>