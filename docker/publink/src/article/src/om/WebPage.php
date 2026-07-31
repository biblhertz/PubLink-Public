<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\Reference;
use Biblhertz\Publink\utilities\Utilities;
use XmlWriter;
use SimpleXMLElement;

/**
 * WebPage
 *
 * Represents a web page or online resource reference within the PubLink
 * article model. Extends {@see Reference} with no additional fields; all
 * web-specific data (URL, title, year) is stored in inherited properties.
 *
 * On construction, the publication type is set to `"web-page"` and the
 * BibTeX type to `"misc"`.
 *
 * Field mapping notes:
 * - The inherited `$title` property holds the page or resource title,
 *   serialised as `<source>` in JATS output.
 * - The inherited `$uri` property holds the URL, serialised as `<uri>`.
 * - JATS parsing uses a fallback chain for the title since web citations
 *   may use varying elements depending on the originating system.
 *
 * Supports:
 * - Parsing from a JATS XML `<ref>` fragment via {@see createFromJatsXMLFragment()}
 * - Serialisation to a JATS `<element-citation>` XML block via {@see getJATSReference()}
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class WebPage extends Reference {

    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a WebPage reference.
     *
     * Calls the parent {@see Reference} constructor and sets the publication
     * type to `"web-page"` and BibTeX type to `"misc"`.
     */
    public function __construct() {
        parent::__construct();
        $this->setPublicationType("web-page");
        $this->setBibtexType("misc");
    }


    /****************************************************************/
    /* INHERITED METHODS                                            */
    /****************************************************************/

    /**
     * Populate this WebPage from a JATS XML `<ref>` citation fragment.
     *
     * Uses a fallback chain for the title, since web citations may use
     * different elements depending on the originating reference manager:
     * 1. `<source>`        — preferred title element for web references
     * 2. `<article-title>` — used if `<source>` is absent
     * 3. `<collab>`        — used if both above are absent (e.g. a corporate author
     *                        or collaborative body used as the resource title)
     *
     * Year is extracted directly from `<year>` if present.
     *
     * All values are passed through {@see Utilities::to_utf()} for UTF-8
     * normalisation.
     *
     * @param  SimpleXMLElement $xml  The JATS citation XML fragment to parse.
     * @return void
     */
    public function createFromJatsXMLFragment(SimpleXMLElement $xml): void {
        // Title fallback chain: source → article-title → collab → italic
        if (isset($xml->{'source'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'source'})));
        elseif (isset($xml->{'article-title'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'article-title'})));
        elseif (isset($xml->{'collab'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'collab'})));
        elseif (isset($xml->{'italic'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'italic'})));

        if (isset($xml->{'year'}))
            $this->setYear(Utilities::to_utf((string) $xml->{'year'}));
    }

    /**
     * Return the CrossRef API filter string for web page / online content queries.
     *
     * Maps to the CrossRef `posted-content` type, which covers preprints and
     * other online-first or web-published material.
     *
     * @return string  CrossRef type filter string.
     */
    public function getFilterType(): string {
        return "type:posted-content";
    }

    /**
     * Serialise this WebPage to a JATS `<element-citation>` XML block.
     *
     * Produces a `<ref>` element containing:
     * - `<label>`            — citation label
     * - `<element-citation>` — with `publication-type` set to `"web-page"`
     *   - `<person-group>`   — author names as `<n>` elements (if authors exist)
     *   - `<year>`           — publication or access year (omitted if empty)
     *   - `<source>`         — page or resource title (omitted if empty)
     *   - `<uri>`            — URL of the resource (omitted if empty)
     *   - DOI element        — via {@see Reference::getJatsDOI()} (if present)
     *
     * Note: the title is serialised as `<source>` rather than `<article-title>`
     * in JATS web citations, consistent with how the title is parsed in
     * {@see createFromJatsXMLFragment()}.
     *
     * All optional elements are omitted when their values are empty.
     *
     * If an {@see XmlWriter} is already set on this object, it is reused and
     * the method returns `true`. Otherwise a standalone XML document is created,
     * flushed to a string, and returned.
     *
     * @return string|true  Serialised XML string when writing standalone,
     *                      or `true` when appending to an existing XmlWriter.
     */
    public function getJATSReference(): string|true {
        $flush = false;

        if (!isset($this->xmlWriter)) {
            $xmlWriter = new XmlWriter();
            $xmlWriter->openMemory();
            $xmlWriter->startDocument();
            $xmlWriter->setIndent(true);
            $flush = true;
        } else {
            $xmlWriter = $this->xmlWriter;
        }

        $xmlWriter->startElement("ref");
        $jatsID = $this->getJatsID();
        if (!empty($jatsID)) {
            $xmlWriter->writeAttribute("id", $jatsID);
        }

        $xmlWriter->startElement("label");
        $xmlWriter->writeRaw($this->getLabel());
        $xmlWriter->endElement();

        $xmlWriter->startElement("element-citation");
        $xmlWriter->writeAttribute("publication-type", $this->getPublicationType());

        // Write author person-group if authors are present
        if (count($this->authors)) {
            $xmlWriter->startElement("person-group");
            $xmlWriter->writeAttribute("person-group-type", "author");
            foreach ($this->authors as $author) {
                $xmlWriter->startElement("name");
                $xmlWriter->startElement("surname");
                $xmlWriter->writeRaw($author->getLastName());
                $xmlWriter->endElement();
                $xmlWriter->startElement("given-names");
                $xmlWriter->writeRaw($author->getFirstName());
                $xmlWriter->endElement();
                $xmlWriter->endElement(); // </name>
            }
            $xmlWriter->endElement(); // </person-group>
        }

        $year = $this->getYear();
        if (!empty($year)) {
            $xmlWriter->startElement("year");
            $xmlWriter->writeRaw($year);
            $xmlWriter->endElement();
        }

        // Title serialises as <source> in JATS web citations
        $title = $this->getTitle();
        if (!empty($title)) {
            $xmlWriter->startElement("source");
            $xmlWriter->writeRaw($title);
            $xmlWriter->endElement();
        }

        $publisher = $this->getPublisher();
        if (!empty($publisher)) {
            $xmlWriter->startElement("publisher-name");
            $xmlWriter->writeRaw($publisher);
            $xmlWriter->endElement();
        }

        $address = $this->getAddress();
        if (!empty($address)) {
            $xmlWriter->startElement("publisher-loc");
            $xmlWriter->writeRaw($address);
            $xmlWriter->endElement();
        }

        $uri = $this->getURI();
        if (!empty($uri)) {
            $xmlWriter->startElement("uri");
            $xmlWriter->writeRaw($uri);
            $xmlWriter->endElement();
        }

        $note = $this->getNote();
        if (!empty($note)) {
            $xmlWriter->startElement("comment");
            $xmlWriter->writeRaw($note);
            $xmlWriter->endElement();
        }

        $this->getJatsDOI($xmlWriter);

        $xmlWriter->endElement(); // </element-citation>
        $xmlWriter->endElement(); // </ref>

        if ($flush) return $xmlWriter->flush();
        return true;
    }
}
?>