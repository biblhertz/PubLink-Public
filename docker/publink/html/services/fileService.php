<?php
/**
 * File List Service
 *
 * Returns an HTML table of files belonging to the current user, for inline
 * page injection. The list returned depends on the presence of the `jats`
 * request parameter:
 *
 *   ?jats  (present, any value)
 *     Returns only JATS XML files via {@see User::getMyJATSFilesAsResultSet()}.
 *     JATS (Journal Article Tag Suite) files are used for structured article
 *     import/export in the publishing workflow.
 *
 *   (absent)
 *     Returns the user's general file list via {@see User::getMyFilesAsResultSet()}.
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML file list table fragment, ready for page insertion.
 *
 * @package Biblhertz\Publink
 * @see     FilePresentation::getFileListAsTable()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\presentation\FilePresentation;

$page = new Bibliotheca_Content_Page();

header('Content-Type: text/html; charset=UTF-8');

// Return JATS XML files if requested, otherwise the general file list
if (isset($_REQUEST['jats'])) {
    echo FilePresentation::getFileListAsTable(
        $page->getUser()->getMyJATSFilesAsResultSet(),
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

$page = null;
exit;
?>