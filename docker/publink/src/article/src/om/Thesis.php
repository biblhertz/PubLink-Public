<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\Reference;
use Biblhertz\Publink\utilities\Utilities;
use XmlWriter;
use SimpleXMLElement;

/**
 * Thesis
 *
 * Represents a thesis or dissertation reference within the PubLink article
 * model. Extends {@see Reference} with no additional fields; all relevant
 * data (title, year, publisher/institution, URI, notes) is stored in
 * inherited properties.
 *
 * On construction, the publication type is set to `"thesis"` and the BibTeX
 * type to `"phdthesis"`.
 *
 * Field mapping notes:
 * - `$publisher` holds the degree-awarding institution name, serialised as
 *   `<publisher-name>` in JATS output. The BibTeX `school` field maps to
 *   `$publisher` during import (handled by {@see Reference::updateFromBibtex()}).
 * - `$title` is serialised as `<source>` in JATS thesis citations (rather
 *   than `<article-title>`), consistent with how the title is parsed in
 *   {@see createFromJatsXMLFragment()}.
 * - `$note` is serialised as a JATS `<comment>` element, suitable for
 *   storing access dates, URLs or other supplementary information.
 *
 * Supports:
 * - Parsing from a JATS XML `<ref>` fragment via {@see createFromJatsXMLFragment()}
 * - Serialisation to a JATS `<element-citation>` XML block via {@see getJATSReference()}
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class Thesis extends Reference {

    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a Thesis reference.
     *
     * Calls the parent {@see Reference} constructor and sets the publication
     * type to `"thesis"` and BibTeX type to `"phdthesis"`.
     */
    public function __construct() {
        parent::__construct();
        $this->setPublicationType("thesis");
        $this->setBibtexType("phdthesis");
    }


    /****************************************************************/
    /* INHERITED METHODS                                            */
    /****************************************************************/

    /**
     * Populate this Thesis from a JATS XML `<ref>` citation fragment.
     *
     * Uses a fallback chain for the title:
     * 1. `<article-title>` — used when present (thesis title in some schemas)
     * 2. `<source>`        — used as fallback if `<article-title>` is absent
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
        // Title fallback: article-title → source → italic
        if (isset($xml->{'article-title'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'article-title'})));
        elseif (isset($xml->{'source'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'source'})));
        elseif (isset($xml->{'italic'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'italic'})));

        if (isset($xml->{'year'}))
            $this->setYear(Utilities::to_utf((string) $xml->{'year'}));
    }

    /**
     * Return the CrossRef API filter string for thesis queries.
     *
     * @return string  CrossRef type filter string.
     */
    public function getFilterType(): string {
        return "type:dissertation";
    }

    /**
     * Serialise this Thesis to a JATS `<element-citation>` XML block.
     *
     * Produces a `<ref>` element containing:
     * - `<label>`            — citation label
     * - `<element-citation>` — with `publication-type` set to `"thesis"`
     *   - `<person-group>`   — author names as `<n>` elements (if authors exist)
     *   - `<year>`           — publication year (omitted if empty)
     *   - `<source>`         — thesis title (omitted if empty)
     *   - `<publisher-name>` — degree-awarding institution (omitted if empty)
     *   - `<uri>`            — URL of the thesis (omitted if empty)
     *   - `<comment>`        — notes field, e.g. access date (omitted if empty)
     *   - DOI element        — via {@see Reference::getJatsDOI()} (if present)
     *
     * Note: `<publisher-loc>` (institution location) is not currently output;
     * the relevant code block is commented out in the original source.
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
            $xmlWriter->writeRaw($this->getYear());
            $xmlWriter->endElement();
        }

        // Thesis title serialises as <source> in JATS thesis citations
        $title = $this->getTitle();
        if (!empty($title)) {
            $xmlWriter->startElement("source");
            $xmlWriter->writeRaw($title);
            $xmlWriter->endElement();
        }

        // Degree-awarding institution serialises as <publisher-name>
        $pub = $this->getPublisher();
        if (!empty($pub)) {
            $xmlWriter->startElement("publisher-name");
            $xmlWriter->writeRaw($pub);
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

        // Notes field (e.g. access date) serialises as <comment>
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