<?php
/**
 * File Box List Service
 *
 * Returns an HTML checkbox table listing all files belonging to the current
 * authenticated user, intended for inline injection into a page.
 *
 * The file list is retrieved via {@see User::getMyFilesAsResultSet()} and
 * rendered by {@see FilePresentation::getFileListAsCheckBoxTable()}.
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML checkbox table fragment, ready for page insertion.
 *
 * @package Biblhertz\Publink
 * @see     FilePresentation::getFileListAsCheckBoxTable()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\presentation\FilePresentation;

$page = new Bibliotheca_Content_Page();

header('Content-Type: text/html; charset=UTF-8');

// Fetch the current user's files and render them as an HTML checkbox table
echo FilePresentation::getFileListAsCheckBoxTable($page->getUser()->getMyFilesAsResultSet());

// Release the page object and its resources (DB connections etc.)
$page = null;

exit;
?>