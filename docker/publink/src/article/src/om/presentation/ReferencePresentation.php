<?php

namespace Biblhertz\Article\om\presentation;

use Biblhertz\Article\om\Reference;
use Biblhertz\Publink\om\presentation\SerializedObjectPresentation;
use Biblhertz\Publink\pages\htmlPage;
use ReflectionClass;

/********************************************************************/
/*  ReferencePresentation                                           */
/*                                                                  */
/*  Author  :   Chris Tomlinson                                     */
/*  Date    :   10th July 2023                                      */
/*                                                                  */
/*  Presentation class for individual Reference objects.            */
/*  Renders a reference as various HTML components used across      */
/*  the PubLink editorial interface: serialized property tables,    */
/*  short citation strings, expandable table rows with checkbox     */
/*  field selectors, and DOI match comparison tables.               */
/********************************************************************/

/**
 * Renders a single Reference object as HTML UI components.
 *
 * Handles the visual representation of references in multiple contexts:
 *  - Full serialized property table (getAsTable).
 *  - Compact citation display (getShortReference).
 *  - Expandable table row with per-field checkboxes (getAsTableRow / getAsCheckBoxTable).
 *  - DOI match comparison tables with accept/include forms (commented out — see below).
 *
 * The two DOI match methods (getDOIMatchAsTable, getDOIMatchAsTableNew) are
 * currently commented out pending reinstatement of the DOI matching workflow.
 *
 * @package Biblhertz\Article\om\presentation
 */
class ReferencePresentation {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var Reference The reference object rendered by this instance. */
    private Reference $reference;


    /****************************************************************/
    /*  CONSTRUCTOR                                                 */
    /****************************************************************/

    /**
     * @param Reference $ref The reference to present.
     */
    public function __construct(Reference $ref) {
        $this->reference = $ref;
    }


    /****************************************************************/
    /*  PRESENTATION METHODS                                        */
    /****************************************************************/

    /**
     * Returns the reference rendered as a full serialized property table,
     * wrapped in Bootstrap vertical padding divs.
     *
     * Delegates to SerializedObjectPresentation::getObjectAsTable().
     *
     * @return string HTML <div><table>…</table></div> markup.
     */
    public function getAsTable(): string {
        return "<div class=\"pt-4 pb-4\">"
             . SerializedObjectPresentation::getObjectAsTable($this->reference)
             . "</div>";
    }


    /**
     * Returns a compact HTML citation string showing title, author list,
     * and year.
     *
     * For Chapter references, getChapterTitle() is preferred over getTitle();
     * if no chapter title is set, getTitle() is used as a fallback.
     * Each populated field is followed by a comma and a line break.
     * Empty or unset fields are silently omitted.
     *
     * @return string HTML snippet wrapped in a <span class="w-100 csl-bib-body">.
     */
    public function getShortReference(): string {
        $ref = $this->reference;

        $str   = "<span class=\"w-100 csl-bib-body\">";
        $title = $ref->getTitle();

        // Prefer chapter title for Chapter subclass instances
        if (is_a($ref, "\Biblhertz\Article\om\Chapter")) {
            $title = $ref->getChapterTitle();
        }

        if (!empty($title)) {
            $str .= "<b>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</b>,<br/>";
        }

        $list = $ref->getAuthorList(true);
        if (!empty($list)) {
            $str .= htmlspecialchars($list, ENT_QUOTES, 'UTF-8') . ",<br/>";
        }

        $year = $ref->getYear();
        if (!empty($year)) {
            $str .= htmlspecialchars($year, ENT_QUOTES, 'UTF-8');
        }

        return $str . "</span>";
    }


    /*
    public function getDOIMatchAsTable($form = false, $oid = false, $tab = 0) { ... }
    public function getDOIMatchAsTableNew($form = false, $oid = false, $tab = 0) { ... }
    */


    /**
     * Renders all non-static, non-array, non-object properties of the reference
     * as an HTML table with a checkbox per row, used to allow editors to
     * selectively copy individual field values.
     *
     * Uses PHP Reflection to enumerate properties at runtime, skipping:
     *  - Static properties.
     *  - The `id` field.
     *  - Any fields listed in Reference::getDisallowedFields() (if the method exists).
     *  - Properties with empty or null values.
     *
     * Special rendering:
     *  - `pubId` fields with a known type (doi / pmid) are rendered as external links.
     *  - `uri` fields are rendered as clickable links.
     *
     * Checkbox names follow the pattern `{propertyName}_{uniqid}` to avoid
     * collisions when multiple tables appear on the same page.
     *
     * @return string HTML <table> markup with one checkbox row per eligible property.
     */
    public function getAsCheckBoxTable(): string {
        $object = $this->reference;

        $disallowedFields = method_exists($object, "getDisallowedFields")
            ? $object->getDisallowedFields()
            : array();

        $reflect = new ReflectionClass($object);
        $props   = $reflect->getProperties();
        $str     = "<table class=\"table table-bordered table-sm\">";

        // Resolve external link base URL from pub-id type
        $link = null;
        if (method_exists($object, "getPubIdType")) {
            $type = $object->getPubIdType();
            if ($type === "pmid")      $link = "https://pubmed.ncbi.nlm.nih.gov/";
            else if ($type === "doi")  $link = "https://doi.org/";
        }

        $uniqid = uniqid();

        foreach ($props as $prop) {
            $prop->setAccessible(true);
            if ($prop->isStatic()) continue;
            if ($prop->getName() === "id") continue;
            if (in_array($prop->getName(), $disallowedFields)) continue;

            $value = $prop->getValue($object);
            // Skip arrays and objects
            if (is_array($value) || is_object($value)) continue;
            $key   = $prop->getName();
            $cbid  = $key . "_$uniqid";
            $nvalue = null;

            $safeValue = htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
            $safeKey   = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $safeCbid  = htmlspecialchars($cbid, ENT_QUOTES, 'UTF-8');

            // Linkify pubId and uri fields; render note as raw HTML (may contain a trusted link)
            $nvalue = null;
            if ($key === "pubId" && isset($link)) {
                $nvalue = "<a href=\"" . htmlspecialchars($link . $value, ENT_QUOTES, 'UTF-8') . "\" target=\"_blank\">$safeValue</a>";
            } elseif ($key === "uri") {
                $nvalue = "<a href=\"" . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "\" target=\"_blank\">$safeValue</a>";
            } elseif ($key === "note") {
                $nvalue = $value;
            }

            if (!empty($value)) {
                $str .= "<tr>
                    <td width=\"5%\"><input type=\"checkbox\" id=\"$safeCbid\" name=\"$safeCbid\" value=\"$safeValue\" /></td>
                    <td width=\"15%\">$safeKey</td>
                    <th width=\"80%\" style=\"word-wrap:break-word; word-break:break-all;\">"
                    . ($nvalue ? $nvalue : $safeValue) .
                    "</th>
                    </tr>";
            }
        }

        $str .= "</table>";
        return $str;
    }


    /**
     * Renders the reference as an expandable HTML table row for use within a
     * reference list table (e.g. ReferenceCollectionPresentation::getAsTableList()).
     *
     * The visible row shows year, author list, and title (with chapter title
     * prepended for Chapter references). An expand/collapse toggle icon
     * (Font Awesome chevron) is appended to the title cell; clicking it calls
     * the JavaScript openClose() function with a sanitised version of the
     * reference label as the element ID.
     *
     * A second hidden row immediately follows, containing the full
     * getAsCheckBoxTable() output inside a display:none div. This div is
     * toggled by openClose() to show/hide the field-level detail.
     *
     * The element ID is derived from the reference label with the following
     * characters stripped to ensure valid HTML id syntax: https://, http://, '.', '/'.
     *
     * @return string Two <tr> elements: one visible summary row and one
     *                collapsible detail row.
     */
    public function getAsTableRow(): string {
        $ref = $this->reference;

        $str  = "<tr>";
        $year = $ref->getYear();
        $str .= "<td width=\"8%\">";
        if (!empty($year)) $str .= htmlspecialchars($year, ENT_QUOTES, 'UTF-8');
        $str .= "</td>";

        $str .= "<td width=\"25%\" style=\"word-wrap:break-word; overflow-wrap:break-word;\">";
        $list = $ref->getAuthorList();
        if (!empty($list)) $str .= htmlspecialchars($list, ENT_QUOTES, 'UTF-8');
        $str .= "</td>";

        $str .= "<td width=\"59%\" style=\"word-wrap:break-word; overflow-wrap:break-word;\">";
        $title = $ref->getTitle();

        // Prepend chapter title for Chapter subclass instances
        if (is_a($ref, "\Biblhertz\Article\om\Chapter")) {
            $stitle = $ref->getChapterTitle();
            if (!empty($stitle)) $str .= htmlspecialchars($stitle, ENT_QUOTES, 'UTF-8') . "<br/>";
        }

        if (!empty($title)) $str .= htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $matchPct   = $ref->getMatchPercent();
        $colorClass = 'text-secondary';
        $badgeClass = '';
        if ($matchPct > 0) {
            $colorClass = $matchPct >= 80 ? 'text-success' : ($matchPct >= 60 ? 'text-warning' : 'text-danger');
            $badgeClass = $matchPct >= 80 ? 'badge-success' : ($matchPct >= 60 ? 'badge-warning' : 'badge-danger');
        }

        // Sanitise label for use as a valid HTML id / JS argument
        $id = $ref->getLabel();
        $id = str_ireplace(['https://', 'http://', '.', '/'], '', $id);
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');

        $str .= "&nbsp;<a href=\"javascript:void(0)\" onClick=\"openClose('$safeId');\" title=\"Show detail\" class=\"$colorClass\"><i id=\"icon_$safeId\" class=\"fas fa-info-circle fa-lg\"></i></a>";
        $str .= "</td>";

        // Dedicated match percentage column
        $str .= "<td width=\"8%\" class=\"text-center\">";
        if ($matchPct > 0) {
            $str .= '<span class="badge ' . $badgeClass . '">' . $matchPct . '%</span>';
        } else {
            $str .= '<span class="text-muted">—</span>';
        }
        $str .= "</td></tr>";

        // Hidden detail row — revealed by openClose()
        $str .= "<tr>
            <td colspan=\"4\">
                <div id=\"row_$safeId\" style=\"display:none\">" . $this->getAsCheckBoxTable() . "</div>
            </td>
        </tr>";

        return $str;
    }

}