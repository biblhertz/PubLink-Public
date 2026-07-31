<?php

namespace Biblhertz\Article\om\presentation;

use Biblhertz\Article\om\GalleyFile;
use Biblhertz\Article\om\Article;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\presentation\SerializedObjectPresentation;
use Biblhertz\Publink\components\BootstrapRadioButton;
use Biblhertz\Publink\components\BootstrapButton;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Publink\pages\Bibliotheca_Page;
use Biblhertz\Publink\utilities\PDODatabase;
use PDOStatement;

/********************************************************************/
/*  GalleyFilePresentation                                          */
/*                                                                  */
/*  Author  :   Chris Tomlinson                                     */
/*  Date    :   10th July 2023                                      */
/*                                                                  */
/*  Presentation class for GalleyFile objects.                      */
/*  Renders galley file data as HTML tables and forms for the       */
/*  PubLink editorial interface, covering inline edit/delete        */
/*  forms, radio-button file selectors, and the galley attach       */
/*  form with dependent-file support.                               */
/********************************************************************/

/**
 * Renders a GalleyFile object as HTML UI components.
 *
 * Instance methods operate on the GalleyFile supplied to the
 * constructor. Static methods are utility builders that do not
 * require an existing instance (file-list tables, attach forms,
 * genre pulldowns).
 *
 * @package Biblhertz\Article\om\presentation
 */
class GalleyFilePresentation {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var GalleyFile The galley file rendered by this instance. */
    private GalleyFile $galleyFile;


    /****************************************************************/
    /*  CONSTRUCTOR                                                 */
    /****************************************************************/

    /**
     * @param GalleyFile $galleyFile The galley file to present.
     */
    public function __construct(GalleyFile $galleyFile) {
        $this->galleyFile = $galleyFile;
    }


    /****************************************************************/
    /*  INSTANCE PRESENTATION METHODS                               */
    /****************************************************************/

    /**
     * Returns a read-only HTML table summarising the galley file's
     * key properties: file name, alt text, MIME type, and genre.
     *
     * @return string HTML <table> markup.
     */
    public function getAsTable(): string {
        $str  = "<table class=\"table table-bordered table-sm small\">";
        $str .= "<tr><td class=\"text-nowrap fw-bold\" style=\"width:1%\">Name</td><td>"     . htmlspecialchars($this->galleyFile->getGalleyFileName(),    ENT_QUOTES, 'UTF-8') . "</td></tr>";
        $str .= "<tr><td class=\"text-nowrap fw-bold\" style=\"width:1%\">Alt Text</td><td>" . htmlspecialchars($this->galleyFile->getGalleyFileAltText(), ENT_QUOTES, 'UTF-8') . "</td></tr>";
        $str .= "<tr><td class=\"text-nowrap fw-bold\" style=\"width:1%\">Type</td><td>"     . htmlspecialchars($this->galleyFile->getGalleyFileType(),    ENT_QUOTES, 'UTF-8') . "</td></tr>";
        $str .= "<tr><td class=\"text-nowrap fw-bold\" style=\"width:1%\">Genre</td><td>"    . htmlspecialchars($this->galleyFile->getGenre(),             ENT_QUOTES, 'UTF-8') . "</td></tr>";
        $str .= "</table>";
        return $str;
    }


    /**
     * Returns an HTML table containing two inline forms for this galley file:
     *
     *  1. **Update form** – editable fields for alt text, display name, locale,
     *     and genre (or parent file name for dependent files). Posts with
     *     `updateGalley` = the button name.
     *  2. **Remove form** – a single "Remove Galley File" button. Posts with
     *     `removeGalley` = the button name.
     *
     * For dependent files the genre cell shows the genre constant plus the
     * resolved parent galley name instead of a genre pulldown.
     * When $readOnly is true the Update button is disabled.
     *
     * Both forms carry hidden fields `itemid` (the galley's JATS ID) and
     * `oid` (the serialized object ID) so the receiving page can identify
     * the record to modify.
     *
     * @param string $target    Form post-back URL.
     * @param int    $oid       Serialized object ID to include in both forms.
     * @param bool   $readOnly  When true, the update button is disabled.
     * @param bool   $dependant Reserved parameter; not currently used internally
     *                          (dependency is detected via GalleyFile::$DEPENDANT_GENRE).
     *
     * @return string HTML markup for the combined edit/delete table.
     */
    public function getEditOrDeleteForm(string $target, int $oid, bool $readOnly, bool $dependant = false): string {

        $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
        $safeJatsID = htmlspecialchars($this->galleyFile->getJatsID(), ENT_QUOTES, 'UTF-8');
        $dis        = $readOnly ? ' disabled' : '';
        $f          = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $label      = fn(string $t) => '<label class="form-label small text-muted mb-0">' . $t . '</label>';
        $input      = fn(string $name, string $val, int $maxlen = 255) =>
            '<input type="text" class="form-control form-control-sm" name="' . $name . '"'
            . ' value="' . $f($val) . '" maxlength="' . $maxlen . '"' . $dis . '>';

        // Genre cell; parent row (dependent files only)
        $parentRowHtml = '';
        if ($this->galleyFile->getGenre() === GalleyFile::$DEPENDANT_GENRE) {
            $parent        = $this->galleyFile->getArticle()->getGalleyFileByJatsID($this->galleyFile->getParent());
            $parentName    = $parent ? ($parent->getName() ?: $parent->getGalleyFileName()) : '';
            $genreHtml     = '<span class="form-control-plaintext form-control-sm">' . $f(GalleyFile::$DEPENDANT_GENRE) . '</span>';
            $parentRowHtml = '<div class="mb-2">'
                           .   $label('Parent file')
                           .   '<p class="form-control-plaintext form-control-sm mb-0">'
                           .     ($parentName ? $f($parentName) : '<span class="text-muted fst-italic">unknown</span>')
                           .   '</p>'
                           . '</div>';
        } else {
            $genreHtml = str_replace('<select ', '<select class="form-select form-select-sm" ', self::getAllowedGenresAsPullDown("genre", $this->galleyFile->getGenre(), [GalleyFile::$DEPENDANT_GENRE]));
        }

        $str  = '<form method="POST" action="' . $safeTarget . '">'
              . '<input type="hidden" name="itemid" value="' . $safeJatsID . '">'
              . '<input type="hidden" name="oid"    value="' . $oid . '">'
              . '<div class="row g-2 mb-2">'
              .   '<div class="col-12">' . $label('File name') . '<p class="form-control-plaintext form-control-sm mb-0">' . $f($this->galleyFile->getGalleyFileName()) . '</p></div>'
              . '</div>'
              . $parentRowHtml
              . '<div class="d-flex align-items-end gap-2 mb-2">'
              .   '<div class="flex-grow-1">'           . $label('Display name') . $input('name',   $this->galleyFile->getName(),   80) . '</div>'
              .   '<div style="min-width:5rem">'        . $label('Locale')       . $input('locale', $this->galleyFile->getLocale(),  2) . '</div>'
              .   '<div style="min-width:10rem">'       . $label('Genre')        . $genreHtml . '</div>'
              . '</div>'
              . '<div class="mb-2">' . $label('Alt text') . $input('alt_text', $this->galleyFile->getGalleyFileAltText(), 255) . '</div>'
              . '<div class="d-flex gap-2">'
              .   '<button type="submit" name="updateGalley" class="btn btn-sm btn-primary"' . $dis . '>Save</button>'
              . '</form>'
              . '<form method="POST" action="' . $safeTarget . '" class="d-inline">'
              .   '<input type="hidden" name="itemid" value="' . $safeJatsID . '">'
              .   '<input type="hidden" name="oid"    value="' . $oid . '">'
              .   '<button type="submit" name="removeGalley" class="btn btn-sm btn-outline-danger"'
              .       ($readOnly ? ' disabled' : '') . '>Remove</button>'
              . '</form>'
              . '</div>';

        return $str;
    }


    /****************************************************************/
    /*  STATIC METHODS                                              */
    /****************************************************************/

    /**
     * Renders a paginated radio-button table of files from a PDOStatement
     * result set, wrapped in a form for selecting and attaching a galley file.
     *
     * The submit button is hidden on page load and revealed via an onClick
     * JavaScript handler attached to each radio button, preventing accidental
     * submissions before a file is chosen.
     *
     * The form posts `oid`, `addGalleyFile` = "true", and the selected
     * file's database ID as `galley_file`.
     *
     * Note: the `image` type branch references an undefined `$fileObj` variable
     * (likely a pre-existing bug); non-image files fall through to the icon path.
     *
     * @param PDOStatement $files  Result set containing file rows with at least:
     *                             id, name, type, icon, file_extension, user_details_id.
     * @param string       $action Form action URL.
     * @param int          $oid    Serialized object ID posted with the form.
     *
     * @return string HTML form markup, or "<p>No files returned</p>" if $files is falsy.
     */
    public static function getGalleyFileListAsRadioButtonTable(PDOStatement $files, string $action, int $oid): string {

        if (!$files) return "<p>No files returned</p>";

        $name     = "galley_file";
        $text     = "Add Galley File";
        $buttonid = $name . "_button";
        $funcid   = $name . "_onclick()";
        $formid   = $name . "_form";

        $safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $str  = "<form action=\"$safeAction\" method=\"POST\" id=\"$formid\">";
        $str .= htmlPage::makeHiddenInput("oid", $oid);
        $str .= htmlPage::makeHiddenInput("addGalleyFile", "true");
        $str .= "<table class=\"table table-sm\">";
        $str .= "<tr class=\"small\"><th width=\"15%\">Select</th><th width=\"70%\">File</th><th width=\"15%\">Ext</th></tr>";

        while ($file = $files->fetch()) {
            $radioButton = new BootstrapRadioButton();
            $radioButton->setGroupName($name);
            $radioButton->setName($name);
            $radioButton->setValue($file['id']);
            $radioButton->setOnClick($funcid);
            $str .= "<tr class=\"small\"><td>" . $radioButton->getComponent() . "</td>";

            $type = $file['type'];
            if ($type === "image") {
                $imageData = file_get_contents($file['thumbnail_path']);
                $img       = ($imageData !== false)
                           ? "<img src=\"data:image;base64," . base64_encode($imageData) . "\" class=\"img-thumbnail\" style=\"max-height:50px; \">"
                           : "";
            } else {
                $img = htmlspecialchars(htmlPage::getImageRoot() . $file['icon'], ENT_QUOTES, 'UTF-8');
                $img = "<img src=\"$img\" height=50 />";
            }

            $safeUid  = (int)$file['user_details_id'];
            $safeId   = (int)$file['id'];
            $str .= "<td><a href=\"profile.html?uid=$safeUid&&fileDownload=$safeId\">"
                  . htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') . "</a></td>"
                  . "<td>$img</td>"
                  . "<td>" . htmlspecialchars($file['file_extension'], ENT_QUOTES, 'UTF-8') . "</td></tr>";
        }

        $str .= "</table>";
        $str .= "<div class=\"d-grid gap-2 d-md-flex justify-content-md-end\">"
              . "<button class=\"btn btn-outline-primary\" name=\"$buttonid\" id=\"$buttonid\" type=\"SUBMIT\">$text</button>"
              . "</div></form>";

        // Hide submit button until a radio button is selected
        $str .= "<script type=\"text/javascript\">"
              . "\$( document ).ready(function() {\$( \"#$buttonid\").hide();});"
              . " function " . $funcid . "{\$( \"#" . $buttonid . "\").show();}"
              . "</script>";

        return $str;
    }


    /**
     * Builds the form used to attach an existing file from the user's file store
     * as a new galley file on an article.
     *
     * The form contains:
     *  - A dropdown of files owned by $uid that are not already attached to the
     *    article and are not of type "log".
     *  - A genre pulldown (excluding the dependent genre).
     *  - A "Parent Galley File" row that is hidden by default and shown via
     *    JavaScript when the dependent genre is selected.
     *  - A submit button.
     *
     * The form posts `oid`, `addGalleyFile` = "true", `galley_file` (selected
     * file ID), `non_dependent_genre`, and optionally `parent_galley_file`.
     *
     * Returns "No files available" when the user has no eligible files.
     *
     * @param Article $article The article to attach the file to (used to exclude
     *                         already-attached files and build the parent list).
     * @param int     $uid     User ID; used to scope the file query.
     * @param string  $action  Form action URL.
     * @param int     $oid     Serialized object ID posted with the form.
     *
     * @return string HTML form markup, or "No files available".
     */
    public static function getGalleyFileAttachForm(Article $article, int $uid, string $action, int $oid): string {

        $name     = "galley_file";
        $text     = "Add Galley File";
        $buttonid = $name . "_button";
        $funcid   = $name . "_onclick()";
        $formid   = $name . "_form";

        // Build a list of already-attached file IDs to exclude from the query
        $afiles     = $article->getGalleyFiles();
        $excludeIds = [];
        foreach ($afiles as $galley) {
            $fid = $galley->getFileID();
            if (is_int($fid)) $excludeIds[] = $fid;
        }

        if (empty($excludeIds)) {
            $files = Bibliotheca_Page::getObjDB()->preparedSelect(
                "select * from file where user_details_id = ? and file_type_id not in (select id from file_type where name = ?)",
                [$uid, 'log']
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $files = Bibliotheca_Page::getObjDB()->preparedSelect(
                "select * from file where user_details_id = ? and file.id not in ($placeholders) and file_type_id not in (select id from file_type where name = ?)",
                array_merge([$uid], $excludeIds, ['log'])
            );
        }

        if (Bibliotheca_Page::getObjDB()->numRows() == 0) {
            return "No files available";
        }

        // Build parent file dropdown from the article's non-dependent galleys owned by this user
        $afiles     = $article->getNonDependantGalleyFiles();
        $includeIds = [];
        foreach ($afiles as $galley) {
            $fid = $galley->getFileID();
            if (is_int($fid)) $includeIds[] = $fid;
        }

        if (empty($includeIds)) {
            $ndfiles = Bibliotheca_Page::getObjDB()->preparedSelect(
                "select * from file where 1=0",
                []
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($includeIds), '?'));
            $ndfiles = Bibliotheca_Page::getObjDB()->preparedSelect(
                "select * from file where user_details_id = ? and file.id in ($placeholders)",
                array_merge([$uid], $includeIds)
            );
        }

        $safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $label      = fn(string $t) => '<label class="form-label small text-muted mb-0">' . $t . '</label>';
        $select     = fn(string $html) => str_replace('<select ', '<select class="form-select form-select-sm" ', $html);

        $str  = '<form action="' . $safeAction . '" method="POST" id="' . $formid . '">'
              . htmlPage::makeHiddenInput("oid", $oid)
              . htmlPage::makeHiddenInput("addGalleyFile", "true")
              . '<div class="d-flex gap-3 align-items-end flex-wrap mb-2">'
              .   '<div>' . $label('File')  . $select(htmlPage::makeOption("galley_file", $files, "id", "name")) . '</div>'
              .   '<div>' . $label('Genre') . $select(self::getAllowedGenresAsPullDown("non_dependent_genre")) . '</div>'
              .   '<div>' . $label('&nbsp;') . '<button type="submit" name="addGalleyButton" class="btn btn-sm btn-primary">Add</button></div>'
              . '</div>'
              . '<div class="mb-2" id="parent_row" style="display:none">'
              .   $label('Parent galley file')
              .   $select(htmlPage::makeOption("parent_galley_file", $ndfiles, "id", "name"))
              . '</div>'
              . '</form>';

        // Show/hide parent row based on genre selection
        $str .= '<script type="text/javascript">$("#non_dependent_genre").change(function() {'
              . '  if ($(this).val() == ' . json_encode(GalleyFile::$DEPENDANT_GENRE) . ') $("#parent_row").show();'
              . '  else $("#parent_row").hide();'
              . '});</script>';

        return $str;
    }


    /**
     * Builds an HTML select element populated with the allowed galley genres,
     * optionally excluding specific genre values.
     *
     * Delegates to GalleyFile::getAllowedGenres() for the master genre list,
     * then filters out any entries present in $ignore before building the
     * option array passed to htmlPage::makeOptionFromArray().
     *
     * @param string $name     The `name` attribute of the rendered <select>.
     * @param mixed  $selected The value of the option to pre-select (0 = none).
     * @param array  $ignore   Genre names to omit from the pulldown
     *                         (e.g. [GalleyFile::$DEPENDANT_GENRE]).
     *
     * @return string HTML <select> markup.
     */
    public static function getAllowedGenresAsPullDown(string $name, mixed $selected = 0, array $ignore = []): string {
        $genres = GalleyFile::getAllowedGenres();
        $arr    = array();
        $c      = 0;
        foreach ($genres as $genre) {
            if (!in_array($genre, $ignore)) {
                $arr[$c][0] = $arr[$c][1] = $genre;
                $c++;
            }
        }
        return htmlPage::makeOptionFromArray($name, $arr, $selected);
    }


    /**
     * Renders an array of GalleyFile objects as a sortable, paginated
     * DataTables HTML table.
     *
     * Each row shows a file thumbnail (image files) or type icon (other files),
     * a download link, file extension, and upload timestamp. An optional delete
     * button column can be enabled; log files and files with linked objects are
     * exempt from deletion.
     *
     * The table is initialised as a jQuery DataTable with paging enabled.
     * DataTable is initialised twice (on document ready and immediately) to
     * handle both standard and deferred DOM insertion scenarios.
     *
     * @param array       $files   Array of GalleyFile objects to display.
     * @param PDODatabase $objDB   Database handle used to instantiate each File object.
     * @param bool        $delete  When true, appends a delete button column;
     *                             log files and linked files receive an empty cell.
     *
     * @return string HTML markup wrapped in a 12px font-size div.
     */
    public static function getFileListAsTable(array $files, PDODatabase $objDB, bool $delete = false): string {

        $tableId = uniqid("table_");
        $str     = "<table class=\"table table-sm responsive\" id=\"$tableId\">";
        $str    .= "<thead><tr class=\"small\"><th>File</th><th>File Name</th><th>Ext</th><th>Uploaded</th>";
        if ($delete) $str .= "<th></th>";
        $str .= "</tr></thead><tbody>";

        foreach ($files as $galley) {
            $fileObj = new File($objDB, $galley->getFileID());
            $fid     = $fileObj->getID();
            $type    = $fileObj->getType();

            $href  = "profile.html?uid=" . $fileObj->getUserID() . "&&fileDownload=$fid";
            $href2 = "image.html?viewImage=$fid";

            $safeName = htmlspecialchars($fileObj->getName(), ENT_QUOTES, 'UTF-8');
            $safeExt  = htmlspecialchars($fileObj->getFileExtension(), ENT_QUOTES, 'UTF-8');
            $safeHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');

            if ($type === "image") {
                // Inline base64 thumbnail for image files
                $imageData = file_get_contents($fileObj->getThumbNailPath());
                $img       = ($imageData !== false)
                           ? "<img src=\"data:image;base64," . base64_encode($imageData) . "\" class=\"img-thumbnail\" style=\"max-height:50px; \">"
                           : "";
                $img       = htmlPage::makeLink($href2, $img, "viewImage_$fid");
                $str      .= "<tr class=\"small\"><td>$img</td>"
                           . "<td><a href=\"$safeHref\">$safeName</a></td>"
                           . "<td>$safeExt</td>";
            } else {
                // Icon image for non-image file types
                $img  = htmlspecialchars(htmlPage::getImageRoot() . $fileObj->getIcon(), ENT_QUOTES, 'UTF-8');
                $img  = "<img src=\"$img\" height=50 />";
                $str .= "<tr class=\"small\"><td>$img</td>"
                      . "<td><a data-toggle=\"tooltip\" data-placement=\"top\" title=\"\" href=\"$safeHref\">$safeName</a></td>"
                      . "<td>$safeExt</td>";
            }

            // Timestamp column (only populated when $file['timestamp'] is set)
            if (isset($galley['timestamp'])) {
                $timestamp = htmlPage::getTimeStampAsDateTimeArray($galley['timestamp']);
                $date      = $timestamp[0];
                $time      = $timestamp[1];
                $str      .= "<td>$date @ $time</td>";
            } else {
                $str .= "<td></td>";
            }

            // Optional delete column — log files and linked objects are not deletable
            if ($delete) {
                $lid = "link_$fid";
                $log = ($fileObj->getFileExtension() === "log") ? 1 : 0;
                if ($fileObj->hasLinkedObject()) {
                    $str .= "<td></td>";
                } else {
                    $str .= "<td><a href=\"javascript:void(0)\" class=\"btn btn-danger btn-sm\" id=\"$lid\" onclick=\"delClick($fid,$log)\">Del</a></td>";
                }
            }

            $str .= "</tr>";
        }

        $str .= "</tbody></table>";

        // Initialise DataTable — called twice to handle both standard and deferred DOM insertion
        $str .= "<script>
        $(document).ready(function() {
            $('#$tableId').DataTable({paging: true, destroy: true});
        });
        $('#$tableId').DataTable({paging: true, destroy: true});
        </script>";

        return "<div style=\"font-size:12px;\">$str</div>";
    }

}
?>