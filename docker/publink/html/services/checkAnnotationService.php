<?php
/**
 * Annotation Edit Check Service
 *
 * Polls for image annotations that have been modified in the Mirador viewer
 * since the last check. A modified annotation is identified by the `edited`
 * flag (value 1) in the `image_annotation` table, which Mirador sets whenever
 * a change is saved.
 *
 * The service supports two modes depending on whether a `canvas` parameter is
 * supplied in the request:
 *
 *   Canvas mode  ($_REQUEST['canvas'] present)
 *     Checks for edited annotations on a specific IIIF canvas. Returns an
 *     HTML canvas panel ({@see ImageAnnotationPresentation::getCanvasPanel()})
 *     suitable for inline page injection.
 *
 *   Global mode  (no canvas parameter)
 *     Checks for any edited annotations belonging to the current user across
 *     all canvases. Returns an HTML annotation table
 *     ({@see ImageAnnotationPresentation::getCanvasAnnotationTable()}) for the
 *     user's full annotation list page.
 *
 * In both modes, once edits are detected the `edited` flag is reset to 0 via
 * {@see setEdit()} before the updated HTML is generated, preventing the same
 * changes from being re-processed on the next poll.
 *
 * Ownership model:
 *   A user sees their own annotations plus annotations on canvases they have
 *   been granted shared access to via the `user_details_canvas_annotation`
 *   join table (where `owner_id` identifies the annotation's original author).
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML fragment for page injection, or an empty string if no edits
 *         were found.
 *
 * @package Biblhertz\Publink
 * @see     ImageAnnotationController   Front controller that routes to this service
 * @see     ImageAnnotation
 * @see     ImageAnnotationPresentation
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\annotation\ImageAnnotation;
use Biblhertz\Publink\annotation\ImageAnnotationPresentation;

// -------------------------------------------------------------------------
// Initialise page context and current user
// -------------------------------------------------------------------------

$page = new Bibliotheca_Content_Page();

/** @var int $uid Current authenticated user ID */
$uid = $page->getUser()->getID();

/** @var string $echoString HTML fragment returned to the caller; empty if no edits detected */
$echoString = "";

// -------------------------------------------------------------------------
// Canvas mode: check for edits on a specific IIIF canvas
// -------------------------------------------------------------------------

if (isset($_REQUEST['canvas'])) {

    /** @var string $canvas IIIF canvas URI supplied by the Mirador client */
    $canvas = trim($_REQUEST['canvas']);

    /*
     * Fetch annotations on this canvas that have been edited, considering:
     *   - Annotations owned directly by the current user (user_details_id = ?)
     *   - Annotations on shared canvases the user has access to, where the
     *     owner has also made edits (via user_details_canvas_annotation join)
     */
    $annotations = $page->getObjDB()->preparedSelect(
        "SELECT * FROM image_annotation
         WHERE (canvas = ? AND user_details_id = ? AND edited = 1)
            OR (canvas = ? AND canvas IN (
                    SELECT canvas FROM user_details_canvas_annotation
                    WHERE user_details_id = ?
                      AND image_annotation.user_details_id = owner_id
                      AND image_annotation.edited = 1
                ))",
        array($canvas, $uid, $canvas, $uid)
    );

    $rows = $page->getObjDB()->numRows();

    if ($rows) {
        // Reset the edited flag on all affected annotations
        setEdit($annotations);

        // Re-fetch all annotations for this canvas (edited or not) to build
        // the complete, up-to-date canvas panel
        $annotations = $page->getObjDB()->preparedSelect(
            "SELECT * FROM image_annotation
             WHERE (canvas = ? AND user_details_id = ?)
                OR (canvas = ? AND canvas IN (
                        SELECT canvas FROM user_details_canvas_annotation
                        WHERE user_details_id = ?
                          AND image_annotation.user_details_id = owner_id
                    ))",
            array($canvas, $uid, $canvas, $uid)
        );

        $echoString = ImageAnnotationPresentation::getCanvasPanel(
            $page->getObjDB(),
            $annotations,
            $canvas,
            $uid,
            "annotationCanvas.html"
        );
    }

// -------------------------------------------------------------------------
// Global mode: check for edits across all canvases for this user
// -------------------------------------------------------------------------

} else {

    /*
     * Find the most recent edited annotation per canvas, covering both
     * directly owned annotations and those on shared canvases.
     * Grouping by canvas ensures we detect edits efficiently without
     * fetching the full annotation body at this stage.
     */
    $annotations = $page->getObjDB()->preparedSelect(
        "SELECT MAX(id) AS id, canvas
         FROM image_annotation
         WHERE edited = 1
           AND (user_details_id = ?
                OR canvas IN (
                    SELECT canvas FROM user_details_canvas_annotation
                    WHERE user_details_id = ?
                ))
         GROUP BY canvas",
        array($uid, $uid)
    );

    $rows = $page->getObjDB()->numRows();

    if ($rows) {
        // Reset the edited flag on all detected annotations
        setEdit($annotations);

        // Re-fetch the complete annotation list for the user to rebuild
        // the full annotation table view
        $annotations = ImageAnnotation::getAnnotationListForUser($uid, $page->getObjDB());

        $echoString = ImageAnnotationPresentation::getCanvasAnnotationTable(
            $annotations,
            $page->getObjDB(),
            $uid,
            true
        );
    }
}

// -------------------------------------------------------------------------
// Output
// -------------------------------------------------------------------------

header('Content-Type: text/html; charset=UTF-8');
echo $echoString;
exit;


// -------------------------------------------------------------------------
// Helper functions
// -------------------------------------------------------------------------

/**
 * Reset the `edited` flag on a set of annotations.
 *
 * Iterates over the supplied result set and sets `edited = 0` on each row,
 * marking them as processed so they are not re-returned on the next poll.
 *
 * @param PDOStatement $annotations Result set from a `preparedSelect()` call,
 *                                  each row must include an `id` column.
 * @return void
 */
function setEdit($annotations)
{
    global $page;

    while ($ann = $annotations->fetch()) {
        $annotation = new ImageAnnotation($page->getObjDB(), $ann['id']);
        $annotation->updateEdited(0);
    }
}

?>