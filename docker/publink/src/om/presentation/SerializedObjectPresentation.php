<?php
namespace Biblhertz\Publink\om\presentation;

use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\om\presentation\TaskPresentation;
use Biblhertz\Publink\components\BootstrapRadioButton;
use Biblhertz\Publink\components\BootstrapButton;
use Biblhertz\Publink\pages\htmlPage;
use ReflectionClass;
use Biblhertz\Article\om\presentation\ReferencePresentation;
use Biblhertz\Article\om\ArticleObject;
use PDOStatement;
use Biblhertz\Publink\utilities\PDODatabase;

/**
 * SerializedObjectPresentation
 *
 * Presentation layer for SerializedObject instances in the PubLink article
 * object model. Uses PHP Reflection to introspect object properties at runtime,
 * allowing generic edit forms and display tables to be generated without
 * requiring object-specific rendering code.
 *
 * Rendering methods provided:
 *
 * | Method                          | Output              | Purpose                              |
 * |---------------------------------|---------------------|--------------------------------------|
 * | getAsTable()                    | Bootstrap table     | Minimal ID/name summary (inherited)  |
 * | getAsForm()                     | POST form           | Reflected edit form for any object   |
 * | getObjectAsTable()              | Bootstrap table     | Reflected read/view table            |
 * | getObjectDeleteForm()           | POST form table     | Delete buttons for object arrays     |
 * | getObjectListAsTable()          | DataTable           | Object list with optional delete     |
 * | getObjectListAsRadioButtonTable()| Task form table    | Radio button object selector         |
 * | getObjectListAsCheckBoxTable()  | Task form table     | Checkbox object selector             |
 *
 * Reflection-based methods respect two optional interface methods on the target object:
 * - isReadOnly()          — if present, overrides the $readOnly parameter.
 * - getDisallowedFields() — if present, returns field names to exclude from rendering.
 *
 * @package Biblhertz\Publink\om\presentation
 * @author  Chris Tomlinson
 * @since   June 2023
 */
class SerializedObjectPresentation extends SerializedObject
{

    /********************************************************************/
    /*  INHERITED INSTANCE METHODS                                      */
    /********************************************************************/

    /**
     * Render this serialized object as a minimal Bootstrap bordered table
     * showing its ID and name.
     *
     * Implements the getAsTable() contract inherited from SerializedObject.
     *
     * @return string HTML table string.
     */
    public function getAsTable(): string
    {
        $name=htmlspecialchars($this->getName(), ENT_QUOTES, 'UTF-8');
        return "<table class=\"table table-bordered\"><tr><th>ID</th><th>" . $name . "</th></tr></table>";
    }


    /********************************************************************/
    /*  STATIC REFLECTION-BASED RENDERING                               */
    /********************************************************************/

    /**
     * Render an ArticleObject as an editable POST form using PHP Reflection.
     *
     * Reflects over all non-static, non-id properties of $object and generates
     * a form row for each via getFormElement(). Properties that are arrays or
     * objects are skipped. Properties listed in getDisallowedFields() (if the
     * method exists on $object) are also excluded.
     *
     * The form is only rendered when the object's static ALLOW_EDIT property
     * is true; otherwise only the submit button wrapper is generated.
     *
     * When $add is a non-false value (typically a class name string), the button
     * is labelled "Add" and a hidden `classType` input is appended so the handler
     * knows which class to instantiate. Otherwise the button is labelled "Save".
     *
     * @param ArticleObject $object    The object to render as a form.
     * @param string        $target    Form action URL.
     * @param int           $id        Object ID, written as a hidden `oid` input.
     * @param mixed         $add       False for edit mode, or a class name string for add mode.
     * @param bool          $readOnly  When true, all inputs are rendered disabled. May be
     *                                 overridden by $object->isReadOnly() if that method exists.
     * @return string HTML form string.
     */
    public static function getAsForm(ArticleObject $object, string $target, int $id, mixed $add = false, bool $readOnly = false): string
    {
        $reflect = new ReflectionClass($object);
        $props   = $reflect->getProperties();
        $canEdit = $reflect->getProperty('ALLOW_EDIT')->getValue();
        $str     = "";

        // Allow the object itself to declare read-only status, overriding the parameter.
        if (method_exists($object, "isReadOnly")) {
            $readOnly = $object->isReadOnly();
        }

        // Retrieve any fields the object explicitly excludes from editing.
        if (method_exists($object, "getDisallowedFields")) {
            $disallowedFields = $object->getDisallowedFields();
        } else {
            $disallowedFields = array();
        }

        if ($canEdit) {
            $str = '<table class="table table-sm table-bordered small">';
            foreach ($props as $prop) {
                // Skip array/object properties, static properties, the id field,
                // and any fields the object has marked as disallowed.
                if (!is_array($prop->getValue($object)) && !is_object($prop->getValue($object))) {
                    if (!$prop->isStatic() && strcmp($prop->getName(), "id") && !in_array($prop->getName(), $disallowedFields)) {
                        $str .= SerializedObjectPresentation::getFormElement($prop->getName(), $prop->getValue($object), $readOnly);
                    }
                }
            }
            $str .= '</table>';
        }

        $dis        = $readOnly ? ' disabled' : '';
        $btnName    = $add ? 'addObject' : 'saveSerializedObject';
        $btnLabel   = $add ? 'Add' : 'Save';
        $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');

        $head = '<form method="POST" action="' . $safeTarget . '" class="p-1">'
              . '<input type="hidden" name="oid" value="' . $id . '" />';

        // In add mode, include the target class name so the handler can instantiate it.
        if ($add) $head .= '<input type="hidden" name="classType" value="' . htmlspecialchars($add, ENT_QUOTES, 'UTF-8') . '" />';

        $tail = '<div class="mt-3"><button type="submit" name="' . $btnName . '" class="btn btn-sm btn-primary"' . $dis . '>'
              . $btnLabel . '</button></div></form>';

        return $head . $str . $tail;
    }

    /**
     * Render an ArticleObject as a read-only Bootstrap table using PHP Reflection.
     *
     * Reflects over all non-static, non-id properties and renders each as a
     * table row. Properties listed in getDisallowedFields() are excluded.
     *
     * Two property names receive special link treatment:
     * - `pubId` — linked to PubMed or DOI resolver depending on getPubIdType().
     * - `uri`   — linked as a plain hyperlink opening in a new tab.
     *
     * @param ArticleObject $object The object to display.
     * @return string HTML Bootstrap table string.
     */
    public static function getObjectAsTable(ArticleObject $object): string
    {
        if (method_exists($object, "getDisallowedFields")) {
            $disallowedFields = $object->getDisallowedFields();
        } else {
            $disallowedFields = array();
        }

        $reflect = new ReflectionClass($object);
        $props   = $reflect->getProperties();
        $str     = "<table class=\"table table-bordered table-sm w-100\">";

        // Pre-resolve the external link base URL for pubId fields, if applicable.
        $link = null;
        if (method_exists($object, "getPubIdType")) {
            $type = $object->getPubIdType();
            if (!strcmp($type, "pmid"))     $link = "https://pubmed.ncbi.nlm.nih.gov/";
            elseif (!strcmp($type, "doi"))  $link = "https://doi.org/";
        }

        foreach ($props as $prop) {
            if (!is_array($prop->getValue($object)) && !is_object($prop->getValue($object))) {
                if (!$prop->isStatic() && strcmp($prop->getName(), "id") && !in_array($prop->getName(), $disallowedFields)) {
                    $escapedValue = htmlspecialchars((string)$prop->getValue($object), ENT_QUOTES, 'UTF-8');
                    $escapedKey   = htmlspecialchars($prop->getName(), ENT_QUOTES, 'UTF-8');

                    // Wrap pubId and uri values in appropriate hyperlinks; render note as raw HTML.
                    if (!strcmp($prop->getName(), "pubId") && isset($link)) {
                        $display = "<a href=\"$link$escapedValue\" target=\"_blank\">$escapedValue</a>";
                    } elseif (!strcmp($prop->getName(), "uri")) {
                        $display = "<a href=\"$escapedValue\" target=\"_blank\">$escapedValue</a>";
                    } elseif (!strcmp($prop->getName(), "note")) {
                        $display = (string) $prop->getValue($object);
                    } else {
                        $display = $escapedValue;
                    }
                    if(!empty($escapedValue)){
                        $str .= "<tr><td width=\"15%\">$escapedKey</td>"
                            . "<th style=\"word-wrap:break-word; word-break:break-all;\">$display</th></tr>";
                    }
                }
            }
        }

        $str .= "</table>";
        return $str;
    }

    /**
     * Render an array of objects as a table of delete forms.
     *
     * Each object is displayed via getObjectAsTable() alongside a "Delete" button.
     * The form posts to $target with hidden inputs for the item's JATS ID, the
     * parent object ID, and the class type, allowing the handler to identify and
     * remove the correct item from the serialized object model.
     *
     * Returns a "No files available" message if $items is empty or falsy.
     *
     * @param array  $items    Array of objects exposing getJatsID(). Null items are skipped.
     * @param string $target   Form action URL.
     * @param int    $oid      Parent object ID, passed as a hidden `oid` input.
     * @param string $class    Class name of the items, passed as a hidden `classType` input.
     * @param bool   $readOnly When true, the Delete button is rendered disabled.
     * @return string HTML table string of delete forms, or "<p>No files available</p>".
     */
    public static function getObjectDeleteForm(array $items, string $target, int $oid, string $class, bool $readOnly = false): string
    {
        if (!$items || count($items) == 0) return "<p>No files available</p>";

        $button = new BootstrapButton();
        $button->setName("removeObject");
        $button->setValue("Delete");
        if ($readOnly) $button->setDisabled(true);

        $str = "<table class=\"table table-bordered table-sm\">";

        $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
        $safeClass  = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');

        foreach ($items as $item) {
            if ($item) {
                $str .= "<tr><form method=\"POST\" action=\"$safeTarget\">"
                      . "<input type=\"hidden\" name=\"itemid\" value=\"" . htmlspecialchars((string)$item->getJatsID(), ENT_QUOTES, 'UTF-8') . "\" />"
                      . "<input type=\"hidden\" name=\"oid\" value=\"$oid\" />"
                      . "<input type=\"hidden\" name=\"classType\" value=\"$safeClass\" />"
                      . "<td>" . SerializedObjectPresentation::getObjectAsTable($item) . "</td>"
                      . "<td>" . $button->getComponent() . "</td>"
                      . "</form></tr>";
            }
        }

        return $str . "</table>";
    }

    /**
     * Render a DataTable-enhanced list of serialized objects from a PDOStatement.
     *
     * Each row shows a generic object icon, the object name linked to the
     * appropriate detail page (article.html for Article type, referenceCollection.html
     * for all others), the object type, and an upload timestamp. An optional
     * delete button triggers the JavaScript `delObj(fid)` function which must
     * be defined in the calling page.
     *
     * Note: The DataTable initialisation script calls DataTable() twice — once
     * inside $(document).ready() and once immediately after. The second call is
     * redundant. The ready() call alone is sufficient.
     *
     * @param PDOStatement $objects Result set of object rows ('id', 'type', 'name', 'timestamp').
     * @param PDODatabase  $objDB   Active database connection (currently unused in this method).
     * @param bool         $delete  When true, adds a delete button column. Defaults to false.
     * @return string HTML string of the table, DataTable init script, and wrapper div.
     */
    public static function getObjectListAsTable(PDOStatement $objects, PDODatabase $objDB, bool $delete = false): string
    {
        $tableId = uniqid("table_");
        $str  = "<table class=\"table table-sm responsive\" id=\"$tableId\">";
        $str .= "<thead><tr class=\"small\"><th></th><th>Object</th><th>Type</th><th>Uploaded</th>";
        if ($delete) $str .= "<th></th>";
        $str .= "</tr></thead><tbody>";

        while ($object = $objects->fetch()) {
            $fid  = (int)$object['id'];
            $type = htmlspecialchars((string)$object['type'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars((string)$object['name'], ENT_QUOTES, 'UTF-8');

            $img  = htmlspecialchars(htmlPage::getImageRoot() . "/obj.png", ENT_QUOTES, 'UTF-8');
            $img  = "<img src=\"$img\" height=50 />";

            // Route the object name link to the appropriate detail page by type.
            $href = ($object['type'] === 'Article')
                ? "article.html?oid=$fid"
                : "referenceCollection.html?oid=$fid";

            $str .= "<tr class=\"small\"><td>$img</td>"
                  . "<td><a data-toggle=\"tooltip\" data-placement=\"top\" title=\"\" href=\"$href\">"
                  . $name . "</a></td>"
                  . "<td>$type</td>";

            if (isset($object['timestamp'])) {
                $timestamp = htmlPage::getTimeStampAsDateTimeArray($object['timestamp']);
                $date      = htmlspecialchars((string)$timestamp[0], ENT_QUOTES, 'UTF-8');
                $time      = htmlspecialchars((string)$timestamp[1], ENT_QUOTES, 'UTF-8');
                $str .= "<td>$date @ $time</td>";
            } else {
                $str .= "<td></td>";
            }

            $lid = "objlink_" . $fid;
            if ($delete) {
                $str .= "<td><a href=\"javascript:void(0)\" class=\"btn btn-danger btn-sm\" id=\"$lid\" onclick=\"delObj($fid)\">Del</a></td>";
            }

            $str .= "</tr>";
        }

        $str .= "</tbody></table>";
        $str .= "<script>
            $(document).ready(function() {
                $('#$tableId').DataTable({paging: true, destroy: true});
            });
        </script>";

        return "<div style=\"font-size:12px;\">" . $str . "</div>";
    }

    /**
     * Render a radio button object selection table for use inside a task submission form.
     *
     * When $button is true, wraps the table in a POST form with a submit button
     * hidden on load and revealed by the `<codeName>_onclick()` JavaScript function.
     * When $func is false, the onClick handler is omitted from the radio buttons,
     * so the button must be shown by other means.
     *
     * The radio button value is the object's database ID. Each row links the
     * object name to article.html?oid=<id>.
     *
     * @param PDOStatement     $files            Result set of object rows ('id', 'name').
     * @param TaskPresentation $taskpresentation Task context providing code name, action, and button text.
     * @param bool             $button           When true, wraps output in a form with submit button. Defaults to true.
     * @param bool             $func             When true, attaches onClick handler to radio buttons. Defaults to true.
     * @return string HTML string of the radio button table (and optional form wrapper + script).
     */
    public static function getObjectListAsRadioButtonTable(PDOStatement $files, TaskPresentation $taskpresentation, bool $button = true, bool $func = true): string
    {
        $task     = $taskpresentation->getTask();
        $name     = $task->getCodeName();
        $text     = $task->getActionText();
        $action   = $task->getActionHandler();
        $buttonid = $name . "_button";
        $funcid   = $task->getCodeName() . "_onclick()";
        $str      = "";

        $safeAction   = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $safeText     = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $safeButtonId = htmlspecialchars($buttonid, ENT_QUOTES, 'UTF-8');

        if ($button) {
            $str = "<form action=\"$safeAction\" method=\"POST\">"
                 . htmlPage::makeHiddenInput("task_id", $task->getID());
        }

        $str .= "<table class=\"table table-sm\">";
        $str .= "<tr class=\"small\"><th width=\"15%\">Select</th><th width=\"85%\">File</th></tr>";

        while ($file = $files->fetch()) {
            $fid      = (int)$file['id'];
            $fileName = htmlspecialchars((string)$file['name'], ENT_QUOTES, 'UTF-8');

            $radioButton = new BootstrapRadioButton();
            $radioButton->setGroupName($name);
            $radioButton->setName($name);
            $radioButton->setValue($fid);
            if ($func) $radioButton->setOnClick($funcid);

            $str .= "<tr class=\"small\"><td>" . $radioButton->getComponent() . "</td>";
            $str .= "<td><a href=\"article.html?oid=$fid\">$fileName</a></td></tr>";
        }

        $str .= "</table>";

        if ($button) {
            $str .= "<div class=\"d-grid gap-2 d-md-flex justify-content-md-end\">"
                  . "<button class=\"btn btn-outline-primary\" name=\"$safeButtonId\" id=\"$safeButtonId\" type=\"SUBMIT\">$safeText</button>"
                  . "</div></form>";
            $str .= "<script type=\"text/javascript\">"
                  . "\$( document ).ready(function() { \$(\"#$buttonid\").hide(); }); "
                  . "function $funcid { \$(\"#$buttonid\").show(); }"
                  . "</script>";
        }

        return $str;
    }

    /**
     * Render a checkbox object selection table for use inside a task submission form.
     *
     * When $button is true, wraps the table in a POST form with a submit button
     * hidden on load, revealed only when at least one checkbox is checked.
     * Checkbox names follow the pattern `<codeName>_cb[]`.
     *
     * Note: The checkbox `id` and `name` attributes are missing surrounding quotes
     * in the original (e.g. `id=cbid[]` rather than `id="cbid[]"`), which produces
     * invalid HTML. These should be quoted.
     *
     * @param PDOStatement     $files            Result set of object rows ('id', 'name', 'user_details_id').
     * @param TaskPresentation $taskpresentation Task context providing code name, action, and button text.
     * @param bool             $button           When true, wraps output in a form with submit button. Defaults to true.
     * @return string HTML string of the checkbox table (and optional form wrapper + script).
     */
    public static function getObjectListAsCheckBoxTable(PDOStatement $files, TaskPresentation $taskpresentation, bool $button = true): string
    {
        $task     = $taskpresentation->getTask();
        $name     = $task->getCodeName();
        $text     = $task->getActionText();
        $action   = $task->getActionHandler();
        $buttonid = $name . "_button";
        $funcid   = $name . "_onclick()";
        $cbid     = $name . "_cb";
        $str      = "";

        $safeAction   = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $safeText     = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $safeButtonId = htmlspecialchars($buttonid, ENT_QUOTES, 'UTF-8');
        $safeCbId     = htmlspecialchars($cbid, ENT_QUOTES, 'UTF-8');

        if ($button) {
            $str = "<form action=\"$safeAction\" method=\"POST\">"
                 . htmlPage::makeHiddenInput("task_id", $task->getID());
        }

        $str .= "<table class=\"table table-sm\">";
        $str .= "<tr class=\"small\"><th width=\"15%\">Select</th><th width=\"85%\">Object</th></tr>";

        // onClick is only wired when the button visibility is managed by this method.
        $onclick = $button ? "onclick=\"$funcid\"" : "";

        while ($file = $files->fetch()) {
            $fid = (int)$file['id'];
            $uid = (int)$file['user_details_id'];
            $displayName = (string)$file['name'];
            if (strlen($displayName) > 50) $displayName = substr($displayName, 0, 50);
            $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');

            $str .= "<tr class=\"small\"><td>"
                  . "<input type=\"checkbox\" id=\"{$safeCbId}[]\" name=\"{$safeCbId}[]\" value=\"$fid\" $onclick />"
                  . "</td>";
            $str .= "<td><a href=\"user.html?uid=$uid&amp;fileDownload=$fid\">"
                  . $safeName . "</a></td></tr>";
        }

        $str .= "</table>";

        if ($button) {
            $str .= "<div class=\"d-grid gap-2 d-md-flex justify-content-md-end\">"
                  . "<button class=\"btn btn-outline-primary\" name=\"$safeButtonId\" id=\"$safeButtonId\" type=\"SUBMIT\">$safeText</button>"
                  . "</div></form>";
            $str .= "<script type=\"text/javascript\">"
                  . "\$( document ).ready(function() { \$(\"#$buttonid\").hide(); }); "
                  . "function $funcid {"
                  . "var total = \$('input[name=\"{$cbid}[]\"]').filter(':checked').length;"
                  . "if (total > 0) \$(\"#$buttonid\").show(); else \$(\"#$buttonid\").hide();"
                  . "}</script>";
        }

        return $str;
    }


    /********************************************************************/
    /*  PRIVATE HELPERS                                                 */
    /********************************************************************/

    /**
     * Render a single form row for a reflected object property.
     *
     * Chooses the input component based on value length:
     * - Values shorter than 200 characters use a BootstrapInput (max 100 chars, width 80).
     * - Longer values use a textarea (10 rows, 100 columns).
     *
     * Returns an empty string for the value if $value is null.
     *
     * @param string $name     Property name, used as both the row label and input name.
     * @param mixed  $value    Current property value. Null is normalised to an empty string.
     * @param bool   $readOnly When true, the input is rendered as read-only/disabled.
     * @return string HTML <tr> string containing the label cell and input cell.
     */
    private static function getFormElement(string $name, mixed $value, bool $readOnly = false): string
    {
        if (!isset($value)) $value = "";

        $safeName  = htmlspecialchars($name,          ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $dis       = $readOnly ? ' disabled' : '';

        if (strlen((string)$value) < 200) {
            $input = '<input type="text" class="form-control form-control-sm" name="' . $safeName . '" value="' . $safeValue . '" maxlength="255"' . $dis . '>';
        } else {
            $input = '<textarea class="form-control form-control-sm" name="' . $safeName . '" rows="4"' . $dis . '>' . $safeValue . '</textarea>';
        }

        return '<tr><td class="text-nowrap align-middle fw-bold" style="width:1%;font-weight:bold">' . $safeName . '</td><td>' . $input . '</td></tr>';
    }
}