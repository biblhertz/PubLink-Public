<?php
/**
 * File / Object Delete and List Service
 *
 * Deletes a file or serialized object belonging to the current user, then
 * returns a refreshed HTML table of the remaining items for inline page
 * injection. Intended to be called via AJAX immediately after a delete action
 * in the UI.
 *
 * Two mutually exclusive modes are supported, selected by the request parameter:
 *
 *   File mode  ($_REQUEST['file_id'] present)
 *     Deletes the specified file via {@see File::deleteFile()} and returns an
 *     updated file list table. The list shown depends on the deleted file's
 *     extension:
 *       - `.log` files → {@see User::getMyLogFilesAsResultSet()} (log file list)
 *       - All other files → {@see User::getMyFilesAsResultSet()} (general file list)
 *     This ensures the user is returned to the same list view they were browsing.
 *
 *   Object mode  ($_REQUEST['object_id'] present)
 *     Deletes the specified serialized object via {@see SerializedObject::deleteObject()}
 *     and returns an updated object list table via
 *     {@see SerializedObjectPresentation::getObjectListAsTable()}.
 *
 * If neither parameter is present, no action is taken and an empty response
 * is returned.
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML table fragment of remaining items, or empty if no valid
 *         parameter was supplied.
 *
 * @package Biblhertz\Publink
 * @see     File::deleteFile()
 * @see     SerializedObject::deleteObject()
 * @see     FilePresentation::getFileListAsTable()
 * @see     SerializedObjectPresentation::getObjectListAsTable()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\presentation\FilePresentation;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\presentation\SerializedObjectPresentation;
use Biblhertz\Publink\om\SerializedObject;

$page = new Bibliotheca_Content_Page();

header('Content-Type: text/html; charset=UTF-8');

// -------------------------------------------------------------------------
// File mode: delete a file and return the appropriate refreshed file list
// -------------------------------------------------------------------------

if (isset($_REQUEST['file_id'])) {

    $fid = $_REQUEST['file_id'];
    $f   = new File($page->getObjDB(), $fid);
    $f->deleteFile($page->getUser()->getID());

    // Return the log file list for .log files, the general list for all others,
    // so the user lands back on the same list view they were browsing
    if (!strcmp($f->getFileExtension(), "log")) {
        echo FilePresentation::getFileListAsTable(
            $page->getUser()->getMyLogFilesAsResultSet(),
            $page->getObjDB(),
            true
        );
    } else {
        echo FilePresentation::getFileListAsTable(
            $page->getUser()->getMyFilesAsResultSet(),
            $page->getObjDB(),
            true
        );
    }

// -------------------------------------------------------------------------
// Object mode: delete a serialized object and return the refreshed object list
// -------------------------------------------------------------------------

} elseif (isset($_REQUEST['object_id'])) {

    $fid = $_REQUEST['object_id'];
    $f   = new SerializedObject($page->getObjDB(), $fid);
    $f->deleteObject($page->getUser()->getID());

    echo SerializedObjectPresentation::getObjectListAsTable(
        $page->getUser()->getMyObjectsAsResultSet(),
        $page->getObjDB(),
        true
    );
}

$page = null;
exit;

?>