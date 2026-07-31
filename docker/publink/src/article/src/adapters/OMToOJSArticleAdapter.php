<?php

namespace Biblhertz\Article\Adapters;

use XmlWriter;
use Biblhertz\Article\om\GalleyFile;
use Biblhertz\Article\om\Article;
use DomDocument;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Article\utilities\Utilities;
use Biblhertz\Article\Config;

/********************************************************************/
/*      OMToOJSArticleAdapter                                       */
/*                                                                  */
/*      Author  :   Chris Tomlinson                                 */
/*      Date    :   11th July 2023                                  */
/*                                                                  */
/*      Generates OJS Native XML from an Article object model       */
/*                                                                  */
/********************************************************************/

/**
 * Serialises a PubLink Article object model to an OJS Native XML document.
 *
 * Generates a complete OJS Native XML import file from the data held in an
 * {@see Article} object. The output targets Open Journal Systems (OJS) and
 * supports version-specific output differences between OJS 3.3 and 3.4/3.5,
 * controlled via {@see setVersion()} and the {@see $OJS_3_3} / {@see $OJS_3_4}
 * / {@see $OJS_3_5} constants.
 *
 * Output structure:
 * - {@code <article>}: root element with submission attributes
 *   - {@code <submission_file>} entries (JATS XML first, then non-dependent
 *     galleys, then dependent galleys — see {@see writeSubmissionFiles()})
 *   - {@code <publication>}: publication metadata, authors, galley references,
 *     issue metadata (OJS 3.4/3.5 only), page range, and cover image
 *
 * Output mode is determined at construction:
 * - **File mode** (non-empty URI): XML is written directly to the given path.
 * - **Memory mode** (empty URI): XML is returned as a string from {@see generateXML()}.
 *
 * @package  Biblhertz\Article\Adapters
 * @author   Chris Tomlinson
 * @since    2023-07-11
 */
class OMToOJSArticleAdapter {

    /****************************************************************/
    /*  STATIC VARIABLES                                            */
    /****************************************************************/

    /**
     * Version constant for OJS 3.3 output mode.
     * Affects {@code <publication>} locale attribute and subtitle handling.
     *
     * @var int
     */
    public static $OJS_3_3 = 1;

    /**
     * Version constant for OJS 3.4 output mode.
     * Adds {@code access_status}, {@code xsi:schemaLocation}, {@code url_path},
     * and {@code primary_contact_id} attributes to {@code <publication>}, and
     * appends {@code <issue_identification>} inside the publication element.
     *
     * @var int
     */
    public static $OJS_3_4 = 2;

    /**
     * Version constant for OJS 3.5 output mode.
     * Uses the same {@code <publication>} attributes and
     * {@code <issue_identification>} handling as {@see $OJS_3_4}, since the
     * OJS native XML import format is unchanged between 3.4 and 3.5.
     *
     * @var int
     */
    public static $OJS_3_5 = 3;


    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /**
     * The Article object model to serialise into OJS Native XML.
     *
     * @var Article
     */
    private Article $article;

    /**
     * XmlWriter instance used to build the OJS XML output.
     *
     * @var XmlWriter
     */
    private XmlWriter $xmlWriter;

    /**
     * Filesystem URI for the output XML file.
     * An empty string signals in-memory (string return) mode.
     *
     * @var mixed
     */
    private string $uri;

    /**
     * Locale string used for locale-sensitive XML attributes (e.g. {@code "en_US"}).
     * Initialised from the Article's own locale at construction.
     *
     * @var string
     */
    private string $locale = "";

    /**
     * Logger instance for recording adapter activity and validation output.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Target OJS version for the output XML.
     * Should be one of {@see $OJS_3_3}, {@see $OJS_3_4}, or {@see $OJS_3_5}.
     *
     * @var int
     */
    private int $version;


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Constructs the adapter for the given Article and optional output URI.
     *
     * The Article's locale is read immediately and stored for use in all
     * locale-sensitive XML attributes. If {@see $uri} is empty, output will
     * be returned as a string from {@see generateXML()}.
     *
     * @param Article $article The Article to serialise into OJS Native XML.
     * @param mixed   $uri     Filesystem path for the output XML file, or an
     *                         empty string for in-memory mode.
     */
    public function __construct(Article $article, string $uri = "") {
        $this->article   = $article;
        $this->locale    = $article->getLocale();
        $this->uri       = $uri;
        $this->xmlWriter = new XmlWriter();
    }


    /****************************************************************/
    /*  INTERFACE METHODS                                           */
    /****************************************************************/

    /**
     * Sets the Logger instance for recording adapter and validation output.
     *
     * @param Logger $l Logger to use.
     * @return void
     */
    public function setLogger(Logger $l): void {
        $this->logger = $l;
    }

    /**
     * Sets the target OJS version for XML output.
     *
     * Use the class constants {@see $OJS_3_3}, {@see $OJS_3_4}, or
     * {@see $OJS_3_5} as the argument. The version affects which attributes
     * and elements are included in the {@code <publication>} element and
     * whether {@code <issue_identification>} is appended.
     *
     * @param int $v OJS version constant.
     * @return void
     */
    public function setVersion(int $v): void {
        $this->version = $v;
    }


    /****************************************************************/
    /*  OTHER METHODS                                               */
    /****************************************************************/

    /**
     * Generates the OJS Native XML document.
     *
     * Behaviour depends on whether a URI was supplied at construction:
     * - **File mode**: writes XML to the file and returns nothing.
     * - **Memory mode**: builds XML in memory and returns it as a string.
     *
     * @return string OJS Native XML string in memory mode; nothing in file mode.
     */
    public function generateXML(): string|null {
        if ($this->uri !== "") $this->xmlWriter->openUri($this->uri);
        else $this->xmlWriter->openMemory();

        $this->xmlWriter->startDocument();
        $this->xmlWriter->setIndent(true);
        $this->writeArticle();
        $this->xmlWriter->endDocument();

        if ($this->uri !== "") {
            $this->xmlWriter->flush();
            return null;
        }
        return $this->xmlWriter->flush();
    }


    /**
     * Writes the {@code <issue_identification>} element.
     *
     * Contains volume, issue number, year, and journal title (locale-aware).
     * Volume, number, and year are all optional in the OJS schema (each
     * {@code minOccurs="0"}) and are omitted entirely when not set on the
     * Article, since volume/year are typed {@code xs:int} and an empty
     * string is not a valid int value.
     * The {@code <date_published>} element is defined but currently commented out.
     * Used only in OJS 3.4 / 3.5 output (called from {@see writePublication()}).
     *
     * @return void
     */
    private function writeIssueMetadata(): void {
        $this->xmlWriter->startElement("issue_identification");

        if ($this->article->getVolume() !== "") {
            $this->xmlWriter->startElement("volume");
            $this->xmlWriter->writeRaw($this->article->getVolume());
            $this->xmlWriter->endElement();
        }

        if ($this->article->getIssue() !== "") {
            $this->xmlWriter->startElement("number");
            $this->xmlWriter->writeRaw($this->article->getIssue());
            $this->xmlWriter->endElement();
        }

        if ($this->article->getYear() !== 0) {
            $this->xmlWriter->startElement("year");
            $this->xmlWriter->writeRaw($this->article->getYear());
            $this->xmlWriter->endElement();
        }

        $this->xmlWriter->startElement("title");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeRaw($this->article->getJournalName());
        $this->xmlWriter->endElement();

        // <date_published> is intentionally omitted in the current implementation
        // $this->xmlWriter->startElement("date_published");
        // $this->xmlWriter->writeRaw($this->article->getDate());
        // $this->xmlWriter->endElement();

        $this->xmlWriter->endElement(); // </issue_identification>
    }


    /**
     * Writes the {@code <sections>} element with a single hardcoded "Articles" section.
     *
     * The section is written with {@code ref="ART"}, an abbreviated title of "ART",
     * an empty policy element, and a full title of "Articles". All text elements
     * carry the current locale attribute.
     *
     * @return void
     */
    public function writeSection(): void {
        $this->xmlWriter->startElement("sections");
        $this->xmlWriter->startElement("section");
        $this->xmlWriter->writeAttribute("ref", "ART");

        $this->xmlWriter->startElement("abbrev");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeRaw("ART");
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("policy");
        $this->addLocaleAttribute();
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("title");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeRaw("Articles");
        $this->xmlWriter->endElement();

        $this->xmlWriter->endElement(); // </section>
        $this->xmlWriter->endElement(); // </sections>
    }


    /**
     * Writes the {@code <covers>} element for the article's cover image, if present.
     *
     * Retrieves the cover image galley from the Article. If none is set the method
     * returns immediately without writing anything. The image content is embedded as
     * a base64-encoded {@code <embed>} element. The locale attribute is added to
     * the {@code <cover>} element.
     *
     * @return void
     */
    public function writeCover(): void {
        $galley = $this->article->getCoverImageFile();
        if (!$galley) return;

        $this->xmlWriter->startElement("covers");
        $this->xmlWriter->startElement("cover");
        $this->addLocaleAttribute();

        $this->xmlWriter->startElement("cover_image");
        $this->xmlWriter->writeRaw($galley->getGalleyFileName());
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("cover_image_alt_text");
        $this->xmlWriter->writeRaw($galley->getGalleyFileAltText());
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("embed");
        $this->xmlWriter->writeAttribute("encoding", "base64");
        $this->xmlWriter->writeRaw($galley->getBase64Encoding());
        $this->xmlWriter->endElement();

        $this->xmlWriter->endElement(); // </cover>
        $this->xmlWriter->endElement(); // </covers>
    }


    /**
     * Writes the root {@code <article>} element with submission attributes.
     *
     * Sets PKP namespace declarations, submission status attributes, and the
     * submission date, then delegates to {@see writeSubmissionFiles()} and
     * {@see writePublication()}.
     *
     * The {@code current_publication_id} and the internal ID element are
     * hardcoded to 100.
     *
     * @return void
     */
    private function writeArticle(): void {
        $this->xmlWriter->startElement("article");
        $this->xmlWriter->writeAttribute("xmlns",                "http://pkp.sfu.ca");
        $this->xmlWriter->writeAttribute("xmlns:xsi",            "http://www.w3.org/2001/XMLSchema-instance");
        $this->xmlWriter->writeAttribute("status",               "3");
        $this->xmlWriter->writeAttribute("submission_progress",  "0");
        $this->xmlWriter->writeAttribute("stage",                "production");
        $this->xmlWriter->writeAttribute("current_publication_id", 100);
        $this->xmlWriter->writeAttribute("date_submitted",       $this->article->getDate());

        $this->writeIdElement(100);
        $this->writeSubmissionFiles();
        $this->writePublication();

        $this->xmlWriter->endElement(); // </article>
    }


    /**
     * Writes all {@code <submission_file>} elements in the required OJS import order.
     *
     * OJS Native XML requires submission files to be declared before their galley
     * references in {@code <article_galley>}. Files are written in this fixed order
     * to ensure IDs are assigned predictably:
     *
     * 1. JATS XML file (always first, assigned ID 100)
     * 2. Non-dependent galley files (all types except cover image, JATS XML,
     *    and files whose genre matches {@see GalleyFile::$DEPENDANT_GENRE})
     * 3. Dependent galley files (genre matches {@see GalleyFile::$DEPENDANT_GENRE},
     *    excluding cover image and JATS XML)
     *
     * Cover image files are excluded from all three passes (handled separately
     * by {@see writeCover()}).
     *
     * @return void
     */
    private function writeSubmissionFiles(): void {
        $id = 100; // IDs start at 100 to avoid conflicts with OJS internal IDs

        // Pass 1: JATS XML file first so it receives the anchor ID of 100
        $jatsFile = $this->article->getJATSXMLFile();
        if ($jatsFile) {
            $this->writeSubmissionFile($jatsFile, $id);
        }
        $id++;

        // Pass 2: Non-dependent galley files (proof stage)
        foreach ($this->article->getGalleyFiles() as $galley) {
            if ($galley->getType() !== GalleyFile::$COVER_IMAGE &&
                $galley->getType() !== GalleyFile::$JATSXML &&
                $galley->getGenre() !== GalleyFile::$DEPENDANT_GENRE
            ) {
                $this->writeSubmissionFile($galley, $id);
                $id++;
            }
        }

        // Pass 3: Dependent galley files (dependent stage, linked to a parent file)
        foreach ($this->article->getGalleyFiles() as $galley) {
            if ($galley->getType() !== GalleyFile::$COVER_IMAGE &&
                $galley->getType() !== GalleyFile::$JATSXML &&
                $galley->getGenre() === GalleyFile::$DEPENDANT_GENRE
            ) {
                $this->writeSubmissionFile($galley, $id);
                $id++;
            }
        }
    }


    /**
     * Writes a single {@code <submission_file>} element for the given galley.
     *
     * Sets the submission file's ID on the GalleyFile object (so that a subsequent
     * {@code <article_galley>} can reference it via {@code getID()}), then writes
     * the full {@code <submission_file>} XML block including the base64-encoded
     * file content.
     *
     * Dependent files (genre matches {@see GalleyFile::$DEPENDANT_GENRE}) are
     * written with {@code stage="dependent"} and include a
     * {@code <submission_file_ref>} element pointing to their parent file's ID,
     * resolved via {@code Article::getGalleyFileByJatsID()}.
     *
     * Non-dependent files are written with {@code stage="proof"}.
     *
     * @param GalleyFile $galley The galley file to serialise.
     * @param int        $id     The submission file ID to assign (also used as file_id).
     * @return void
     */
    private function writeSubmissionFile(GalleyFile $galley, int $id): void {
        $dependantFile = false;
        $galley->setID($id); // Store ID on galley so article_galley can reference it later

        $this->xmlWriter->startElement("submission_file");
        $this->xmlWriter->writeAttribute("xmlns:xsi",  "http://www.w3.org/2001/XMLSchema-instance");
        $this->xmlWriter->writeAttribute("id",         $id);
        $this->xmlWriter->writeAttribute("file_id",    $id);

        if ($galley->getGenre() === GalleyFile::$DEPENDANT_GENRE) {
            $this->xmlWriter->writeAttribute("stage", "dependent");
            $dependantFile = true;
        } else {
            $this->xmlWriter->writeAttribute("stage", "proof");
        }

        $this->xmlWriter->writeAttribute("viewable", "false");
        $this->xmlWriter->writeAttribute("genre",    $galley->getGenre());

        if ($this->article->getOJSUserName() !== null) {
            $this->xmlWriter->writeAttribute("uploader", $this->article->getOJSUserName());
        }

        $this->xmlWriter->writeAttribute("xsi:schemaLocation", "http://pkp.sfu.ca native.xsd");

        $this->xmlWriter->startElement("name");
        $this->xmlWriter->writeAttribute("locale", $galley->getLocale());
        $this->xmlWriter->writeRaw($galley->getName());
        $this->xmlWriter->endElement();

        // Dependent files include a reference to their parent submission file
        if ($dependantFile) {
            $parentRef = $galley->getParent();
            $parent    = $this->article->getGalleyFileByJatsID($parentRef);
            $ref       = $parent->getID();
            $this->xmlWriter->startElement("submission_file_ref");
            $this->xmlWriter->writeAttribute("id", $ref);
            $this->xmlWriter->endElement();
        }

        $this->xmlWriter->startElement("file");
        $this->xmlWriter->writeAttribute("id",        $id);
        $this->xmlWriter->writeAttribute("filesize",  $galley->getGalleyFileSize());
        $this->xmlWriter->writeAttribute("extension", $galley->getGalleyFileType());

        $this->xmlWriter->startElement("embed");
        $this->xmlWriter->writeAttribute("encoding", "base64");
        $this->xmlWriter->writeRaw($galley->getBase64Encoding());
        $this->xmlWriter->endElement(); // </embed>

        $this->xmlWriter->endElement(); // </file>
        $this->xmlWriter->endElement(); // </submission_file>
    }


    /**
     * Writes the {@code <publication>} element with all publication-level metadata.
     *
     * Version-specific behaviour:
     * - **OJS 3.3**: adds a locale attribute to the {@code <publication>} element.
     * - **OJS 3.4 / 3.5**: adds {@code access_status}, {@code xsi:schemaLocation},
     *   {@code url_path}, and {@code primary_contact_id} attributes, and appends
     *   an {@code <issue_identification>} element at the end of the block.
     *
     * Always writes: internal ID element, publication metadata, authors, article
     * galleys, page range, and cover image (if present).
     *
     * @return void
     */
    public function writePublication(): void {
        $this->xmlWriter->startElement("publication");
        $this->xmlWriter->writeAttribute("xmlns:xsi", "http://www.w3.org/2001/XMLSchema-instance");

        // Version-specific attributes on <publication>
        if ($this->version === self::$OJS_3_3) {
            $this->addLocaleAttribute();
        }
        if ($this->version === self::$OJS_3_4 || $this->version === self::$OJS_3_5) {
            $this->xmlWriter->writeAttribute("access_status",       "0");
            $this->xmlWriter->writeAttribute("xsi:schemaLocation",  "http://pkp.sfu.ca native.xsd");
            $this->xmlWriter->writeAttribute("url_path",            "");
            $this->xmlWriter->writeAttribute("primary_contact_id",  "100");
        }

        $this->xmlWriter->writeAttribute("version",        "1");
        $this->xmlWriter->writeAttribute("status",         "3");
        $this->xmlWriter->writeAttribute("date_published", $this->article->getDate());
        $this->xmlWriter->writeAttribute("section_ref",    $this->article->getSectionRef());
        $this->xmlWriter->writeAttribute("seq",            0);

        $this->writeIdElement(100);
        $this->writePublicationMetadata();
        $this->writeAuthors();
        $this->writeArticleGalley();

        // OJS 3.4 / 3.5 include issue identification inside the publication element
        if ($this->version === self::$OJS_3_4 || $this->version === self::$OJS_3_5) {
            $this->writeIssueMetadata();
        }

        $this->xmlWriter->startElement("pages");
        $this->xmlWriter->writeRaw($this->article->getStartPage() . "-" . $this->article->getEndPage());
        $this->xmlWriter->endElement();

        $this->writeCover();

        $this->xmlWriter->endElement(); // </publication>
    }


    /**
     * Writes publication-level metadata fields.
     *
     * Outputs the following elements (each conditional unless noted):
     * - {@code <id type="doi">} — only when the Article has a non-empty DOI
     * - {@code <title>} (locale-aware, always)
     * - {@code <subtitle>} (locale-aware, always)
     * - {@code <abstract>} (locale-aware, always) — rendered via
     *   {@code getAbstract()->getAsText()}
     * - {@code <licenseUrl>} — only when set
     * - {@code <copyrightHolder>} (locale-aware) — only when set
     * - {@code <copyrightYear>} — only when set
     * - {@code <keywords>} (locale-aware) — only when the Article has keywords;
     *   each keyword is written as a child {@code <keyword>} element
     *
     * @return void
     */
    public function writePublicationMetadata(): void {
        // DOI (optional)
        if (!empty($this->article->getDOI())) {
            $this->xmlWriter->startElement("id");
            $this->xmlWriter->writeAttribute("type",   "doi");
            $this->xmlWriter->writeAttribute("advice", "update");
            $this->xmlWriter->writeRaw($this->article->getDOI());
            $this->xmlWriter->endElement();
        }

        $this->xmlWriter->startElement("title");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeRaw($this->article->getTitle());
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("subtitle");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeRaw($this->article->getSubTitle());
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("abstract");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeRaw($this->article->getAbstract()->getAsText());
        $this->xmlWriter->endElement();

        // License URL (optional)
        if ($this->article->getLicenseUrl() !== "") {
            $this->xmlWriter->startElement("licenseUrl");
            $this->xmlWriter->writeRaw($this->article->getLicenseUrl());
            $this->xmlWriter->endElement();
        }

        // Copyright holder (optional, locale-aware)
        if ($this->article->getCopyrightHolder() !== "") {
            $this->xmlWriter->startElement("copyrightHolder");
            $this->addLocaleAttribute();
            $this->xmlWriter->writeRaw($this->article->getCopyrightHolder());
            $this->xmlWriter->endElement();
        }

        // Copyright year (optional; typed xs:int in the schema, so an empty
        // string must not be written)
        if ($this->article->getCopyrightYear() !== "") {
            $this->xmlWriter->startElement("copyrightYear");
            $this->xmlWriter->writeRaw($this->article->getCopyrightYear());
            $this->xmlWriter->endElement();
        }

        // Keywords (optional)
        $keywords = $this->article->getKeywords();
        if (count($keywords)) {
            $this->xmlWriter->startElement("keywords");
            $this->addLocaleAttribute();
            foreach ($keywords as $keyword) {
                $this->xmlWriter->startElement("keyword");
                $this->xmlWriter->writeRaw(trim($keyword->getName()));
                $this->xmlWriter->endElement();
            }
            $this->xmlWriter->endElement(); // </keywords>
        }
    }


    /**
     * Writes the {@code <authors>} element containing all article authors.
     *
     * The first author in the collection is treated as the primary contact
     * (index 0 triggers the {@code primary_contact="true"} attribute in
     * {@see writeAuthor()}). PKP namespace attributes are added to the
     * {@code <authors>} element via {@see setXmlnsAttributes()}.
     *
     * @return void
     */
    public function writeAuthors(): void {
        $this->xmlWriter->startElement("authors");
        $this->setXmlnsAttributes();

        $authorIndex = 0;
        foreach ($this->article->getAuthors() as $author) {
            $this->writeAuthor($author, $authorIndex);
            $authorIndex++;
        }

        $this->xmlWriter->endElement(); // </authors>
    }


    /**
     * Writes a single {@code <author>} element.
     *
     * Outputs: given name, family name, affiliation (first only, if set),
     * email (if non-empty), and ORCID (if non-empty). All text elements
     * carry the current locale attribute. In OJS 3.5 output, the affiliation
     * text is nested inside an {@code <affiliation><name>} child element
     * rather than carrying the locale attribute directly on {@code <affiliation>}.
     *
     * The {@code primary_contact="true"} attribute is added only for the
     * first author (index 0). All author elements are assigned a hardcoded
     * internal {@code id} of 100.
     *
     * @param \Biblhertz\Article\om\Author $author The author object to serialise.
     * @param int                          $index  Zero-based position of this author
     *                                             in the author list; 0 marks the
     *                                             primary contact.
     * @return void
     */
    public function writeAuthor(\Biblhertz\Article\om\Author $author, int $index): void {
        $this->xmlWriter->startElement("author");
        $this->xmlWriter->writeAttribute("user_group_ref", "Author");

        // First author in list is the primary contact
        if ($index === 0) {
            $this->xmlWriter->writeAttribute("primary_contact", "true");
        }

        $this->xmlWriter->writeAttribute("seq", $index);
        $this->xmlWriter->writeAttribute("id",  100);

        $this->xmlWriter->startElement("givenname");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeRaw(trim($author->getFirstName()));
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("familyname");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeRaw(trim($author->getLastName()));
        $this->xmlWriter->endElement();

        // First affiliation only (OJS Native XML does not support multiple affiliations per author)
        $affiliation = $author->getFirstAffiliation();
        if ($affiliation) {
            $this->xmlWriter->startElement("affiliation");
            if ($this->version === self::$OJS_3_5) {
                // OJS 3.5 nests the localized text inside a <name> child
                // (to allow a sibling <rorAffiliation> alternative), whereas
                // 3.3/3.4 put the locale attribute directly on <affiliation>.
                $this->xmlWriter->startElement("name");
                $this->addLocaleAttribute();
                $this->xmlWriter->writeRaw($affiliation);
                $this->xmlWriter->endElement(); // </name>
            } else {
                $this->addLocaleAttribute();
                $this->xmlWriter->writeRaw($affiliation);
            }
            $this->xmlWriter->endElement(); // </affiliation>
        }

        if (!empty($author->getEmail())) {
            $this->xmlWriter->startElement("email");
            $this->xmlWriter->writeRaw(trim($author->getEmail()));
            $this->xmlWriter->endElement();
        }

        if (!empty($author->getOrcID())) {
            $this->xmlWriter->startElement("orcid");
            $this->xmlWriter->writeRaw(trim($author->getOrcID()));
            $this->xmlWriter->endElement();
        }

        $this->xmlWriter->endElement(); // </author>
    }


    /**
     * Writes {@code <article_galley>} elements for all non-cover, non-dependent galleys.
     *
     * Cover image files and files with the dependent genre are excluded — cover images
     * are handled by {@see writeCover()} and dependent files are not referenced as
     * standalone galleys. Each galley element includes an internal ID, a locale-aware
     * name (uppercased file extension), a sequence number, and a
     * {@code <submission_file_ref>} linking back to the corresponding
     * {@code <submission_file>} by its assigned ID.
     *
     * @todo The {@code $seq} variable is reset to 0 inside the loop on each iteration
     *       and incremented after the closing brace, meaning it never advances past 1
     *       for successive galleys. Move initialisation outside the loop if sequential
     *       ordering is needed.
     *
     * @return void
     */
    public function writeArticleGalley(): void {
        $seq = 0;
        foreach ($this->article->getGalleyFiles() as $galley) {
            if ($galley->getType() !== GalleyFile::$COVER_IMAGE &&
                $galley->getGenre() !== GalleyFile::$DEPENDANT_GENRE
            ) {
                $this->xmlWriter->startElement("article_galley");
                $this->xmlWriter->writeAttribute("xmlns:xsi",        "http://www.w3.org/2001/XMLSchema-instance");
                $this->xmlWriter->writeAttribute("locale",            $galley->getLocale());
                $this->xmlWriter->writeAttribute("approved",          "false");
                $this->xmlWriter->writeAttribute("xsi:schemaLocation","http://pkp.sfu.ca native.xsd");

                $this->writeIdElement(100);

                $this->xmlWriter->startElement("name");
                $this->xmlWriter->writeAttribute("locale", $galley->getLocale());
                // Use uppercased file extension as the galley display label
                $this->xmlWriter->writeRaw(strtoupper($galley->getGalleyFileType()));
                $this->xmlWriter->endElement();

                $this->xmlWriter->startElement("seq");
                $this->xmlWriter->writeRaw($seq);
                $this->xmlWriter->endElement();

                // Reference back to the submission_file written in writeSubmissionFiles()
                $this->xmlWriter->startElement("submission_file_ref");
                $this->xmlWriter->writeAttribute("id", $galley->getID());
                $this->xmlWriter->endElement();

                $this->xmlWriter->endElement(); // </article_galley>
                $seq++;
            }
        }
    }


    /**
     * Writes PKP namespace attributes onto the current open XML element.
     *
     * Adds {@code xmlns} and {@code xmlns:xsi} attributes, and optionally
     * {@code xsi:schemaLocation} if {@see $includeSchemaLocation} is true.
     *
     * @param bool $includeSchemaLocation Whether to include the schema location
     *                                    attribute (default: false).
     * @return void
     */
    private function setXmlnsAttributes(bool $includeSchemaLocation = false): void {
        $this->xmlWriter->writeAttribute("xmlns",     "http://pkp.sfu.ca");
        $this->xmlWriter->writeAttribute("xmlns:xsi", "http://www.w3.org/2001/XMLSchema-instance");
        if ($includeSchemaLocation) {
            $this->xmlWriter->writeAttribute("xsi:schemaLocation", "http://pkp.sfu.ca native.xsd");
        }
    }


    /**
     * Writes the current locale as an XML attribute on the active element.
     *
     * Helper used by all locale-sensitive elements throughout the adapter.
     *
     * @return void
     */
    protected function addLocaleAttribute(): void {
        $this->xmlWriter->writeAttribute("locale", $this->locale);
    }


    /**
     * Writes an {@code <id type="internal" advice="ignore">} element.
     *
     * Used as the internal linking ID for {@code <article>}, {@code <publication>},
     * and {@code <article_galley>} elements. The value is hardcoded at call sites
     * (currently always 100); OJS ignores this value on import but requires its presence.
     *
     * @param int $currentId The ID value to write as element content.
     * @return void
     */
    private function writeIdElement(int $currentId): void {
        $this->xmlWriter->startElement("id");
        $this->xmlWriter->writeAttribute("type",   "internal");
        $this->xmlWriter->writeAttribute("advice", "ignore");
        $this->xmlWriter->writeRaw($currentId);
        $this->xmlWriter->endElement();
    }


    /**
     * Validates the generated XML file at {@see $uri} against an OJS XSD schema.
     *
     * Loads the file written during {@see generateXML()}, parses it into a
     * DOMDocument, and runs schema validation. Results (pass or fail) are written
     * to the Logger. On failure, libxml errors are printed via
     * {@see Utilities::printXMLErrors()}.
     *
     * Note: this method calls {@code Logger::print()} statically for one log line
     * (the "Trying XML document" entry) while using the instance logger for all
     * others. This is likely unintentional — consider replacing the static call
     * with {@code $this->logger->print()} for consistency.
     *
     * @param string $ojs_xsd_path Absolute path to the OJS XSD schema file.
     * @return bool True if the document is valid; false otherwise.
     *
     * @todo Replace the static {@code Logger::print()} call with
     *       {@code $this->logger->print()} for consistent instance-based logging.
     */
    public function validateXML(string $ojs_xsd_path): bool {
        $fcontent = file_get_contents($this->uri);
        if ($fcontent === false) {
            $this->logger->print("Failed to read generated OJS XML file: " . $this->uri);
            return false;
        }
        $doc = new DOMDocument();
        $doc->loadXML($fcontent);

        $this->logger->print("Loaded generated OJS XML document from " . $this->uri);
        $this->logger->print("Starting OJS schema validation");

        libxml_use_internal_errors(true);

        // Note: static call — see @todo above
        Logger::print("Trying XML document :: " . $this->uri . " against OJS :: " . $ojs_xsd_path);
        $is_valid_xml = $doc->schemaValidate($ojs_xsd_path);

        libxml_use_internal_errors(false);

        if ($is_valid_xml) {
            $this->logger->print("Validation Successful against OJS xsd");
            $this->logger->print("Generated File ready for OJS import in :: " . $this->uri);
            $this->logger->println();
        } else {
            $this->logger->print("Validation Failed against OJS xsd");
            $this->logger->print("Generated File cannot be imported at :: " . $this->uri);
            Utilities::printXMLErrors();
            $this->logger->println();
        }

        return $is_valid_xml;
    }
}

?>