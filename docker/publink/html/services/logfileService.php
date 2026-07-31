<?php
/**
 * Log File List Service
 *
 * Returns an HTML table of log files belonging to the current user, for
 * inline page injection. Log files are a distinct subset of the user's
 * files (`.log` extension) used to record background job output.
 *
 * This service is the log-file counterpart to the general file list service,
 * and is also called by the delete service after a `.log` file is removed to
 * refresh the log file list view.
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML file list table fragment, ready for page insertion.
 *
 * @package Biblhertz\Publink
 * @see     FilePresentation::getFileListAsTable()
 * @see     User::getMyLogFilesAsResultSet()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\presentation\FilePresentation;

$page = new Bibliotheca_Content_Page();

header('Content-Type: text/html; charset=UTF-8');

echo FilePresentation::getFileListAsTable(
    $page->getUser()->getMyLogFilesAsResultSet(),
    $page->getObjDB(),
    true
);

$page = null;
exit;

?>