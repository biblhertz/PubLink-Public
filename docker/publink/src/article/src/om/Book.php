<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\Reference;
use Biblhertz\Publink\utilities\Utilities;
use XmlWriter;
use SimpleXMLElement;

/**
 * Book
 *
 * Represents a book reference within the PubLink article model.
 * Extends {@see Reference} with book-specific fields: volume, edition, and
 * publication number.
 *
 * On construction, the publication type is set to `"book"` and the BibTeX
 * type to `"book"`, ensuring correct handling during import and export.
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
class Book extends Reference {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string Volume number of the book. */
    protected string $volume = "";

    /**
     * Edition of the book (e.g. `"2nd"`, `"Revised"`).
     * Whitespace is trimmed on assignment.
     *
     * @var string
     */
    protected string $edition = "";

    /**
     * Publication number, used in some series-based publications.
     * Whitespace is trimmed on assignment.
     *
     * @var string
     */
    protected string $number = "";


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a Book reference.
     *
     * Calls the parent {@see Reference} constructor and sets the publication
     * type and BibTeX type to `"book"`.
     */
    public function __construct() {
        parent::__construct();
        $this->setPublicationType("book");
        $this->setBibtexType("book");
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the volume number.
     *
     * @param  string $s  Volume number.
     * @return void
     */
    public function setVolume(string $s): void {
        $this->volume = $s;
    }

    /**
     * Get the volume number.
     *
     * @return string
     */
    public function getVolume(): string {
        return $this->volume;
    }

    /**
     * Set the edition string (e.g. `"2nd"`, `"Revised"`).
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $s  Edition string.
     * @return void
     */
    public function setEdition(string $s): void {
        $this->edition = trim($s);
    }

    /**
     * Get the edition string.
     *
     * @return string
     */
    public function getEdition(): string {
        return $this->edition;
    }

    /**
     * Set the publication number.
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $s  Publication number.
     * @return void
     */
    public function setNumber(string $s): void {
        $this->number = trim($s);
    }

    /**
     * Get the publication number.
     *
     * @return string
     */
    public function getNumber(): string {
        return $this->number;
    }


    /****************************************************************/
    /* ABSTRACT INHERITED METHODS                                   */
    /****************************************************************/

    /**
     * Populate this Book from a JATS XML `<ref>` citation fragment.
     *
     * Extracts the following elements if present:
     * - `<source>`        → title (book title in JATS element-citation)
     * - `<volume>`        → volume
     * - `<article-title>` → title (overrides `<source>` if both are present)
     *
     * Values are passed through {@see Utilities::to_utf()} to ensure correct
     * UTF-8 encoding.
     *
     * @param  SimpleXMLElement $xml  The JATS citation XML fragment to parse.
     * @return void
     */
    public function createFromJatsXMLFragment(SimpleXMLElement $xml): void {
        if (isset($xml->{'source'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'source'})));

        if (isset($xml->{'volume'}))
            $this->setVolume(Utilities::to_utf((string) $xml->{'volume'}));

        if (isset($xml->{'article-title'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'article-title'})));

        // Fallback: if neither source nor article-title yielded a title, use <italic> content
        if (empty($this->getTitle()) && isset($xml->{'italic'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'italic'})));
    }

    /**
     * Return the CrossRef API filter string for book-type queries.
     *
     * Used by {@see CrossRefAdapter} to restrict search results to book
     * publication types.
     *
     * @return string  Comma-separated CrossRef type filter.
     */
    public function getFilterType(): string {
        return "type:book,type:edited-book,type:reference-book";
    }

    /**
     * Populate this Book's fields from a parsed BibTeX field array.
     *
     * Delegates common reference fields to {@see Reference::updateFromBibtex()},
     * then handles book-specific fields:
     * - `editor`  → parsed via {@see Author::parseBibtexAuthors()} into the editor list
     * - `volume`  → volume number
     * - `isbn`    → ISBN
     * - `edition` → edition string
     * - `number`  → publication number
     *
     * Page range handling is delegated to {@see Reference::updatePages()}.
     *
     * @param  array $vals  Associative array of BibTeX field names → values.
     * @return void
     */
    public function updateFromBibtex(array $vals): void {
        parent::updateFromBibtex($vals);

        $this->updatePages($vals);

        if (isset($vals['editor'])) {
            $this->editors    = Author::parseBibtexAuthors($vals['editor']);
            $this->editorList = $this->getPersonList($this->editors, true);
        }

        if (isset($vals['volume']))  $this->setVolume($vals['volume']);
        if (isset($vals['isbn']))    $this->setIsbn($vals['isbn']);
        if (isset($vals['edition'])) $this->setEdition($vals['edition']);
        if (isset($vals['number']))  $this->setNumber($vals['number']);
    }

    /**
     * Serialise this Book reference to a JATS `<element-citation>` XML block.
     *
     * Produces a `<ref>` element containing:
     * - `<label>`            — citation label
     * - `<element-citation>` — with `publication-type` attribute set to `"book"`
     *   - `<person-group person-group-type="author">` — author names (if any)
     *   - `<person-group person-group-type="editor">` — editor names (if any)
     *   - `<year>`           — publication year
     *   - `<source>`         — book title
     *   - `<edition>`        — edition string (if set)
     *   - `<volume>`         — volume number (if set)
     *   - `<publisher-name>` — publisher (if set)
     *   - `<publisher-loc>`  — publisher location (if set)
     *   - `<pub-id>`         — DOI via {@see Reference::getJatsDOI()}; ISBN if set
     *
     * If an {@see XmlWriter} is already set on this object it is reused and the
     * method returns `true`. Otherwise a standalone XML document is created,
     * flushed to a string, and returned.
     *
     * @return string|true  The serialised XML string when writing standalone,
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
            $xmlWriter->startElement("source");
            $xmlWriter->writeRaw($title);
            $xmlWriter->endElement();
        }

        $edition = $this->getEdition();
        if (!empty($edition)) {
            $xmlWriter->startElement("edition");
            $xmlWriter->writeRaw($edition);
            $xmlWriter->endElement();
        }

        $volume = $this->getVolume();
        if (!empty($volume)) {
            $xmlWriter->startElement("volume");
            $xmlWriter->writeRaw($volume);
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

        $isbn = $this->getIsbn();
        if (!empty($isbn)) {
            $xmlWriter->startElement("pub-id");
            $xmlWriter->writeAttribute("pub-id-type", "isbn");
            $xmlWriter->writeRaw($isbn);
            $xmlWriter->endElement();
        }

        $xmlWriter->endElement(); // </element-citation>
        $xmlWriter->endElement(); // </ref>

        if ($flush) return $xmlWriter->flush();
        return true;
    }
}
?>