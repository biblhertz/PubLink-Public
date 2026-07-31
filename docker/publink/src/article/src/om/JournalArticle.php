<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\Reference;
use Biblhertz\Publink\utilities\Utilities;
use XmlWriter;
use SimpleXMLElement;

/**
 * JournalArticle
 *
 * Represents a journal article reference within the PubLink article model.
 * Extends {@see Reference} with journal-specific fields: journal name, volume,
 * issue number, and page range.
 *
 * On construction, the publication type is set to `"article-journal"` and the
 * BibTeX type to `"article"`.
 *
 * Field mapping notes:
 * - `$journal` holds the journal title, normalised through
 *   {@see Utilities::renderBibtexTitle()} on assignment. During BibTeX import,
 *   both `journal` and `journaltitle` fields are accepted, with `journal`
 *   taking priority.
 * - `$number` corresponds to the journal issue number (mapped to `<issue>` in
 *   JATS and `number` in BibTeX).
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
class JournalArticle extends Reference {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string Journal volume number. */
    private string $volume = "";

    /**
     * Journal issue number.
     * Stored as `$number` internally (BibTeX convention), but serialised as
     * `<issue>` in JATS XML output.
     *
     * @var string
     */
    private string $number = "";

    /** @var string First page of the article within the issue. */
    private string $firstPage = "";

    /** @var string Last page of the article within the issue. */
    private string $lastPage = "";

    /**
     * Journal title, normalised through {@see Utilities::renderBibtexTitle()}
     * on assignment. Maps to the JATS `<source>` element.
     *
     * @var string
     */
    private string $journal = "";


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a JournalArticle reference.
     *
     * Calls the parent {@see Reference} constructor and sets the publication
     * type to `"article-journal"` and BibTeX type to `"article"`.
     */
    public function __construct() {
        parent::__construct();
        $this->setPublicationType("article-journal");
        $this->setBibtexType("article");
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the journal volume number.
     *
     * @param  string $s  Volume number.
     * @return void
     */
    public function setVolume(string $s): void {
        $this->volume = $s;
    }

    /**
     * Get the journal volume number.
     *
     * @return string
     */
    public function getVolume(): string {
        return $this->volume;
    }

    /**
     * Set the first page of the article.
     *
     * @param  string $p  First page number or label.
     * @return void
     */
    public function setFirstPage(string $p): void {
        $this->firstPage = $p;
    }

    /**
     * Get the first page of the article.
     *
     * @return string
     */
    public function getFirstPage(): string {
        return $this->firstPage;
    }

    /**
     * Set the last page of the article.
     *
     * @param  string $p  Last page number or label.
     * @return void
     */
    public function setLastPage(string $p): void {
        $this->lastPage = $p;
    }

    /**
     * Get the last page of the article.
     *
     * @return string
     */
    public function getLastPage(): string {
        return $this->lastPage;
    }

    /**
     * Set the journal title.
     *
     * The value is passed through {@see Utilities::renderBibtexTitle()} to
     * normalise BibTeX special characters and formatting before storage.
     *
     * @param  string $p  Journal title string.
     * @return void
     */
    public function setJournal(string $p): void {
        $this->journal = Utilities::renderBibtexTitle($p);
    }

    /**
     * Get the journal title.
     *
     * @return string
     */
    public function getJournal(): string {
        return $this->journal;
    }

    /**
     * Set the journal issue number.
     *
     * @param  string $p  Issue number.
     * @return void
     */
    public function setNumber(string $p): void {
        $this->number = $p;
    }

    /**
     * Get the journal issue number.
     *
     * @return string
     */
    public function getNumber(): string {
        return $this->number;
    }


    /****************************************************************/
    /* INHERITED METHODS                                            */
    /****************************************************************/

    /**
     * Populate this JournalArticle from a JATS XML `<ref>` citation fragment.
     *
     * Extracts the following elements if present:
     * - `<article-title>` → article title
     * - `<fpage>`         → first page
     * - `<lpage>`         → last page
     * - `<volume>`        → volume number
     * - `<source>`        → journal title
     *
     * All values are passed through {@see Utilities::to_utf()} for UTF-8
     * normalisation.
     *
     * @param  SimpleXMLElement $xml  The JATS citation XML fragment to parse.
     * @return void
     */
    public function createFromJatsXMLFragment(SimpleXMLElement $xml): void {
        if (isset($xml->{'article-title'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'article-title'})));

        if (isset($xml->{'fpage'}))
            $this->setFirstPage(Utilities::to_utf((string) $xml->{'fpage'}));

        if (isset($xml->{'lpage'}))
            $this->setLastPage(Utilities::to_utf((string) $xml->{'lpage'}));

        if (isset($xml->{'volume'}))
            $this->setVolume(Utilities::to_utf((string) $xml->{'volume'}));

        // <source> maps to journal title in journal article citations
        if (isset($xml->{'source'}))
            $this->setJournal(Utilities::to_utf(self::innerText($xml->{'source'})));

        // Fallback: if no article-title or source yielded a title, use <italic> content
        if (empty($this->getTitle()) && isset($xml->{'italic'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'italic'})));
    }

    /**
     * Return the CrossRef API filter string for journal article queries.
     *
     * Used by {@see CrossRefAdapter} to restrict results to journal article
     * publication types.
     *
     * @return string  CrossRef type filter string.
     */
    public function getFilterType(): string {
        return "type:journal-article";
    }

    /**
     * Populate this JournalArticle's fields from a parsed BibTeX field array.
     *
     * Delegates common reference fields to {@see Reference::updateFromBibtex()}
     * and page range handling to {@see Reference::updatePages()}, then handles
     * journal-specific BibTeX fields:
     * - `volume`       → volume number
     * - `number`       → issue number
     * - `journal`      → journal title (takes priority over `journaltitle`)
     * - `journaltitle` → journal title (BibBLaTeX variant; used as fallback if
     *                    `journal` is absent)
     *
     * Fields are only updated when non-empty.
     *
     * @param  array $vals  Associative array of BibTeX field names → values.
     * @return void
     */
    public function updateFromBibtex(array $vals): void {
        parent::updateFromBibtex($vals);

        if (!empty($vals['volume']))  $this->setVolume($vals['volume']);
        if (!empty($vals['number']))  $this->setNumber($vals['number']);

        $this->updatePages($vals);

        // Accept both 'journal' (BibTeX) and 'journaltitle' (BibLaTeX)
        if (!empty($vals['journal'])) {
            $this->setJournal($vals['journal']);
        } elseif (!empty($vals['journaltitle'])) {
            $this->setJournal($vals['journaltitle']);
        }
    }

    /**
     * Serialise this JournalArticle to a JATS `<element-citation>` XML block.
     *
     * Produces a `<ref>` element containing:
     * - `<label>`            — citation label
     * - `<element-citation>` — with `publication-type` set to `"article-journal"`
     *   - `<person-group>`   — author names as `<n>` elements (if authors exist)
     *   - `<year>`           — publication year (omitted if empty)
     *   - `<article-title>`  — article title (omitted if empty)
     *   - `<source>`         — journal title (omitted if empty)
     *   - `<volume>`         — volume number (omitted if empty)
     *   - `<issue>`          — issue number (omitted if empty)
     *   - `<fpage>`          — first page (omitted if empty)
     *   - `<lpage>`          — last page (omitted if empty)
     *   - DOI element        — via {@see Reference::getJatsDOI()} (if present)
     *
     * All optional elements are guarded by non-empty checks and omitted entirely
     * when their values are not set.
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

        // All remaining fields are optional — only written when non-empty
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

        // Journal title maps to <source> in JATS journal article citations
        $journal = $this->getJournal();
        if (!empty($journal)) {
            $xmlWriter->startElement("source");
            $xmlWriter->writeRaw($journal);
            $xmlWriter->endElement();
        }

        $volume = $this->getVolume();
        if (!empty($volume)) {
            $xmlWriter->startElement("volume");
            $xmlWriter->writeRaw($volume);
            $xmlWriter->endElement();
        }

        // Issue number maps to <issue> in JATS (stored as $number internally)
        $number = $this->getNumber();
        if (!empty($number)) {
            $xmlWriter->startElement("issue");
            $xmlWriter->writeRaw($number);
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

        $this->getJatsDOI($xmlWriter);

        $xmlWriter->endElement(); // </element-citation>
        $xmlWriter->endElement(); // </ref>

        if ($flush) return $xmlWriter->flush();
        return true;
    }
}
?>