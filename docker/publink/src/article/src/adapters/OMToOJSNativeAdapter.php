<?php

namespace Biblhertz\Article\Adapters;

use XmlWriter;
use Biblhertz\Article\om\GalleyFile;
use Biblhertz\Publink\utilities\Logger;

/********************************************************************/
/*      OMToOJSNativeAdapter                                        */
/*                                                                  */
/*      Author  :   Chris Tomlinson                                 */
/*      Date    :   11th July 2023                                  */
/*                                                                  */
/*      Generates an OJS Native XML issue-level document from an    */
/*      Article object model                                        */
/*                                                                  */
/********************************************************************/

/**
 * Serialises a PubLink Article object model to an OJS Native XML document
 * at the issue level.
 *
 * This adapter is the issue-wrapped counterpart to {@see OMToOJSArticleAdapter}.
 * Where that adapter generates a standalone {@code <article>} root element,
 * this adapter wraps the article inside a full {@code <issues><issue>} structure,
 * making it suitable for OJS import workflows that operate at the issue level.
 *
 * The output is always written to the filesystem path supplied at construction
 * (file-only mode; no in-memory string return).
 *
 * Output structure:
 * - {@code <issues>} (root, with PKP namespace and schema location)
 *   - {@code <issue published="1">}
 *     - {@code <issue_identification>}: volume, number, year, journal title,
 *       and publication date
 *     - {@code <sections>}: hardcoded single "Articles" section
 *     - {@code <articles>}
 *       - {@code <article>}: submission files and publication block
 *         - {@code <submission_file>} entries (all non-cover-image galleys,
 *           in iteration order)
 *         - {@code <publication>}: metadata, authors, galley references,
 *           page range, cover image
 *
 * Key differences from {@see OMToOJSArticleAdapter}:
 * - Wraps output in {@code <issues><issue>} rather than a bare {@code <article>}
 * - No three-pass submission file ordering (JATS-first / non-dependent /
 *   dependent) — all non-cover galleys are written in a single pass
 * - Submission file name is formatted as "{ojsUserName}, {galleyFileName}"
 * - Uses {@code getGalleyFileAsBase64()} rather than {@code getBase64Encoding()}
 * - Abstract is written directly via {@code getAbstract()} (not {@code ->getAsText()})
 * - {@code <date_published>} is written as a sibling of {@code <issue_identification>}
 *   rather than inside it
 *
 * @package  Biblhertz\Article\adapters
 * @author   Chris Tomlinson
 * @since    2023-07-11
 */
class OMToOJSNativeAdapter {

    /****************************************************************/
    /*  STATIC VARIABLES                                            */
    /****************************************************************/

    /**
     * Version constant for OJS 3.3 output mode.
     *
     * @var int
     */
    public static $OJS_3_3 = 1;

    /**
     * Version constant for OJS 3.4 output mode.
     *
     * @var int
     */
    public static $OJS_3_4 = 2;

    /**
     * Version constant for OJS 3.5 output mode (reserved for future use).
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
     * @var \Biblhertz\Article\om\Article
     */
    private \Biblhertz\Article\om\Article $article;

    /**
     * XmlWriter instance used to build the OJS Native XML output.
     *
     * @var XmlWriter
     */
    private XmlWriter $xmlWriter;

    /**
     * Absolute filesystem path for the output XML file.
     * This adapter operates in file-only mode — there is no in-memory option.
     *
     * @var string
     */
    private string $uri;

    /**
     * Locale string used for all locale-sensitive XML attributes.
     * Defaults to {@code "en_US"}.
     *
     * @var string
     */
    private string $locale = "en_US";

    /**
     * Target OJS version for the output XML.
     * Should be one of {@see $OJS_3_3}, {@see $OJS_3_4}, or {@see $OJS_3_5}.
     * Currently stored but not yet used to branch output behaviour in this adapter.
     *
     * @var int
     */
    private int $version;


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Constructs the adapter for the given Article and output file path.
     *
     * @param \Biblhertz\Article\om\Article $article The Article to serialise.
     * @param string                        $uri     Absolute filesystem path for the
     *                                               output OJS Native XML file.
     */
    public function __construct(\Biblhertz\Article\om\Article $article, string $uri) {
        $this->article   = $article;
        $this->uri       = $uri;
        $this->xmlWriter = new XmlWriter();
    }


    /****************************************************************/
    /*  INTERFACE METHODS                                           */
    /****************************************************************/

    /**
     * Sets the target OJS version for XML output.
     *
     * Use the class constants {@see $OJS_3_3}, {@see $OJS_3_4}, or {@see $OJS_3_5}.
     * Note: version branching is not yet implemented in this adapter; the value
     * is stored for future use.
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
     * Generates the OJS Native XML document and writes it to {@see $uri}.
     *
     * Produces an {@code <issues><issue>} wrapper document containing issue
     * metadata, section definition, and the full article submission block.
     * Output is always written to the filesystem; this adapter has no
     * in-memory mode.
     *
     * @return void
     */
    public function generateXML(): void {
        $this->xmlWriter->openUri($this->uri);
        $this->xmlWriter->startDocument();
        $this->xmlWriter->setIndent(true);

        $this->xmlWriter->startElement("issues");
        $this->setXmlnsAttributes(true); // Root element carries the schema location

        $this->xmlWriter->startElement("issue");
        $this->setXmlnsAttributes(false);
        $this->xmlWriter->writeAttribute("published", "1");

        $this->writeIssueMetadata();
        $this->writeSection();
        $this->writeArticle();

        $this->xmlWriter->endElement(); // </issue>
        $this->xmlWriter->endElement(); // </issues>
        $this->xmlWriter->endDocument();
        $this->xmlWriter->flush();
    }


    /**
     * Writes the {@code <issue_identification>} element followed by
     * {@code <date_published>} as a sibling element.
     *
     * Contains: volume, issue number, year, and journal title (locale-aware)
     * inside {@code <issue_identification>}. The publication date is written
     * immediately after as a separate sibling element.
     *
     * Note: in {@see OMToOJSArticleAdapter} the publication date is omitted
     * from this block. Here it is always written.
     *
     * @return void
     */
    private function writeIssueMetadata(): void {
        $this->xmlWriter->startElement("issue_identification");

        $this->xmlWriter->startElement("volume");
        $this->xmlWriter->writeRaw($this->article->getVolume());
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("number");
        $this->xmlWriter->writeRaw($this->article->getIssue());
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("year");
        $this->xmlWriter->writeRaw($this->article->getYear());
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("title");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeRaw($this->article->getJournalName());
        $this->xmlWriter->endElement();

        $this->xmlWriter->endElement(); // </issue_identification>

        // date_published is a sibling of issue_identification, not a child
        $this->xmlWriter->startElement("date_published");
        $this->xmlWriter->writeRaw($this->article->getDate());
        $this->xmlWriter->endElement();
    }


    /**
     * Writes the {@code <sections>} element with a single hardcoded "Articles" section.
     *
     * The section is written with {@code ref="ART"}, abbreviated title "ART",
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
     * returns immediately without writing anything. The image is embedded as a
     * base64-encoded {@code <embed>} element via {@code getGalleyFileAsBase64()}.
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
        $this->xmlWriter->writeRaw($galley->getGalleyFileAsBase64());
        $this->xmlWriter->endElement();

        $this->xmlWriter->endElement(); // </cover>
        $this->xmlWriter->endElement(); // </covers>
    }


    /**
     * Writes the {@code <articles><article>} wrapper and its child elements.
     *
     * The {@code <articles>} container receives PKP namespace attributes.
     * The inner {@code <article>} element is written with submission status
     * attributes and a hardcoded {@code current_publication_id} of 1.
     * Delegates to {@see writeSubmissionFiles()} and {@see writePublication()}.
     *
     * @return void
     */
    private function writeArticle(): void {
        $this->xmlWriter->startElement("articles");
        $this->setXmlnsAttributes();

        $this->xmlWriter->startElement("article");
        $this->xmlWriter->writeAttribute("xmlns:xsi",               "http://www.w3.org/2001/XMLSchema-instance");
        $this->xmlWriter->writeAttribute("status",                  "3");
        $this->xmlWriter->writeAttribute("stage",                   "production");
        $this->xmlWriter->writeAttribute("current_publication_id",  1);

        $this->writeIdElement(100);
        $this->writeSubmissionFiles();
        $this->writePublication();

        $this->xmlWriter->endElement(); // </article>
        $this->xmlWriter->endElement(); // </articles>
    }


    /**
     * Writes all {@code <submission_file>} elements for the article's galleys.
     *
     * All galley files except cover images are written in a single iteration
     * pass (in the order returned by {@code getGalleyFiles()}), starting at
     * ID 100. The ID is stored on each GalleyFile via {@code setID()} so that
     * {@see writeArticleGalley()} can later reference it.
     *
     * Dependent files (genre {@code "Dependant File"}) receive
     * {@code stage="dependent"}; all others receive {@code stage="proof"}.
     *
     * The submission file name is formatted as "{ojsUserName}, {galleyFileName}",
     * which differs from {@see OMToOJSArticleAdapter} where the galley's own
     * {@code getName()} is used.
     *
     * Note: unlike {@see OMToOJSArticleAdapter}, there is no three-pass ordering
     * here (JATS-first / non-dependent / dependent) — all files are processed
     * in a single loop.
     *
     * @return void
     */
    private function writeSubmissionFiles(): void {
        $id = 100; // Arbitrary start; OJS uses these only for cross-referencing within the import file

        foreach ($this->article->getGalleyFiles() as $galley) {
            if ($galley->getType() !== GalleyFile::$COVER_IMAGE) {
                $galley->setID($id); // Store so writeArticleGalley() can reference it

                $this->xmlWriter->startElement("submission_file");
                $this->xmlWriter->writeAttribute("xmlns:xsi",       "http://www.w3.org/2001/XMLSchema-instance");
                $this->xmlWriter->writeAttribute("id",              $id);
                $this->xmlWriter->writeAttribute("file_id",         $id);

                if ($galley->getGenre() === GalleyFile::$DEPENDANT_GENRE) {
                    $this->xmlWriter->writeAttribute("stage", "dependent");
                } else {
                    $this->xmlWriter->writeAttribute("stage", "proof");
                }

                $this->xmlWriter->writeAttribute("viewable",         "false");
                $this->xmlWriter->writeAttribute("genre",            $galley->getGenre());
                $this->xmlWriter->writeAttribute("uploader",         $this->article->getOJSUserName());
                $this->xmlWriter->writeAttribute("xsi:schemaLocation","http://pkp.sfu.ca native.xsd");

                // Name format: "OJSUser, filename" (differs from OMToOJSArticleAdapter)
                $this->xmlWriter->startElement("name");
                $this->addLocaleAttribute();
                $this->xmlWriter->writeRaw($this->article->getOJSUserName() . ", " . $galley->getGalleyFileName());
                $this->xmlWriter->endElement();

                $this->xmlWriter->startElement("file");
                $this->xmlWriter->writeAttribute("id",        $id);
                $this->xmlWriter->writeAttribute("filesize",  $galley->getGalleyFileSize());
                $this->xmlWriter->writeAttribute("extension", $galley->getGalleyFileType());

                $this->xmlWriter->startElement("embed");
                $this->xmlWriter->writeAttribute("encoding", "base64");
                $this->xmlWriter->writeRaw($galley->getGalleyFileAsBase64());
                $this->xmlWriter->endElement(); // </embed>

                $this->xmlWriter->endElement(); // </file>
                $this->xmlWriter->endElement(); // </submission_file>

                $id++;
            }
        }
    }


    /**
     * Writes the {@code <publication>} element with all publication-level metadata.
     *
     * Always writes a locale attribute on the {@code <publication>} element
     * (unlike {@see OMToOJSArticleAdapter} which conditionally adds it based
     * on version). Contains: internal ID, publication metadata, authors, galley
     * references, page range, and cover image.
     *
     * @return void
     */
    public function writePublication(): void {
        $this->xmlWriter->startElement("publication");
        $this->xmlWriter->writeAttribute("xmlns:xsi",      "http://www.w3.org/2001/XMLSchema-instance");
        $this->addLocaleAttribute();
        $this->xmlWriter->writeAttribute("version",        "1");
        $this->xmlWriter->writeAttribute("status",         "3");
        $this->xmlWriter->writeAttribute("date_published", $this->article->getDate());
        $this->xmlWriter->writeAttribute("section_ref",    $this->article->getSectionRef());
        $this->xmlWriter->writeAttribute("seq",            0);

        $this->writeIdElement(100);
        $this->writePublicationMetadata();
        $this->writeAuthors();
        $this->writeArticleGalley();

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
     * - {@code <id type="doi">} — only when DOI is non-empty
     * - {@code <title>} (locale-aware, always)
     * - {@code <subtitle>} (locale-aware, always)
     * - {@code <abstract>} (locale-aware, always) — written directly from
     *   {@code getAbstract()}; unlike {@see OMToOJSArticleAdapter} this does
     *   not call {@code ->getAsText()} on the result
     * - {@code <keywords>} (locale-aware) — only when keywords are present;
     *   each keyword is written via {@code trim($keyword)} (raw scalar,
     *   not an object)
     *
     * @return void
     */
    public function writePublicationMetadata(): void {
        // DOI (optional)
        if ($this->article->getDOI() !== "") {
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
     * PKP namespace attributes are added to the {@code <authors>} element.
     * The first author (index 0) is marked as the primary contact by
     * {@see writeAuthor()}.
     *
     * Note: the {@code $authorData} array built inside the loop is unused —
     * it is a remnant that can be safely removed.
     *
     *
     * @return void
     */
    public function writeAuthors(): void {
        $this->xmlWriter->startElement("authors");
        $this->setXmlnsAttributes();

        $authorIndex = 0;
        foreach ($this->article->getAuthors() as $author) {
            // $authorData assignments below are unused 
            //$authorData["seq"]       = $authorIndex;
            //$authorData["currentId"] = 100;
            $this->writeAuthor($author, $authorIndex);
            $authorIndex++;
        }

        $this->xmlWriter->endElement(); // </authors>
    }


    /**
     * Writes a single {@code <author>} element.
     *
     * Outputs: given name, family name (locale-aware), first affiliation
     * (if set, locale-aware), and email (if non-empty). ORCID is not written
     * by this adapter (unlike {@see OMToOJSArticleAdapter::writeAuthor()}).
     *
     * The {@code primary_contact="true"} attribute is added only for the
     * first author (index 0). All authors receive a hardcoded {@code id} of 100.
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

        // First affiliation only (OJS Native XML does not support multiple affiliations)
        $affiliation = $author->getFirstAffiliation();
        if ($affiliation) {
            $this->xmlWriter->startElement("affiliation");
            $this->addLocaleAttribute();
            $this->xmlWriter->writeRaw($affiliation);
            $this->xmlWriter->endElement();
        }

        // <country> is intentionally omitted in the current implementation
        // $this->xmlWriter->startElement("country");
        // $this->xmlWriter->writeRaw(trim($autorData["country"]));
        // $this->xmlWriter->endElement();

        if (trim($author->getEmail()) !== "") {
            $this->xmlWriter->startElement("email");
            $this->xmlWriter->writeRaw(trim($author->getEmail()));
            $this->xmlWriter->endElement();
        }

        $this->xmlWriter->endElement(); // </author>
    }


    /**
     * Writes {@code <article_galley>} elements for all non-cover-image galleys.
     *
     * Each galley element includes a locale attribute on the {@code <article_galley>}
     * element itself (unlike {@see OMToOJSArticleAdapter} which sets locale only on
     * the {@code <name>} child). The galley display name is the uppercased file
     * extension. The sequence number is hardcoded to {@code "0"} for all galleys.
     *
     * Each galley links back to its corresponding {@code <submission_file>} via the
     * ID stored by {@see writeSubmissionFiles()}.
     *
     * @return void
     */
    public function writeArticleGalley(): void {
        foreach ($this->article->getGalleyFiles() as $galley) {
            if ($galley->getType() !== GalleyFile::$COVER_IMAGE) {
                $this->xmlWriter->startElement("article_galley");
                $this->xmlWriter->writeAttribute("xmlns:xsi",        "http://www.w3.org/2001/XMLSchema-instance");
                $this->addLocaleAttribute(); // Locale on galley element (vs. on <name> in OMToOJSArticleAdapter)
                $this->xmlWriter->writeAttribute("approved",          "false");
                $this->xmlWriter->writeAttribute("xsi:schemaLocation","http://pkp.sfu.ca native.xsd");

                $this->writeIdElement(100);

                $this->xmlWriter->startElement("name");
                $this->addLocaleAttribute();
                $this->xmlWriter->writeRaw(strtoupper($galley->getGalleyFileType()));
                $this->xmlWriter->endElement();

                $this->xmlWriter->startElement("seq");
                $this->xmlWriter->writeRaw("0");
                $this->xmlWriter->endElement();

                // Links to the submission_file written in writeSubmissionFiles()
                $this->xmlWriter->startElement("submission_file_ref");
                $this->xmlWriter->writeAttribute("id", $galley->getID());
                $this->xmlWriter->endElement();

                $this->xmlWriter->endElement(); // </article_galley>
            }
        }
    }


    /**
     * Writes PKP namespace attributes onto the current open XML element.
     *
     * Adds {@code xmlns} and {@code xmlns:xsi} attributes, and optionally
     * {@code xsi:schemaLocation} when {@see $includeSchemaLocation} is true.
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
     * and {@code <article_galley>} elements. OJS ignores this value on import
     * but requires its presence in the XML.
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
}

?>