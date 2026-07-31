<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\Reference;
use Biblhertz\Publink\utilities\Utilities;
use XmlWriter;
use SimpleXMLElement;

/**
 * ConferencePaper
 *
 * Represents a conference paper (proceedings article) within the PubLink
 * article model. Extends {@see Reference} with conference-specific fields:
 * paper title, conference name, proceedings book title, and page range.
 *
 * On construction, the publication type is set to `"paper-conference"` and
 * the BibTeX type to `"inproceedings"`.
 *
 * Field mapping notes:
 * - The inherited `$title` property holds the paper title.
 * - `$conference` holds the conference or proceedings series name, mapped
 *   from the JATS `<source>` element or the BibTeX `conference` field.
 * - `$bookTitle` holds the proceedings volume title from the BibTeX
 *   `booktitle` field. If `$conference` is not separately set, `booktitle`
 *   is used as a fallback for the conference name.
 *
 * Supports:
 * - Parsing from a JATS XML `<ref>` fragment via {@see createFromJatsXMLFragment()}
 * - Population from a BibTeX field array via {@see updateFromBibtex()}
 * - Serialisation to a JATS `<element-citation>` XML block via {@see getJATSReference()}
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class ConferencePaper extends Reference {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * First page of the paper within the proceedings volume.
     *
     * @var string
     */
    private string $firstPage = "";

    /**
     * Last page of the paper within the proceedings volume.
     *
     * @var string
     */
    private string $lastPage = "";

    /**
     * Name of the conference or proceedings series.
     * Mapped from the JATS `<source>` element or the BibTeX `conference`
     * field. Falls back to `$bookTitle` during BibTeX import if not set.
     * Whitespace is trimmed on assignment.
     *
     * @var string
     */
    private string $conference = "";

    /**
     * Title of the proceedings volume (from BibTeX `booktitle`).
     * Distinct from `$conference`, which holds the event/series name.
     * Whitespace is trimmed on assignment.
     *
     * @var string
     */
    private string $bookTitle = "";


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a ConferencePaper reference.
     *
     * Calls the parent {@see Reference} constructor and sets the publication
     * type to `"paper-conference"` and BibTeX type to `"inproceedings"`.
     */
    public function __construct() {
        parent::__construct();
        $this->setPublicationType("paper-conference");
        $this->setBibtexType("inproceedings");
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the first page of the paper.
     *
     * @param  string $p  First page number or label.
     * @return void
     */
    public function setFirstPage(string $p): void {
        $this->firstPage = $p;
    }

    /**
     * Get the first page of the paper.
     *
     * @return string
     */
    public function getFirstPage(): string {
        return $this->firstPage;
    }

    /**
     * Set the last page of the paper.
     *
     * @param  string $p  Last page number or label.
     * @return void
     */
    public function setLastPage(string $p): void {
        $this->lastPage = $p;
    }

    /**
     * Get the last page of the paper.
     *
     * @return string
     */
    public function getLastPage(): string {
        return $this->lastPage;
    }

    /**
     * Set the conference or proceedings series name.
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $p  Conference name.
     * @return void
     */
    public function setConference(string $p): void {
        $this->conference = trim($p);
    }

    /**
     * Get the conference or proceedings series name.
     *
     * @return string
     */
    public function getConference(): string {
        return $this->conference;
    }

    /**
     * Set the proceedings volume title (BibTeX `booktitle`).
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $p  Proceedings volume title.
     * @return void
     */
    public function setBookTitle(string $p): void {
        $this->bookTitle = trim($p);
    }

    /**
     * Get the proceedings volume title.
     *
     * @return string
     */
    public function getBookTitle(): string {
        return $this->bookTitle;
    }


    /****************************************************************/
    /* INHERITED METHODS                                            */
    /****************************************************************/

    /**
     * Populate this ConferencePaper from a JATS XML `<ref>` citation fragment.
     *
     * Extracts the following elements:
     * - `<part-title>`    → paper title (takes priority over `<article-title>`)
     * - `<article-title>` → paper title (used if `<part-title>` is absent)
     * - `<fpage>`         → first page
     * - `<lpage>`         → last page
     * - `<source>`        → conference name
     *
     * All values are passed through {@see Utilities::to_utf()} for UTF-8
     * normalisation.
     *
     * @param  SimpleXMLElement $xml  The JATS citation XML fragment to parse.
     * @return void
     */
    public function createFromJatsXMLFragment(SimpleXMLElement $xml): void {
        // <part-title> takes priority; fall back to <article-title>, then <italic>
        if (isset($xml->{'part-title'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'part-title'})));
        elseif (isset($xml->{'article-title'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'article-title'})));
        elseif (isset($xml->{'italic'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'italic'})));

        if (isset($xml->{'fpage'}))
            $this->setFirstPage(Utilities::to_utf((string) $xml->{'fpage'}));

        if (isset($xml->{'lpage'}))
            $this->setLastPage(Utilities::to_utf((string) $xml->{'lpage'}));

        // <source> maps to conference name in proceedings citations
        if (isset($xml->{'source'}))
            $this->setConference(Utilities::to_utf(self::innerText($xml->{'source'})));
    }

    /**
     * Return the CrossRef API filter string for conference paper queries.
     *
     * Used by {@see CrossRefAdapter} to restrict results to proceedings
     * publication types.
     *
     * @return string  Comma-separated CrossRef type filter.
     */
    public function getFilterType(): string {
        return "type:proceedings-article,type:proceedings,type:proceedings-series";
    }

    /**
     * Populate this ConferencePaper's fields from a parsed BibTeX field array.
     *
     * Delegates common reference fields to {@see Reference::updateFromBibtex()}
     * and page range handling to {@see Reference::updatePages()}, then handles
     * conference-specific BibTeX fields:
     * - `conference` → conference name, rendered via {@see Utilities::renderBibtexTitle()}
     * - `booktitle`  → proceedings volume title; also used as a fallback for
     *                  the conference name if `$conference` is not yet set.
     *
     * @param  array $vals  Associative array of BibTeX field names → values.
     * @return void
     */
    public function updateFromBibtex(array $vals): void {
        parent::updateFromBibtex($vals);
        $this->updatePages($vals);

        if (isset($vals['conference'])) {
            $this->setConference(Utilities::renderBibtexTitle($vals['conference']));
        }

        if (isset($vals['booktitle'])) {
            $this->setBookTitle(Utilities::renderBibtexTitle($vals['booktitle']));
            // Use booktitle as conference name fallback if conference is not set
            if ($this->getConference() === "") {
                $this->setConference(Utilities::renderBibtexTitle($vals['booktitle']));
            }
        }
    }

    /**
     * Serialise this ConferencePaper to a JATS `<element-citation>` XML block.
     *
     * Produces a `<ref>` element containing:
     * - `<label>`            — citation label
     * - `<element-citation>` — with `publication-type` set to `"paper-conference"`
     *   - `<person-group>`   — author names as `<name>` elements (if authors exist)
     *   - `<year>`           — publication year (omitted if not set)
     *   - `<article-title>`  — paper title (omitted if not set)
     *   - `<source>`         — conference name (omitted if not set)
     *   - DOI element        — via {@see Reference::getJatsDOI()} (if present)
     *
     * Unlike {@see Book::getJATSReference()}, optional elements (`<year>`,
     * `<article-title>`, `<source>`) are only written if their values are set,
     * rather than always being written with potentially empty content.
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

        // Authors
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

        // Editors
        $editors = $this->getEditors();
        if (count($editors)) {
            $xmlWriter->startElement("person-group");
            $xmlWriter->writeAttribute("person-group-type", "editor");
            foreach ($editors as $editor) {
                $xmlWriter->startElement("name");
                $xmlWriter->startElement("surname");
                $xmlWriter->writeRaw($editor->getLastName());
                $xmlWriter->endElement();
                $xmlWriter->startElement("given-names");
                $xmlWriter->writeRaw($editor->getFirstName());
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

        $title = $this->getTitle();
        if (!empty($title)) {
            $xmlWriter->startElement("article-title");
            $xmlWriter->writeRaw($title);
            $xmlWriter->endElement();
        }

        // Conference name maps to <source>; fall back to bookTitle if not set
        $conference = $this->getConference();
        if (empty($conference)) $conference = $this->getBookTitle();
        if (!empty($conference)) {
            $xmlWriter->startElement("source");
            $xmlWriter->writeRaw($conference);
            $xmlWriter->endElement();
        }

        $fpage = $this->getFirstPage();
        if (!empty($fpage)) {
            $xmlWriter->startElement("fpage");
            $xmlWriter->writeRaw($fpage);
            $xmlWriter->endElement();
        }

        $lpage = $this->getLastPage();
        if (!empty($lpage)) {
            $xmlWriter->startElement("lpage");
            $xmlWriter->writeRaw($lpage);
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

        $this->getJatsDOI($xmlWriter);

        $xmlWriter->endElement(); // </element-citation>
        $xmlWriter->endElement(); // </ref>

        if ($flush) return $xmlWriter->flush();
        return true;
    }
}
?>