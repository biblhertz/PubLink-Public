<?php
/**
 * CSL Reference Rendering Service
 *
 * Renders one or all references from a serialized object (Article or
 * ReferenceCollection) as formatted HTML bibliography entries using a
 * Citation Style Language (CSL) stylesheet via the seboettg/citeproc-php
 * library.
 *
 * Request parameters:
 *   oid    int     ID of the serialized object containing the reference data.
 *                  Must be numeric. The object may be an `Article` (references
 *                  are extracted from it) or a `ReferenceCollection` directly.
 *   key    string  Reference label to render, or "all" to render every
 *                  reference in the collection with navigation links.
 *   style  string  CSL stylesheet name (without `.csl` extension), resolved
 *                  against {@see Config::$CSL_LOCATION}.
 *
 * Rendering pipeline:
 *   1. Load and validate the serialized object and resolve the target reference(s).
 *   2. Load the CSL stylesheet from {@see Config::$CSL_LOCATION}.
 *   3. Apply any style-specific patches (see below).
 *   4. Render via {@see CiteProc::render()} in "bibliography" mode.
 *   5. On CiteProc failure, fall back to {@see formatBiblHertzianaCitation()}.
 *
 * Style patching:
 *   The `bibliotheca-hertziana-max-planck-institute-for-art-history` style
 *   has its `<text macro="point-locators"/>` element stripped from the
 *   citation layout before rendering. This prevents locator formatting
 *   (page numbers etc.) from appearing in standalone bibliography entries
 *   where no locator context exists.
 *
 * Fallback formatter:
 *   {@see formatBiblHertzianaCitation()} provides a basic manual formatter
 *   used when CiteProc throws an Error (e.g. unsupported CSL constructs).
 *   It handles authors, title (with italics for books/manuscripts/theses),
 *   publisher, type, date, and URL.
 *
 * "all" key mode:
 *   Each reference is wrapped in a `<div id="{label}">` with its publication
 *   type heading and a navigation link to its individual reference page
 *   (`reference.html?oid={oid}&&key={label}`).
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML bibliography fragment, or a stringified Exception on error.
 *
 * Note: the original `getCSLReference()` implementation (which loaded the CSL
 * file from the vendor styles directory) is preserved as a commented-out block
 * for reference during the transition to {@see Config::$CSL_LOCATION}.
 *
 * @package Biblhertz\Publink
 * @see     Config::$CSL_LOCATION
 * @see     CiteProc
 * @see     SerializedObject
 */

require 'vendor/autoload.php';

use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\SerializedObject;
use Seboettg\CiteProc\StyleSheet;
use Seboettg\CiteProc\CiteProc;
use Biblhertz\Publink\Config;

/**
 * Returns the highest match percentage for a reference.
 * Uses the stored matchPercent if set; otherwise scans refCheck candidates.
 * This handles articles checked before per-reference scoring was introduced.
 */
function getBestMatchPercent(Reference $reference): float {
    $pct = $reference->getMatchPercent();
    if ($pct > 0.0) return $pct;

    $refCheck = $reference->getRefCheck();
    if (!is_array($refCheck)) return 0.0;

    $best = 0.0;
    foreach ($refCheck as $candidates) {
        if ($candidates instanceof ReferenceCollection) {
            foreach ($candidates as $candidate) {
                $score = $candidate->getMatchPercent();
                if ($score > $best) $best = $score;
            }
        }
    }
    return $best;
}

try {
    $page = new Bibliotheca_Content_Page();

    // -------------------------------------------------------------------------
    // Validate and load the serialized object
    // -------------------------------------------------------------------------

    if (isset($_REQUEST['oid']) && is_numeric($_REQUEST['oid'])) {
        $oid    = $_REQUEST['oid'];
        $object = new SerializedObject($page->getObjDB(), $_REQUEST['oid']);
    } else {
        throw new Exception("Error :: No object ID is set or ID is not numeric");
    }

    // -------------------------------------------------------------------------
    // Validate the reference key
    // -------------------------------------------------------------------------

    if (isset($_REQUEST['key']) && strcmp("", $_REQUEST['key'])) {
        $key = $_REQUEST['key'];
    } else {
        throw new Exception("Error :: No reference ID is set");
    }

    // -------------------------------------------------------------------------
    // Resolve the collection from the deserialized object
    // -------------------------------------------------------------------------

    $str        = "";
    $collection = unserialize($object->getObject());

    // If the object is an Article, extract its reference collection
    if (is_a($collection, "\Biblhertz\Article\om\Article")) {
        $collection = $collection->getReferences();
    }

    // -------------------------------------------------------------------------
    // Render references
    // -------------------------------------------------------------------------

    if (is_a($collection, "\Biblhertz\Article\om\ReferenceCollection")) {

        if (!strcmp($key, "all")) {
            // Render all references with type headings and navigation links
            $str .= '<table id="csl_ref_table" class="table table-bordered table-sm small w-100" style="table-layout:fixed; word-wrap:break-word; overflow-wrap:break-word;">'
                  . '<thead><tr><th style="width:12%">Type</th><th>Reference</th><th style="width:7%">Match</th><th style="width:3%"></th></tr></thead><tbody>';
            foreach ($collection as $reference) {
                $rid      = htmlspecialchars($reference->getLabel(), ENT_QUOTES, 'UTF-8');
                $type     = htmlspecialchars(ucfirst($reference->getPublicationType()), ENT_QUOTES, 'UTF-8');
                $matchPct = getBestMatchPercent($reference);
                if ($matchPct > 0) {
                    $badgeClass = $matchPct >= 80 ? 'badge-success' : ($matchPct >= 60 ? 'badge-warning' : 'badge-danger');
                    $matchCell  = '<span class="badge ' . $badgeClass . '">' . $matchPct . '%</span>';
                } else {
                    $matchCell = '<span class="text-muted">—</span>';
                }
                $matchDataOrder = $matchPct > 0 ? $matchPct : -1;
                $str .= '<tr id="' . $rid . '">'
                      . '<td class="text-nowrap" style="width:12%"><b>' . $type . '</b></td>'
                      . '<td style="word-wrap:break-word; overflow-wrap:break-word;">' . getCSLReference($reference) . '</td>'
                      . '<td class="text-center" style="width:7%" data-order="' . $matchDataOrder . '">' . $matchCell . '</td>'
                      . '<td class="text-center" style="width:3%"><a href="reference.html?oid=' . $oid . '&&key=' . $rid . '" title="Open reference" class="text-secondary">'
                      . '<i class="fas fa-external-link-alt"></i></a></td>'
                      . '</tr>';
            }
            $str .= '</tbody></table>';
        } else {
            // Render a single reference by key
            $reference = $collection->getReferenceFromKey($key);
            $str      .= getCSLReference($reference);
        }
    }

    header('Content-Type:text/html; charset=UTF-8');
    echo $str;

} catch (Exception $e) {
    error_log((string)$e);
    header('Content-Type:text/html; charset=UTF-8');
    echo (string)$e;
}


// -------------------------------------------------------------------------
// Legacy implementation (retained for reference — uses vendor styles path)
// -------------------------------------------------------------------------

/** Original getCSLReference() using vendor citation-style-language/styles:
function getCSLReference(Reference $reference) {
    if (isset($reference) && is_a($reference, "\Biblhertz\Article\om\Reference")) {
        error_reporting(E_ERROR | E_PARSE);
        $style     = $_REQUEST['style'];
        $styleFile = '/var/www/vendor/citation-style-language/styles/' . $style . '.csl';
        if (!file_exists($styleFile)) {
            throw new Exception("Citation style '$style' not found");
        }
        $styleContent = file_get_contents($styleFile);
        $json = json_decode($reference->getAsJson());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in reference data");
        }
        if (!is_array($json)) {
            $json = [$json];
        }
        $citeProc = new CiteProc($styleContent);
        return $citeProc->render($json, "bibliography");
    }
}
**/


// -------------------------------------------------------------------------
// Functions
// -------------------------------------------------------------------------

/**
 * Render a single reference as an HTML bibliography entry via CiteProc.
 *
 * Loads the requested CSL stylesheet, applies any necessary style-specific
 * patches, converts the reference to JSON, and renders it using
 * {@see CiteProc::render()}. Falls back to {@see formatBiblHertzianaCitation()}
 * if CiteProc throws an Error.
 *
 * @param  Reference $reference  The reference object to render.
 * @return string                HTML bibliography entry string.
 * @throws Exception             If the CSL file is missing, empty, or the
 *                               reference JSON is invalid.
 */
function getCSLReference(Reference $reference)
{
    if (isset($reference) && is_a($reference, "\Biblhertz\Article\om\Reference")) {

        error_reporting(E_ERROR | E_PARSE);

        $style     = $_REQUEST['style'];
        $styleFile = Config::$CSL_LOCATION . DIRECTORY_SEPARATOR . $style . '.csl';

        if (!file_exists($styleFile)) {
            throw new Exception("Citation style '$style' not found");
        }

        $styleContent = file_get_contents($styleFile);

        // Patch: strip point-locators from the Bibliotheca Hertziana style when
        // rendering bibliography entries, where no locator context is present
        if ($style === 'bibliotheca-hertziana-max-planck-institute-for-art-history') {
            $styleContent = preg_replace(
                '/<text macro="point-locators"\/>/',
                '',
                $styleContent
            );
        }

        if (empty($styleContent)) {
            throw new Exception("Citation style file is empty");
        }

        $jsonString = $reference->getAsJson();
        $json       = json_decode($jsonString);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in reference data");
        }

        if (!is_array($json)) {
            $json = [$json];
        }

        try {
            $citeProc = new CiteProc($styleContent, "en-US");
            return $citeProc->render($json, "bibliography");
        } catch (Error $e) {
            // CiteProc failed (e.g. unsupported CSL construct) — use manual fallback
            return formatBiblHertzianaCitation($json[0]);
        }
    }
}

/**
 * Fallback bibliography formatter for when CiteProc cannot render a reference.
 *
 * Produces a plain formatted citation string from a decoded JSON reference
 * object, covering the most common CSL fields. Used as a safety net when
 * {@see getCSLReference()} catches a CiteProc Error.
 *
 * Formatting rules:
 *   - Authors are listed as "Given Family", comma-separated.
 *   - Titles for `manuscript`, `book`, and `thesis` types are italicised;
 *     all others are wrapped in double quotes.
 *   - Publisher, type, year, and URL are appended where present.
 *
 * @param  object $item  Decoded CSL-JSON reference object (single item).
 * @return string        HTML-formatted citation string.
 */
function formatBiblHertzianaCitation($item)
{
    $output = "";

    // Authors
    if (isset($item->author) && is_array($item->author) && count($item->author) > 0) {
        $authors = [];
        foreach ($item->author as $author) {
            $name = '';
            if (isset($author->given))  $name .= $author->given . ' ';
            if (isset($author->family)) $name .= $author->family;
            if ($name) $authors[] = trim($name);
        }
        $output .= implode(', ', $authors) . ', ';
    }

    // Title — italic for monograph types, quoted otherwise
    if (isset($item->title)) {
        if (isset($item->type) && in_array($item->type, ['manuscript', 'book', 'thesis'])) {
            $output .= '<i>' . htmlspecialchars($item->title) . '</i>, ';
        } else {
            $output .= '"' . htmlspecialchars($item->title) . '", ';
        }
    }

    if (isset($item->publisher)) {
        $output .= $item->publisher . ', ';
    }

    if (isset($item->type)) {
        $output .= ucfirst($item->type) . ', ';
    }

    // Year
    if (isset($item->issued->{'date-parts'}[0][0])) {
        $output .= $item->issued->{'date-parts'}[0][0] . '. ';
    }

    if (isset($item->URL)) {
        $output .= 'URL: ' . $item->URL . '.';
    }

    return rtrim($output, ', ') . '.';
}

?>