<?php
namespace Biblhertz\Publink\annotation;

use Biblhertz\Publink\annotation\ImageAnnotation;
use Biblhertz\Publink\annotation\ImageCanvas;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Publink\pages\Bibliotheca_Page;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\components\BootstrapTabbedPanel;
use Biblhertz\Publink\utilities\PDODatabase;
use PDOStatement;

/**
 * ImageAnnotationPresentation
 *
 * Presentation layer for ImageAnnotation objects. Responsible for rendering
 * annotations as HTML — including Bootstrap tables, DataTable-enhanced lists,
 * tabbed canvas panels, Mirador viewer launcher buttons, and sharing/publication
 * forms.
 *
 * This class follows a mixed instance/static design:
 * - Instance methods operate on the single annotation passed to the constructor.
 * - Static methods accept result sets or arrays and generate multi-row UI components.
 *
 * All HTML output assumes a Bootstrap 4/5 + jQuery + DataTables environment.
 *
 * @package Biblhertz\Publink\annotation
 * @author  Chris Tomlinson
 * @since   July 2024
 */
class ImageAnnotationPresentation
{

    /********************************************************************/
    /*  STATIC VARIABLES                                                */
    /********************************************************************/

    /**
     * @var array Map of viewer names to their base URLs.
     *            Used when constructing deep-link URLs for external IIIF viewers.
     *            e.g. self::$VIEWERS['Theseus'] gives the Theseus viewer base URL.
     */
    public static $VIEWERS = ['Theseus' => 'https://theseusviewer.org'];


    /********************************************************************/
    /*  INSTANCE VARIABLES                                              */
    /********************************************************************/

    /** @var ImageAnnotation The annotation instance this presentation wraps. */
    protected ImageAnnotation $annotation;


    /********************************************************************/
    /*  CONSTRUCTOR                                                     */
    /********************************************************************/

    /**
     * Construct a presentation wrapper for a single annotation.
     *
     * @param ImageAnnotation $annotation The annotation to present.
     */
    public function __construct(ImageAnnotation $annotation)
    {
        $this->annotation = $annotation;
    }


    /********************************************************************/
    /*  INSTANCE METHODS                                                */
    /********************************************************************/

    /**
     * Return the wrapped ImageAnnotation instance.
     *
     * @return ImageAnnotation
     */
    public function getAnnotation(): ImageAnnotation
    {
        return $this->annotation;
    }

    /**
     * Render the annotation JSON as a syntax-highlighted, word-wrapped HTML block.
     *
     * Decodes the stored JSON, re-encodes it with pretty-printing, and wraps it
     * in a <pre> block with inline styles to prevent horizontal overflow.
     *
     * @return string HTML string containing the formatted annotation JSON.
     */
    public function prettyPrintAnnotation(): string
    {
        $annarr = json_decode($this->annotation->getAnnotation());
        $json   = htmlspecialchars(json_encode($annarr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return '<pre class="small mb-0" style="word-wrap:break-word;overflow-wrap:break-word;white-space:pre-wrap;max-width:100%">'
             . $json . '</pre>';
    }

    /**
     * Magic method — returns a brief string representation of this presentation object.
     *
     * @return string e.g. "Annotation ID :: 42"
     */
    public function __toString(): string
    {
        return "Annotation ID :: " . $this->annotation->getID();
    }

    /**
     * Render the annotation as a Bootstrap bordered table showing key metadata.
     *
     * Columns shown: Manifest (linked), Annotation Owner (full name), Canvas URI,
     * Specific Region Recipe (deep-link), and the pretty-printed annotation JSON.
     *
     * @param PDODatabase $objDB Active database connection, used to resolve the owner's full name.
     * @return string HTML string of the Bootstrap table.
     */
    public function getAsTable(PDODatabase $objDB): string
    {
        $user    = new User($objDB, $this->annotation->getUserID());
        $lbl     = '<td class="text-nowrap fw-bold align-top" style="width:1%;font-weight:bold;white-space:nowrap">';
        $viewId  = uniqid();
        $regionId = uniqid();
        $annId    = uniqid();

        $manifestLink = '<a href="' . htmlspecialchars($this->annotation->getManifest(), ENT_QUOTES) . '" target="' . $viewId . '">View Manifest</a>';

        $regionUrl  = htmlspecialchars($this->annotation->getSpecificRegionURL(), ENT_QUOTES);
        $regionJSON = htmlspecialchars($this->annotation->getSpecificRegionRecipe());
        $copyId     = uniqid('copy_');
        $regionCell = '<div class="d-flex flex-wrap gap-1 mb-1">'
                    .   '<a href="' . $regionUrl . '" target="' . $regionId . '" class="btn btn-sm btn-outline-secondary">View in Theseus</a>'
                    .   '<button id="' . $copyId . '" type="button" class="btn btn-sm btn-outline-secondary"'
                    .     ' onclick="(function(b,u){'
                    .       'navigator.clipboard.writeText(u).then(function(){'
                    .         'var t=b.innerHTML;b.innerHTML=\'<i class=\\\'fas fa-check\\\'></i> Copied\';'
                    .         'b.classList.replace(\'btn-outline-secondary\',\'btn-outline-success\');'
                    .         'setTimeout(function(){b.innerHTML=t;b.classList.replace(\'btn-outline-success\',\'btn-outline-secondary\');},1500);'
                    .       '});'
                    .     '})(document.getElementById(\'' . $copyId . '\'),\'' . $regionUrl . '\')">'
                    .     '<i class="fas fa-copy"></i> Copy link'
                    .   '</button>'
                    . '</div>'
                    . '<details class="mt-1"><summary class="small text-muted" style="cursor:pointer">JSON</summary>'
                    . '<pre class="small mt-1 mb-0" style="word-wrap:break-word;overflow-wrap:break-word;white-space:pre-wrap">' . $regionJSON . '</pre>'
                    . '</details>';

        $annCell = '<details><summary class="small text-muted" style="cursor:pointer">Show annotation JSON</summary>'
                 . $this->prettyPrintAnnotation()
                 . '</details>';

        return '<table class="table table-bordered table-sm small">'
            . "<tr>{$lbl}Manifest</td><td>{$manifestLink}</td></tr>"
            . "<tr>{$lbl}Owner</td><td>" . htmlspecialchars($user->getFullName()) . "</td></tr>"
            . "<tr>{$lbl}Specific Region</td><td>{$regionCell}</td></tr>"
            . "<tr>{$lbl}Annotation</td><td>{$annCell}</td></tr>"
            . '</table>';
    }


    /********************************************************************/
    /*  STATIC TABLE METHODS                                            */
    /********************************************************************/

    /**
     * Render a DataTable-enhanced HTML table of annotations for the admin/tools view.
     *
     * Iterates the result set, building a table row per annotation via
     * getAnnotationRow(). Returns an empty string if the result set is empty.
     * Initialises a jQuery DataTable (search/paging/info disabled) and Bootstrap
     * tooltips on DOM ready.
     *
     * @param PDOStatement $annotations Result set of annotation rows (must include 'id', 'canvas').
     * @param PDODatabase  $objDB       Active database connection.
     * @return string HTML string containing the table and inline <script>, or '' if no rows.
     */
    public static function getAnnotationTable(PDOStatement $annotations, PDODatabase $objDB): string
    {
        $tableId = uniqid("table_");
        $table   = "<table class=\"table table-sm responsive col-12\" id=\"$tableId\">";
        $first   = true;

        while ($a = $annotations->fetch()) {
            if ($first) {
                $table .= self::getTableHeader() . "<tbody>";
                $first  = false;
            }
            $table .= self::getAnnotationRow($a, $objDB);
        }

        if ($first) return "";

        $table .= "</tbody></table>";

        $script = "<script>
            $(document).ready(function() {
                $('#$tableId').DataTable({searching: false, paging: false, info: false, destroy: true});
                $('[data-toggle=\"tooltip\"]').tooltip();
            });
        </script>";

        return "<div class=\"container\">$table $script</div>";
    }

    /**
     * Render the <thead> row for the annotation tools table.
     *
     * Columns: Canvas, Text, JSON (link), Manifest (link), Del (delete button).
     *
     * @return string HTML <thead> string.
     */
    public static function getTableHeader(): string
    {
        return "<thead><tr>
                    <th>Canvas</th>
                    <th>Text</th>
                    <th>JSON</th>
                    <th>Manifest</th>
                    <th>Del</th>
                </tr></thead>";
    }

    /**
     * Render a single <tr> for the annotation tools table.
     *
     * Displays the canvas URI, annotation text, links to the JSON and manifest
     * tool pages, and an inline delete form.
     *
     * @param array       $ann   Associative row array from the DB (must include 'id', 'canvas').
     * @param PDODatabase $objDB Active database connection.
     * @return string HTML <tr> string.
     */
    public static function getAnnotationRow(array $ann, PDODatabase $objDB): string
    {
        $anno = new ImageAnnotation($objDB, $ann['id']);
        $str  = "<tr>
                    <td>" . htmlspecialchars($ann['canvas']) . "</td>
                    <td style=\"word-wrap:break-word; word-break:break-all;\">" . $anno->getAnnotationText() . "</td>
                    <td>" . htmlPage::makeLink("annotationTools.html?json=" . $anno->getID(), "JSON", "json_" . uniqid()) . "</td>
                    <td>" . htmlPage::makeLink("annotationTools.html?manifest=" . $anno->getID(), "Manifest with annotation", "mani_" . uniqid()) . "</td>";
        $str .= "<td>" . ImageAnnotationPresentation::getAnnotationDeleteForm($anno, "Del", $ann['canvas']) . "</td>";
        $str .= "</tr>";
        return $str;
    }

    /**
     * Render an HTML form containing a delete button for a single annotation.
     *
     * The form posts to annotationCanvas.html with hidden inputs for the
     * annotation ID and canvas URI.
     *
     * @param ImageAnnotation $anno   The annotation to be deleted.
     * @param string          $text   Label for the delete button.
     * @param string          $canvas Canvas URI associated with the annotation.
     * @return string HTML form string.
     */
    public static function getAnnotationDeleteForm(ImageAnnotation $anno, string $text, string $canvas): string
    {
        $label = htmlspecialchars($text, ENT_QUOTES);
        return htmlPage::makeFormHead("annotationCanvas.html")
             . "<input class=\"btn btn-sm btn-danger\" type=\"submit\" name=\"deleteAnnotation\" value=\"$label\">"
             . htmlPage::makeHiddenInput("aid", $anno->getID())
             . htmlPage::makeHiddenInput("canvas", $canvas)
             . htmlPage::makeFormFoot();
    }


    /********************************************************************/
    /*  CANVAS ANNOTATION TABLE                                         */
    /********************************************************************/

    /**
     * Render a DataTable-enhanced HTML table of canvas-grouped annotations.
     *
     * Used in the user-facing annotation list pages. Each row represents one
     * canvas, showing a thumbnail, canvas URI/link, optional manifest link,
     * and a Mirador launcher button. Returns a plain-text message if empty.
     *
     * @param PDOStatement $annotations Result set of canvas annotation rows.
     * @param PDODatabase  $objDB       Active database connection.
     * @param int          $uid         user_details_id of the viewing user.
     * @param bool         $display     True to show manifest link column; false for a condensed view.
     * @return string HTML string of the table and inline <script>, or "No annotations returned".
     */
    public static function getCanvasAnnotationTable(PDOStatement $annotations, PDODatabase $objDB, int $uid, bool $display): string
    {
        $tableId = uniqid("table_");
        $table   = "<table class=\"table table-sm responsive\" id=\"$tableId\">";
        $c = 0;

        while ($a = $annotations->fetch()) {
            if (!$c) {
                $table .= self::getCanvasTableHeader($display) . "<tbody>";
                $c++;
            }
            $table .= self::getCanvasAnnotationRow($a, $objDB, $uid, $display);
        }

        if (!$c) return "No annotations returned";

        $table .= "</tbody></table>";

        $script = "<script>
            $(document).ready(function() {
                $('#$tableId').DataTable({searching: false, paging: false, info: false, destroy: true});
                $('[data-toggle=\"tooltip\"]').tooltip();
            });
        </script>";

        return "<div class=\"container\">$table $script</div>";
    }

    /**
     * Render the <thead> row for the canvas annotation table.
     *
     * When $display is true, includes a Manifest column; when false, the condensed
     * view omits it (used for shared annotation views where manifest is irrelevant).
     *
     * @param bool $display True to include the Manifest column.
     * @return string HTML <thead> string.
     */
    public static function getCanvasTableHeader(bool $display): string
    {
        if ($display) return "<thead><tr><th></th><th>Canvas</th><th>Manifest</th><th></th></tr></thead>";
        return "<thead><tr><th></th><th>Canvas</th><th></th></tr></thead>";
    }

    /**
     * Render a single <tr> for the canvas annotation table.
     *
     * Shows a small thumbnail image, the canvas URI (linked to the canvas detail
     * page when $display is true), an optional manifest link, and a Mirador
     * launcher button.
     *
     * @param array       $ann     Associative row array from the DB (must include 'id', 'canvas').
     * @param PDODatabase $objDB   Active database connection.
     * @param int         $uid     user_details_id of the viewing user (for token generation).
     * @param bool        $display True to link canvas and show manifest column.
     * @return string HTML <tr> string.
     */
    public static function getCanvasAnnotationRow(array $ann, PDODatabase $objDB, int $uid, bool $display): string
    {
        $annotation = new ImageAnnotation($objDB, $ann['id']);
        return "<tr>"
             . "<td><img src=\"" . $annotation->getSmallThumbnailURL() . "\" /></td>"
             . ($display
                 ? "<td>" . htmlPage::makeLink("annotationCanvas.html?canvas=" . $ann['canvas'], htmlspecialchars($ann['canvas'])) . "</td>"
                 . "<td>" . htmlPage::makeLink("annotationList.html?canv=" . $ann['canvas'] . "&manifest=" . $annotation->getManifest(), "Manifest", "man_" . uniqid()) . "</td>"
                 : "<td>" . htmlspecialchars($ann['canvas']) . "</td>")
             . "<td>" . ImageAnnotationPresentation::getMiradorButtonOpener($objDB, $ann['canvas'], $uid) . "</td>
             </tr>";
    }


    /********************************************************************/
    /*  MIRADOR LAUNCHER                                                */
    /********************************************************************/

    /**
     * Render a Bootstrap button that opens the Mirador viewer for a canvas.
     *
     * Creates a session token for the user, renders an anchor styled as a button,
     * and injects the click-handler JavaScript produced by getMiradorCanvasOpenerJavaScript().
     * The viewer opens in a new named window so repeated clicks reuse the same tab.
     *
     * @param PDODatabase $objDB  Active database connection.
     * @param string      $canvas IIIF canvas URI to open in Mirador.
     * @param int         $uid    user_details_id of the requesting user.
     * @return string HTML anchor + inline <script> string.
     */
    public static function getMiradorButtonOpener(PDODatabase $objDB, string $canvas, int $uid): string
    {
        $token = ImageAnnotationPresentation::createClientToken($objDB, $uid);
        $btnId = uniqid();
        return "<a role=\"button\" class=\"btn btn-sm btn-outline-primary\" name=\"button_$btnId\" id=\"button_$btnId\" target=\"annotation_$btnId\">"
             . "Start Mirador&nbsp;<img src=\"" . Bibliotheca_Page::getImageRoot() . "mirador-logo.png\" width=20 /></a>"
             . ImageAnnotationPresentation::getMiradorCanvasOpenerJavaScript($objDB, $canvas, $token, $btnId);
    }

    /**
     * Generate the JavaScript click handler that launches Mirador for a specific canvas.
     *
     * On button click, the handler fades the button, builds a manifest array URL,
     * and opens the Mirador client in a named window. The user check service URL
     * and Mirador client base URL are read from Config.
     *
     * @param PDODatabase $objDB  Active database connection, used to resolve the manifest URI.
     * @param string      $canvas Canvas URI whose parent manifest should be loaded.
     * @param string      $token  Session token identifying the user to Mirador.
     * @return string Inline <script> tag string.
     */
    private static function getMiradorCanvasOpenerJavaScript(PDODatabase $objDB, string $canvas, string $token, string $btnId): string
    {
        $userCheck = Config::$USER_CHECK_SERVICE;
        $link      = Config::$MIRADOR_CLIENT."?token=$token&userCheck=$userCheck";
        $manifest  = $objDB->preparedGetOne("select manifest from image_annotation where canvas = ?", array($canvas));
        $manifestJson = json_encode([$manifest]);
        $canvasJson   = json_encode($canvas);
        return "<script type=\"text/javascript\">$(\"#button_$btnId\").click(function(){
            $(this).fadeToggle();
            const manArr=$manifestJson;
            const jsonData = JSON.stringify(manArr);
            const canvasUri = $canvasJson;
            const url = \"$link&manifest=\"+jsonData+\"&canvas=\"+encodeURIComponent(canvasUri);
            window.open(url, \"annotation_$btnId\");
        });</script>";
    }

    /**
     * Retrieve the most recent session token for a user to use as a Mirador client identifier.
     *
     * Fetches the latest ASCII session ID from user_session for the given user.
     * This token is passed to the Mirador client so it can authenticate annotation
     * requests back to the PubLink API.
     *
     * Note: An earlier implementation generated a separate one-time token stored in
     * an `annotation_token` table. That code is preserved in a comment block for reference
     * but is no longer active.
     *
     * @param PDODatabase $objDB Active database connection.
     * @param int         $uid   user_details_id of the user.
     * @return string The ASCII session ID to use as the client token.
     */
    public static function createClientToken(PDODatabase $objDB, int $uid): string
    {
        // Retrieve the user's most recent session ID to use as an auth token for Mirador.
        $token = $objDB->preparedGetOne(
            "select ascii_session_id from user_session where user_id = ? order by id desc limit 1",
            array($uid)
        );
        return $token;
    }


    /********************************************************************/
    /*  CANVAS TABBED PANEL                                             */
    /********************************************************************/

    /**
     * Build a BootstrapTabbedPanel showing all annotations on a canvas, plus
     * sharing controls and (when enabled) publication controls.
     *
     * Tab layout:
     * - One tab per annotation: thumbnail, detail table, and optional delete form.
     *   Shared annotations (not owned by $uid) are shown in italics with no delete button.
     * - A "Share" tab with the share-with form and current sharers list.
     * - A "Publish" tab (only when Config::$PUBLICATION is true) with publication
     *   form and list of already-published manifests.
     *
     * @param PDODatabase  $objDB       Active database connection.
     * @param PDOStatement $annotations Result set of annotation rows for the canvas.
     * @param string       $canvas      IIIF canvas URI.
     * @param int          $uid         user_details_id of the viewing user.
     * @param string       $target      Form action target URL for delete/share/publish forms.
     * @param int          $tab         Zero-based index of the tab to open by default (0 = first).
     * @return string Rendered HTML of the tabbed panel component.
     */
    public static function getCanvasPanel(
        PDODatabase $objDB,
        PDOStatement $annotations,
        string $canvas,
        int $uid,
        string $target,
        int $tab = 0
    ): string {
        $panel = new BootstrapTabbedPanel();
        $panel->setTitle("Annotations");
        $panel->setName("annotation_panel");

        $tabContent      = array();
        $headings        = array();
        $annotationArray = array();
        $ownedAnnotations = array();
        $c       = 0;
        $isOwner = false;

        while ($annotation = $annotations->fetch()) {
            $annotationArray[] = $ann = new ImageAnnotation($objDB, $annotation['id']);
            $ann->resolveImageUrls();
            $shared = ($ann->getUserID() != $uid);
            if (!$shared) {
                $isOwner = true;
                $ownedAnnotations[] = $ann;
            }

            $presentation = new ImageAnnotationPresentation($ann);
            // Show fragment thumbnail; only the owner gets a delete button.
            $fragment = '<div class="d-flex align-items-start justify-content-between mb-2">'
                . '<img src="' . $ann->getFragmentURL() . '" class="img-thumbnail" style="max-height:150px"/>';
            if (!$shared)
                $fragment .= '<div class="ms-2">' . ImageAnnotationPresentation::getAnnotationDeleteForm($ann, "Delete", $ann->getCanvas()) . '</div>';
            $fragment .= '</div>';

            $tabContent[$c] = $fragment . $presentation->getAsTable($objDB);

            // Shared annotation headings are italicised to visually distinguish them.
            $headingText   = trim(strip_tags($ann->getAnnotationText()));
            $headings[$c]  = $shared ? "<i>$headingText</i>" : $headingText;
            $c++;
        }

        // Append a Share tab only when the user owns at least one annotation on this canvas
        // and there are users to share with or existing sharers.
        $shareForm = $isOwner ? ImageAnnotationPresentation::getShareForm($uid, $canvas, $objDB, $target) : '';
        $shareList = $isOwner ? ImageAnnotationPresentation::getShareList($uid, $canvas, $objDB, $target) : '';
        if ($shareForm !== '' || $shareList !== '') {
            $sep = ($shareForm !== '' && $shareList !== '') ? "<hr/>" : "";
            $tabContent[$c] = "<div>" . $shareForm . $sep . $shareList . "</div>";
            $headings[$c]   = "Share";
            $c++;
        }

        // Optionally append a Publish tab when the publication feature is enabled in Config.
        if (Config::$PUBLICATION) {
            $headings[$c] = "Publish";
            $icanvas = new ImageCanvas($objDB);
            $icanvas->setCanvas($canvas);
            $publishedManifests = $icanvas->getPublishedManifests($uid);

            if (count($publishedManifests)) {
                // Split view: publication form on the left, existing manifests on the right.
                $tabContent[$c] = '<div class="row small">'
                    . '<div class="col-md-5"><p class="fw-bold mb-1" style="font-weight:bold">Publish</p>'     . self::getPublicationForm($target, $canvas, $ownedAnnotations, $c) . '</div>'
                    . '<div class="col-md-7"><p class="fw-bold mb-1" style="font-weight:bold">Already Published</p>' . self::getPublishedManifests($target, $canvas, $publishedManifests, $c) . '</div>'
                    . '</div>';
            } else {
                $tabContent[$c] = '<div class="small">' . self::getPublicationForm($target, $canvas, $ownedAnnotations, $c) . '</div>';
            }
        }

        $panel->setTabNames($headings);
        $panel->setTabContent($tabContent);
        if ($tab > 0) $panel->setOpenTab($tab);
        return $panel->getComponent();
    }


    /********************************************************************/
    /*  PUBLICATION FORMS                                               */
    /********************************************************************/

    /**
     * Render the publication form for a canvas.
     *
     * Presents a display name input, a checkbox list of annotations to include
     * in the published manifest, an API key input (required to publish —
     * Publink holds no shared key of its own to pre-fill it with), and two
     * submit buttons: one to publish directly to the public-facing server and
     * one to download the manifest as a file.
     *
     * @param string $target      Form action URL.
     * @param string $canvas      IIIF canvas URI, passed as a hidden input.
     * @param array  $annotations Array of ImageAnnotation instances available for inclusion.
     * @return string HTML form string.
     */
    private static function getPublicationForm(string $target, string $canvas, array $annotations, int $tabIndex = 0): string
    {
        $str = htmlPage::makeFormHead($target);
        $str .= "<table class=\"table table-bordered\">
                    <tr><th width=\"20%\">Display Name</th><td width=\"80%\">" . htmlPage::makeInput("display_name", 50, 200) . "</td></tr>";

        // Build one checkbox row per annotation so the user can select which to include.
        $cb = "";
        foreach ($annotations as $annotation) {
            $cb .= "<tr><th width=\"50%\">" . $annotation->getAnnotationText() . "</th>"
                 . "<td width=\"50%\">" . htmlPage::makeCheckBox("annotation[" . $annotation->getID() . "]", $annotation->getID()) . "</td></tr>";
        }

        $str .= "<tr><th width=\"20%\">Annotations</th><td width=\"80%\">Check for inclusion in manifest<hr/><table>$cb</table></td></tr>
                 <tr><th width=\"20%\">API Key</th><td width=\"80%\">" . htmlPage::makeInput("api_key", 200, "password", 50) . " <span class=\"text-muted small\">(required to publish)</span></td></tr>
                 <tr><th width=\"20%\">Publish on Server</th><td width=\"80%\">" . htmlPage::makeButton("publish", "Publish on Public Facing Server") . "</td></tr>
                 <tr><th width=\"20%\">Get Manifest File</th><td width=\"80%\">" . htmlPage::makeButton("getManifest", "Get Manifest As File") . "</td></tr>
                 </table>";
        $str .= htmlPage::makeHiddenInput("canvas", $canvas)
              . htmlPage::makeHiddenInput("tab", (string) $tabIndex)
              . htmlPage::makeFormFoot();

        return $str;
    }

    /**
     * Render a table of already-published manifests for a canvas.
     *
     * Each row shows the manifest address as a link, a "View in Theseus" deep-link,
     * and an inline delete form (with a required API key input — Publink holds
     * no shared key of its own to pre-fill it with) to unpublish the manifest.
     *
     * @param string $target    Form action URL for the delete forms.
     * @param string $canvas    IIIF canvas URI, passed as a hidden input in delete forms.
     * @param array  $manifests Array of published manifest URI strings.
     * @return string HTML table string.
     */
    private static function getPublishedManifests(string $target, string $canvas, array $manifests, int $tabIndex = 0): string
    {
        $str = "<table class=\"table table-bordered\">
                    <tr><th>Address</th><th>Display</th><th>Del</th></tr>";

        foreach ($manifests as $manifest) {
            $safe = htmlspecialchars($manifest, ENT_QUOTES, 'UTF-8');
            $str .= "<tr><td><a href=\"$safe\" target=\"" . uniqid() . "\">$safe</a></td>";
            $str .= "<td><a href=\"" . htmlspecialchars(self::$VIEWERS['Theseus'] . "?iiif-content=$manifest", ENT_QUOTES, 'UTF-8') . "\" target=\"" . uniqid() . "\">View in Theseus</a></td>";
            $str .= "<td>"
                  . htmlPage::makeFormHead($target)
                  . htmlPage::makeInput("api_key", 200, "password", 20)
                  . htmlPage::makeButton("deletePublishedManifest", "Del")
                  . htmlPage::makeHiddenInput("canvas", $canvas)
                  . htmlPage::makeHiddenInput("manifest", $manifest)
                  . htmlPage::makeHiddenInput("tab", (string) $tabIndex)
                  . htmlPage::makeFormFoot()
                  . "</td>";
            $str .= "</tr>";
        }

        return $str . "</table>";
    }


    /********************************************************************/
    /*  SHARING FORMS                                                   */
    /********************************************************************/

    /**
     * Render the "Share Annotations" form for a canvas.
     *
     * Presents a dropdown of users who do not yet have access to this canvas,
     * and a Share button. Returns null if there are no users available to share with.
     *
     * @param int         $uid    user_details_id of the canvas owner.
     * @param string      $canvas IIIF canvas URI.
     * @param PDODatabase $objDB  Active database connection.
     * @param string      $target Form action URL.
     * @return string HTML form string, or empty string if no sharable users exist.
     */
    private static function getShareForm(int $uid, string $canvas, PDODatabase $objDB, string $target): string
    {
        $shareList = ImageAnnotation::getShareListForCanvas($uid, $canvas, $objDB);
        if (!count($shareList)) return "";

        $content  = '<p class="fw-bold small mb-1" style="font-weight:bold">Share Annotations</p>';
        $content .= "<form action=\"$target\" method=\"POST\" id=\"shareForm\">";
        $content .= '<table class="table table-bordered table-sm small">';
        $content .= "<tr><td>Share with</td><td>" . htmlPage::makeOptionFromArray("user_details_id", $shareList) . "</td></tr>";
        $content .= "<tr><td></td><td>" . htmlPage::makeButton("shareCanvas", "Share") . htmlPage::makeHiddenInput("canvas", $canvas) . "</td></tr>";
        $content .= "</table>";
        $content .= htmlPage::makeFormFoot();
        return $content;
    }

    /**
     * Render the list of users this canvas is currently shared with.
     *
     * Each row shows the user's full name and an inline "Del" form to revoke
     * their access. Returns null if the canvas has not been shared with anyone.
     *
     * @param int         $uid    user_details_id of the canvas owner.
     * @param string      $canvas IIIF canvas URI.
     * @param PDODatabase $objDB  Active database connection.
     * @param string      $target Form action URL for the revoke forms.
     * @return string HTML table string, or empty string if no sharers exist.
     */
    private static function getShareList(int $uid, string $canvas, PDODatabase $objDB, string $target): string
    {
        $sharers = ImageAnnotation::getSharersForCanvas($uid, $canvas, $objDB);
        if (!count($sharers)) return "";

        $content = '<p class="fw-bold small mb-1" style="font-weight:bold">Shared with</p>'
                 . '<table class="table table-bordered table-sm small">';
        foreach ($sharers as $sharer) {
            $content .= "<tr><td>" . htmlspecialchars($sharer[1]) . "</td><td>"
                      . htmlPage::makeFormHead($target)
                      . htmlPage::makeButton("deleteSharer", "Del")
                      . htmlPage::makeHiddenInput("user_details_id", $sharer[0])
                      . htmlPage::makeHiddenInput("canvas", $canvas)
                      . htmlPage::makeFormFoot()
                      . "</td></tr>";
        }
        $content .= "</table>";
        return $content;
    }


    /********************************************************************/
    /*  UTILITY                                                         */
    /********************************************************************/

    /**
     * Return a human-readable annotation count string.
     *
     * Returns the singular form when $num is 1, plural otherwise.
     * e.g. getAnnotationString(1) → "1 Annotation"
     *      getAnnotationString(3) → "3 Annotations"
     *
     * @param int $num Number of annotations.
     * @return string Formatted count string.
     */
    public static function getAnnotationString(int $num): string
    {
        if ($num == 1) return "$num Annotation";
        return "$num Annotations";
    }
}
?>