<?php
/**
 * JATS / BibTeX Menu Service
 *
 * Returns an HTML menu fragment for either the user's JATS XML files or their
 * BibTeX bibliography entries, selected by the `menu` request parameter.
 *
 *   ?menu=bibMenu   → BibTeX menu via {@see Bibliotheca_Content_Page::getMyBibtexMenu()}
 *   ?menu=<other>   → JATS XML menu via {@see Bibliotheca_Content_Page::getMyJATSMenu()}
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML menu fragment for inline page injection.
 *
 * @package Biblhertz\Publink
 * @see     Bibliotheca_Content_Page::getMyBibtexMenu()
 * @see     Bibliotheca_Content_Page::getMyJATSMenu()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;

$page = new Bibliotheca_Content_Page();

header('Content-Type: text/html; charset=UTF-8');

// Return the BibTeX menu if explicitly requested, otherwise default to JATS
if (!strcmp($_REQUEST['menu'], "bibMenu")) {
    echo $page->getMyBibtexMenu();
} else {
    echo $page->getMyJATSMenu();
}

$page = null;
exit;
?>