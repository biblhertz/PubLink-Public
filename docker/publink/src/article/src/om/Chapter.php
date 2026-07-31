<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\Book;
use Biblhertz\Publink\utilities\Utilities;
use XmlWriter;
use SimpleXMLElement;

/**
 * Chapter
 *
 * Represents a book chapter (or contribution to an edited collection) within
 * the PubLink article model. Extends {@see Book} with chapter-specific fields:
 * chapter title, first page, and last page.
 *
 * On construction, the publication type is set to `"chapter"` and the BibTeX
 * type to `"incollection"`. The field mappings are also adjusted so that:
 * - The inherited `title` property maps to the BibTeX `booktitle` field (the
 *   containing book's title).
 * - The `chapterTitle` property maps to the BibTeX `title` field (the chapter's
 *   own title).
 *
 * This distinction is important during BibTeX import/export and when building
 * API search queries, where chapter title and book title serve different roles.
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
class Chapter extends Book {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * First page of the chapter within the containing book.
     * Whitespace is trimmed on assignment.
     *
     * @var string
     */
    private string $firstPage = "";

    /**
     * Last page of the chapter within the containing book.
     * Whitespace is trimmed on assignment.
     *
     * @var string
     */
    private string $lastPage = "";

    /**
     * Title of the chapter itself (as distinct from the containing book title,
     * which is stored in the inherited `$title` property).
     * Surrounding braces are stripped and the value is title-cased on assignment.
     *
     * @var string
     */
    private string $chapterTitle = "";

    /**
     * Authors specific to this chapter, if different from the book's editors
     * or top-level author list. Currently unused in export methods.
     *
     * @var Author[]
     */
    private array $chapterAuthors = [];


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a Chapter reference.
     *
     * Calls the parent {@see Book} constructor, then overrides the publication
     * type to `"chapter"` and BibTeX type to `"incollection"`. Also remaps the
     * inherited field mappings so that `Title` → `booktitle` and
     * `ChapterTitle` → `title` for BibTeX round-tripping.
     */
    public function __construct() {
        parent::__construct();
        $this->setPublicationType("chapter");
        $this->setBibtexType("incollection");
        $this->mappings["Title"]        = "booktitle";
        $this->mappings["ChapterTitle"] = "title";
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the first page of the chapter.
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $p  First page number or label.
     * @return void
     */
    public function setFirstPage(string $p): void {
        $this->firstPage = trim($p);
    }

    /**
     * Get the first page of the chapter.
     *
     * @return string
     */
    public function getFirstPage(): string {
        return $this->firstPage;
    }

    /**
     * Set the last page of the chapter.
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $p  Last page number or label.
     * @return void
     */
    public function setLastPage(string $p): void {
        $this->lastPage = trim($p);
    }

    /**
     * Get the last page of the chapter.
     *
     * @return string
     */
    public function getLastPage(): string {
        return $this->lastPage;
    }

    /**
     * Set the chapter title.
     *
     * Surrounding curly braces (common in BibTeX) are stripped and the result
     * is title-cased via `ucwords()` before storage.
     *
     * @param  string $p  Chapter title, optionally wrapped in BibTeX braces.
     * @return void
     */
    public function setChapterTitle(string $p): void {
        $this->chapterTitle = ucwords(trim($p, "{}"));
    }

    /**
     * Get the chapter title.
     *
     * @return string
     */
    public function getChapterTitle(): string {
        return $this->chapterTitle;
    }


    /****************************************************************/
    /* INHERITED METHODS                                            */
    /****************************************************************/

    /**
     * Populate this Chapter from a JATS XML `<ref>` citation fragment.
     *
     * Does NOT delegate to {@see Book::createFromJatsXMLFragment()} because
     * Book maps `<article-title>` to the book title, which is incorrect for
     * chapters where `<article-title>` carries the chapter title. All fields
     * are handled explicitly here.
     *
     * Field mapping:
     * - `<source>`       → book title (inherited `$title`)
     * - `<volume>`       → volume number
     * - `<fpage>`        → first page
     * - `<lpage>`        → last page
     * - `<article-title>`→ chapter title (canonical; matches JATS round-trip output)
     * - `<part-title>`   → chapter title fallback for older/variant JATS files
     *
     * Italic fallback: if `<italic>` is present and a title is still missing,
     * it is applied to the chapter title first, then the book title.
     *
     * All values are passed through {@see Utilities::to_utf()} for UTF-8 normalisation.
     *
     * @param  SimpleXMLElement $xml  The JATS citation XML fragment to parse.
     * @return void
     */
    public function createFromJatsXMLFragment(SimpleXMLElement $xml): void {
        // Book (volume) title
        if (isset($xml->{'source'}))
            $this->setTitle(Utilities::to_utf(self::innerText($xml->{'source'})));

        if (isset($xml->{'volume'}))
            $this->setVolume(Utilities::to_utf((string) $xml->{'volume'}));

        if (isset($xml->{'fpage'}))
            $this->setFirstPage(Utilities::to_utf((string) $xml->{'fpage'}));

        if (isset($xml->{'lpage'}))
            $this->setLastPage(Utilities::to_utf((string) $xml->{'lpage'}));

        // Chapter title: <article-title> is canonical (matches getJATSReference output);
        // <part-title> is accepted as an alternative from older or variant JATS files
        if (isset($xml->{'article-title'}))
            $this->setChapterTitle(Utilities::to_utf(self::innerText($xml->{'article-title'})));
        elseif (isset($xml->{'part-title'}))
            $this->setChapterTitle(Utilities::to_utf(self::innerText($xml->{'part-title'})));

        // Italic fallback: fill the most specific missing title first
        if (isset($xml->{'italic'})) {
            $italic = Utilities::to_utf(self::innerText($xml->{'italic'}));
            if (empty($this->getChapterTitle()))
                $this->setChapterTitle($italic);
            elseif (empty($this->getTitle()))
                $this->setTitle($italic);
        }
    }

    /**
     * Return the CrossRef API filter string for chapter-type queries.
     *
     * Used by {@see CrossRefAdapter} to restrict search results to chapter
     * and book-section publication types.
     *
     * @return string  Comma-separated CrossRef type filter.
     */
    public function getFilterType(): string {
        return "type:book-chapter,type:book-section,type:book-part";
    }

    /**
     * Populate this Chapter's fields from a parsed BibTeX field array.
     *
     * Delegates common reference and book fields to {@see Book::updateFromBibtex()},
     * then handles chapter-specific BibTeX fields:
     * - `booktitle` → book title (stored in inherited `$title`), rendered via
     *   {@see Utilities::renderBibtexTitle()}
     * - `title`     → chapter title, rendered via {@see Utilities::renderBibtexTitle()}
     *
     * Page range handling is delegated to {@see Reference::updatePages()}.
     *
     * @param  array $vals  Associative array of BibTeX field names → values.
     * @return void
     */
    public function updateFromBibtex(array $vals): void {
        parent::updateFromBibtex($vals);

        if (isset($vals['booktitle']))
            $this->setTitle(Utilities::renderBibtexTitle($vals['booktitle']));

        if (isset($vals['title']))
            $this->setChapterTitle(Utilities::renderBibtexTitle($vals['title']));
    }

    /**
     * Serialise this Chapter reference to a JATS `<element-citation>` XML block.
     *
     * Produces a `<ref>` element containing:
     * - `<label>`            — citation label
     * - `<element-citation>` — with `publication-type` attribute set to `"chapter"`
     *   - `<person-group person-group-type="author">` — author names (if any)
     *   - `<person-group person-group-type="editor">` — editor names (if any)
     *   - `<year>`           — publication year
     *   - `<article-title>`  — chapter title
     *   - `<source>`         — containing book title
     *   - `<edition>`        — edition string (if set)
     *   - `<volume>`         — volume number (if set)
     *   - `<fpage>`          — first page (if set)
     *   - `<lpage>`          — last page (if set)
     *   - `<publisher-name>` — publisher (if set)
     *   - `<publisher-loc>`  — publisher location (if set)
     *   - `<pub-id>`         — DOI via {@see Reference::getJatsDOI()}; ISBN if set
     *
     * If an {@see XmlWriter} is already set on this object, it is reused and the
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

        // Chapter title maps to <article-title> in JATS element-citation
        $ct = $this->getChapterTitle();
        if (!empty($ct)) {
            $xmlWriter->startElement("article-title");
            $xmlWriter->writeRaw($ct);
            $xmlWriter->endElement();
        }

        // Containing book title maps to <source>
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