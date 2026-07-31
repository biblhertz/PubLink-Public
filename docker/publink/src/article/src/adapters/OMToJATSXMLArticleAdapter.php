<?php

namespace Biblhertz\Article\Adapters;

use XmlWriter;
use DomDocument;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Article\utilities\Utilities;
use Biblhertz\Article\Config;

/********************************************************************/
/*      OMToJATSXMLArticleAdapter                                   */
/*                                                                  */
/*      Author  :   Chris Tomlinson                                 */
/*      Date    :   11th July 2023                                  */
/*                                                                  */
/*      Generates a JATS XML document from an Article object model  */
/*                                                                  */
/********************************************************************/

/**
 * Serialises a PubLink Article object model to a JATS XML document.
 *
 * Generates a complete, standalone JATS XML file (or in-memory string) from
 * the data held in an {@see Article} object. The output document structure
 * follows the JATS Journal Publishing Tag Set and contains:
 *
 * - {@code <front>}: journal metadata and article metadata (title, authors,
 *   affiliations, pub-date, volume/issue/pages, history, permissions,
 *   abstract, keywords)
 * - {@code <body>}: raw body content retrieved directly from the Article OM
 * - {@code <back>}: optional "cite-as" section, reference list, and footnotes
 *
 * Output mode is determined at construction:
 * - **File mode** (URI provided): XML is written directly to the given path.
 * - **Memory mode** (no URI): XML is returned as a string from {@see generateXML()}.
 *
 * @package  Biblhertz\Article\adapters
 * @author   Chris Tomlinson
 * @since    2023-07-11
 */
class OMToJATSXMLArticleAdapter {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /**
     * The Article object model to serialise into JATS XML.
     *
     * @var \Biblhertz\Article\om\Article
     */
    private \Biblhertz\Article\om\Article $article;

    /**
     * XmlWriter instance used to build the JATS XML output.
     *
     * @var XmlWriter
     */
    private XmlWriter $xmlWriter;

    /**
     * Filesystem URI for the output XML file.
     * Null signals in-memory (string return) mode.
     *
     * @var string|null
     */
    private ?string $uri;

    /**
     * Locale string written to locale-sensitive XML attributes.
     * Defaults to {@code "en_US"}.
     *
     * @var string
     */
    private string $locale = "en_US";

    /**
     * Logger instance for recording adapter activity.
     *
     * @var \Biblhertz\Publink\utilities\Logger
     */
    private Logger $logger;


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Constructs the adapter for the given Article and optional output URI.
     *
     * @param \Biblhertz\Article\om\Article $article The Article to serialise.
     * @param string|null                   $uri     Filesystem path for the output
     *                                               XML file, or null for in-memory mode.
     */
    public function __construct(\Biblhertz\Article\om\Article $article, ?string $uri = null) {
        $this->article   = $article;
        $this->uri       = $uri;
        $this->xmlWriter = new XmlWriter();
    }


    /****************************************************************/
    /*  INTERFACE METHODS                                           */
    /****************************************************************/

    /**
     * Sets the Logger instance for recording adapter output.
     *
     * @param \Biblhertz\Publink\utilities\Logger $l Logger to use.
     * @return void
     */
    public function setLogger(Logger $l): void {
        $this->logger = $l;
    }


    /****************************************************************/
    /*  OTHER METHODS                                               */
    /****************************************************************/

    /**
     * Generates the JATS XML document.
     *
     * Behaviour depends on whether a URI was supplied at construction:
     * - **File mode**: writes XML to the file and returns nothing.
     * - **Memory mode**: builds XML in memory and returns it as a string.
     *
     * @return string|null JATS XML string in memory mode; null in file mode.
     */
    public function generateXML(): string|null {
        if ($this->uri !== null) $this->xmlWriter->openUri($this->uri);
        else $this->xmlWriter->openMemory();

        $this->xmlWriter->startDocument();
        $this->xmlWriter->setIndent(true);
        $this->writeArticle();
        $this->xmlWriter->endDocument();

        if ($this->uri !== null) {
            $this->xmlWriter->flush();
            return null;
        }
        return $this->xmlWriter->flush();
    }


    /**
     * Writes the root {@code <article>} element and delegates to section writers.
     *
     * Outputs the top-level {@code <article>} element with the JATS xlink
     * namespace declaration and {@code article-type="research-article"}, then
     * calls {@see writeFront()}, {@see writeBody()}, and {@see writeBack()}
     * in sequence.
     *
     * Note: {@code writeBody()} and {@code writeBack()} are called outside
     * the {@code <front>} close tag but are siblings of {@code <front>} inside
     * {@code <article>} — this is intentional and matches the JATS structure.
     *
     * @return void
     */
    private function writeArticle(): void {
        $this->xmlWriter->startElement("article");
        $this->xmlWriter->writeAttribute("xmlns:xlink", "http://www.w3.org/1999/xlink");
        $this->xmlWriter->writeAttribute("article-type", "research-article");

        $this->xmlWriter->startElement("front");
        $this->writeFront();
        $this->xmlWriter->endElement(); // </front>

        $this->writeBody();
        $this->writeBack();

        $this->xmlWriter->endElement(); // </article>
    }


    /**
     * Writes the {@code <back>} element containing the cite-as section,
     * reference list, and footnotes.
     *
     * The "cite-as" {@code <sec>} element is only written when the Article
     * has a non-empty CiteAs value. References are delegated to each
     * {@see Reference} object via {@code getJATSReference()}, which writes
     * directly to the shared XmlWriter. Footnotes are written as raw XML
     * using the Article's {@code getFootNotesTag()} output.
     *
     * @return void
     */
    private function writeBack(): void {
        $this->xmlWriter->startElement("back");

        // Optional "cite-as" section — stored value is already a complete <sec> element
        $cite = $this->article->getCiteAs();
        if (!empty($cite)) {
            $this->xmlWriter->writeRaw($cite);
        }

        // Reference list: each reference writes its own <ref> element
        $this->xmlWriter->startElement("ref-list");
        foreach ($this->article->getReferences() as $ref) {
            $ref->setXMLWriter($this->xmlWriter);
            $ref->getJATSReference();
        }
        $this->xmlWriter->endElement(); // </ref-list>

        // Footnotes as raw XML
        $this->xmlWriter->writeRaw($this->article->getFootNotesTag());

        $this->xmlWriter->endElement(); // </back>
    }


    /**
     * Writes the {@code <body>} element using raw XML from the Article OM.
     *
     * The body content is retrieved via {@code Article::getBodyTag()}, which
     * is expected to return a pre-formed XML string (or null/empty if absent).
     * Nothing is written if the body is empty.
     *
     * @return void
     */
    private function writeBody(): void {
        $body = $this->article->getBodyTag();
        if (!empty($body)) {
            $this->xmlWriter->writeRaw($body);
        }
    }


    /**
     * Writes the {@code <front>} section by delegating to journal and article
     * metadata writers.
     *
     * @return void
     */
    private function writeFront(): void {
        $this->writeJournalMeta();
        $this->writeArticleMeta();
    }


    /**
     * Writes the {@code <journal-meta>} element.
     *
     * Contains: journal ID (publisher-id type), journal title group (full title
     * and abbreviated title), ISSN, and publisher name and location.
     *
     * @return void
     */
    private function writeJournalMeta(): void {
        $this->xmlWriter->startElement("journal-meta");

        $this->xmlWriter->startElement("journal-id");
        $this->xmlWriter->writeAttribute("journal-id-type", "publisher-id");
        $this->xmlWriter->writeRaw($this->article->getJournalID());
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("journal-title-group");
        $this->xmlWriter->startElement("journal-title");
        $this->xmlWriter->writeRaw($this->article->getTitle());
        $this->xmlWriter->endElement();
        $this->xmlWriter->startElement("abbrev-journal-title");
        $this->xmlWriter->writeRaw($this->article->getJournalAbbreviation());
        $this->xmlWriter->endElement();
        $this->xmlWriter->endElement(); // </journal-title-group>

        $this->xmlWriter->startElement("issn");
        $this->xmlWriter->writeRaw($this->article->getJournalISSN());
        $this->xmlWriter->endElement();

        $this->xmlWriter->startElement("publisher");
        $this->xmlWriter->startElement("publisher-name");
        $this->xmlWriter->writeRaw($this->article->getJournalPublisher());
        $this->xmlWriter->endElement();
        $this->xmlWriter->startElement("publisher-loc");
        $this->xmlWriter->writeRaw($this->article->getJournalLocation());
        $this->xmlWriter->endElement();
        $this->xmlWriter->endElement(); // </publisher>

        $this->xmlWriter->endElement(); // </journal-meta>
    }


    /**
     * Writes the {@code <article-meta>} element.
     *
     * Contains: DOI (article-id), article category (hardcoded "Original Article"),
     * title group (title, subtitle, alt-title), contrib-group, affiliations,
     * pub-date, volume, issue, fpage, lpage (each conditional on non-empty value),
     * history, permissions, abstract, and keywords.
     *
     * @return void
     */
    private function writeArticleMeta(): void {
        $this->xmlWriter->startElement("article-meta");

        // DOI
        $this->xmlWriter->startElement("article-id");
        $this->xmlWriter->writeAttribute("pub-id-type", "doi");
        $this->xmlWriter->writeRaw($this->article->getDOI());
        $this->xmlWriter->endElement();

        // Article category (hardcoded display channel)
        $this->xmlWriter->startElement("article-categories");
        $this->xmlWriter->startElement("subj-group");
        $this->xmlWriter->writeAttribute("subj-group-type", "display-channel");
        $this->xmlWriter->startElement("subject");
        $this->xmlWriter->writeRaw("Original Article");
        $this->xmlWriter->endElement();
        $this->xmlWriter->endElement();
        $this->xmlWriter->endElement(); // </article-categories>

        // Title group
        $this->xmlWriter->startElement("title-group");
        $this->xmlWriter->startElement("article-title");
        $this->xmlWriter->writeRaw($this->article->getTitle());
        $this->xmlWriter->endElement();
        $subtitle = $this->article->getSubTitle();
        if ($subtitle !== '') {
            $this->xmlWriter->startElement("subtitle");
            $this->xmlWriter->writeRaw($subtitle);
            $this->xmlWriter->endElement();
        }
        if ($this->article->getTransTitle() !== '') {
            $this->xmlWriter->startElement("trans-title-group");
            $this->xmlWriter->writeAttribute("xml:lang", "en");
            $this->xmlWriter->startElement("trans-title");
            $this->xmlWriter->writeRaw($this->article->getTransTitle());
            $this->xmlWriter->endElement();
            $this->xmlWriter->endElement(); // </trans-title-group>
        }
        $altTitle = $this->article->getAltTitle();
        if ($altTitle !== '') {
            $this->xmlWriter->startElement("alt-title");
            $this->xmlWriter->writeRaw($altTitle);
            $this->xmlWriter->endElement();
        }
        $this->xmlWriter->endElement(); // </title-group>

        $this->writeContribGroup();
        $this->writeAffiliations();
        $this->writePubDate();

        // Volume, issue, and page range are conditional on non-empty values
        $vol = $this->article->getVolume();
        if ($vol !== "") {
            $this->xmlWriter->startElement("volume");
            $this->xmlWriter->writeRaw($vol);
            $this->xmlWriter->endElement();
        }

        $issue = $this->article->getIssue();
        if ($issue !== "") {
            $this->xmlWriter->startElement("issue");
            $this->xmlWriter->writeRaw($issue);
            $this->xmlWriter->endElement();
        }

        $fp = $this->article->getStartPage();
        if ($fp !== "") {
            $this->xmlWriter->startElement("fpage");
            $this->xmlWriter->writeRaw($fp);
            $this->xmlWriter->endElement();
        }

        $lp = $this->article->getEndPage();
        if ($lp !== "") {
            $this->xmlWriter->startElement("lpage");
            $this->xmlWriter->writeRaw($lp);
            $this->xmlWriter->endElement();
        }

        $this->writeHistory();
        $this->writePermissions();
        $this->writeAbstract();
        $this->writeKeywords();

        $this->xmlWriter->endElement(); // </article-meta>
    }


    /**
     * Writes the {@code <contrib-group>} element containing all authors.
     *
     * Iterates over the Article's author list and delegates each author
     * to {@see writeAuthor()}.
     *
     * @return void
     */
    private function writeContribGroup(): void {
        $this->xmlWriter->startElement("contrib-group");
        foreach ($this->article->getAuthors() as $author) {
            $this->writeAuthor($author);
        }
        $this->xmlWriter->endElement(); // </contrib-group>
    }


    /**
     * Writes a single {@code <contrib contrib-type="author">} element.
     *
     * Outputs the following child elements (each conditional unless noted):
     * - {@code <contrib-id contrib-id-type="orcid">} — only when ORCID is set
     * - {@code <name>} with {@code <surname>} and {@code <given-names>} (always)
     * - {@code <email>} — only when non-empty
     * - {@code <bio><p>} — only when biography is non-empty
     * - {@code <xref ref-type="aff">} — one per affiliation (always if present)
     * - {@code <xref ref-type="corresp">} — only for corresponding authors
     *
     * Attributes on {@code <contrib>}: {@code contrib-type}, {@code corresp},
     * {@code equal-contrib}, {@code deceased}, and {@code id} (when JATS ID is set).
     *
     * @param \Biblhertz\Article\om\Author $author The author object to serialise.
     * @return void
     */
    private function writeAuthor(\Biblhertz\Article\om\Author $author): void {
        $this->xmlWriter->startElement("contrib");
        $this->xmlWriter->writeAttribute("contrib-type",  "author");
        $this->xmlWriter->writeAttribute("corresp",       $author->getCorrespondingAuthor() ? "yes" : "no");
        $this->xmlWriter->writeAttribute("equal-contrib", $author->getEqualContrib()        ? "yes" : "no");
        $this->xmlWriter->writeAttribute("deceased",      $author->getDeceased()            ? "yes" : "no");

        if ($author->getJatsID() !== "") {
            $this->xmlWriter->writeAttribute("id", $author->getJatsID());
        }

        // ORCID identifier (optional)
        $orc = $author->getOrcID();
        if (!empty($orc)) {
            $this->xmlWriter->startElement("contrib-id");
            $this->xmlWriter->writeAttribute("contrib-id-type", "orcid");
            $this->xmlWriter->writeRaw($orc);
            $this->xmlWriter->endElement();
        }

        // Name
        $this->xmlWriter->startElement("name");
        $this->xmlWriter->startElement("surname");
        $this->xmlWriter->writeRaw(trim($author->getLastName()));
        $this->xmlWriter->endElement();
        $this->xmlWriter->startElement("given-names");
        $this->xmlWriter->writeRaw(trim($author->getFirstName()));
        $this->xmlWriter->endElement();
        $this->xmlWriter->endElement(); // </name>

        // Email (optional)
        if (trim($author->getEmail()) !== "") {
            $this->xmlWriter->startElement("email");
            $this->xmlWriter->writeRaw(trim($author->getEmail()));
            $this->xmlWriter->endElement();
        }

        // Biography (optional)
        if (trim($author->getBiography()) !== "") {
            $this->xmlWriter->startElement("bio");
            $this->xmlWriter->startElement("p");
            $this->xmlWriter->writeRaw(trim($author->getBiography()));
            $this->xmlWriter->endElement();
            $this->xmlWriter->endElement(); // </bio>
        }

        // Affiliation cross-references
        foreach ($author->getAffiliations() as $affiliation) {
            $this->xmlWriter->startElement("xref");
            $this->xmlWriter->writeAttribute("ref-type", "aff");
            $this->xmlWriter->writeAttribute("rid", $affiliation->getJatsID());
            $this->xmlWriter->endElement();
        }

        // Corresponding author cross-reference (optional)
        if ($author->getCorrespondingAuthor()) {
            $this->xmlWriter->startElement("xref");
            $this->xmlWriter->writeAttribute("ref-type", "corresp");
            $this->xmlWriter->writeAttribute("rid", "corr-" . $author->getJatsID());
            $this->xmlWriter->writeRaw("‐");
            $this->xmlWriter->endElement();
        }

        $this->xmlWriter->endElement(); // </contrib>
    }


    /**
     * Writes all {@code <aff>} elements and the optional {@code <author-notes>}
     * block for the corresponding author.
     *
     * Each affiliation element may contain: organisation name, division
     * ({@code orgdiv1}), street address, city, and country — each written only
     * when the value is non-empty.
     *
     * If the Article has a designated corresponding author, an
     * {@code <author-notes><corresp>} block is appended after the affiliations,
     * linking to the author via {@code "corr-{jatsID}"}.
     *
     * @return void
     */
    private function writeAffiliations(): void {
        foreach ($this->article->getAffiliations() as $affiliation) {
            $this->xmlWriter->startElement("aff");
            $this->xmlWriter->writeAttribute("id", $affiliation->getJatsID());

            $org = $affiliation->getName();
            if ($org !== "") {
                $this->xmlWriter->startElement("institution");
                $this->xmlWriter->writeAttribute("content-type", "org");
                $this->xmlWriter->writeRaw($org);
                $this->xmlWriter->endElement();
            }

            $div = $affiliation->getDivision();
            if ($div !== "") {
                $this->xmlWriter->startElement("institution");
                $this->xmlWriter->writeAttribute("content-type", "orgdiv1");
                $this->xmlWriter->writeRaw($div);
                $this->xmlWriter->endElement();
            }

            $addr = $affiliation->getAddress();
            if ($addr !== "") {
                $this->xmlWriter->startElement("addr-line");
                $this->xmlWriter->writeRaw($addr);
                $this->xmlWriter->endElement();
            }

            $city = $affiliation->getCity();
            if ($city !== "") {
                $this->xmlWriter->startElement("city");
                $this->xmlWriter->writeRaw($city);
                $this->xmlWriter->endElement();
            }

            $country = $affiliation->getCountry();
            if ($country !== "") {
                $this->xmlWriter->startElement("country");
                $this->xmlWriter->writeRaw($country);
                $this->xmlWriter->endElement();
            }

            $this->xmlWriter->endElement(); // </aff>
        }

        // Corresponding author notes block (optional)
        $author = $this->article->getCorrespondingAuthor();
        if ($author) {
            $this->xmlWriter->startElement("author-notes");
            $this->xmlWriter->startElement("corresp");
            $this->xmlWriter->writeAttribute("id", "corr-" . $author->getJatsID());
            $this->xmlWriter->writeRaw("Corresponding author:");
            $this->xmlWriter->startElement("email");
            $this->xmlWriter->writeRaw($author->getEmail());
            $this->xmlWriter->endElement();
            $this->xmlWriter->endElement(); // </corresp>
            $this->xmlWriter->endElement(); // </author-notes>
        }
    }


    /**
     * Writes the {@code <pub-date pub-type="epub">} element.
     *
     * Writes day, month, and year as separate child elements. Nothing is
     * written if any of the three date components is null.
     *
     * @return void
     */
    private function writePubDate(): void {
        $day   = $this->article->getDay();
        $month = $this->article->getMonth();
        $year  = $this->article->getYear();

        if (!empty($day) && !empty($month) && !empty($year)) {
            $this->xmlWriter->startElement("pub-date");
            $this->xmlWriter->writeAttribute("pub-type", "epub");

            $this->xmlWriter->startElement("day");
            $this->xmlWriter->writeRaw($day);
            $this->xmlWriter->endElement();

            $this->xmlWriter->startElement("month");
            $this->xmlWriter->writeRaw($month);
            $this->xmlWriter->endElement();

            $this->xmlWriter->startElement("year");
            $this->xmlWriter->writeRaw($year);
            $this->xmlWriter->endElement();

            $this->xmlWriter->endElement(); // </pub-date>
        }
    }


    /**
     * Writes the publication history block as raw XML.
     *
     * Content is retrieved via {@code Article::getHistoryTag()}, which is
     * expected to return a pre-formed XML string. Nothing is written if
     * the value is empty or null.
     *
     * @return void
     */
    private function writeHistory(): void {
        $hist = $this->article->getHistoryTag();
        if (!empty($hist)) {
            $this->xmlWriter->writeRaw($hist);
        }
    }


    /**
     * Writes the {@code <permissions>} element.
     *
     * Contains: copyright statement, copyright year, copyright holder (each
     * conditional on non-empty value), and a {@code <license>} element with
     * {@code license-type} and {@code xlink:href} attributes plus a
     * {@code <license-p>} paragraph. The {@code <license>} element is always
     * written; its attributes and paragraph content are conditional.
     *
     * @return void
     */
    private function writePermissions(): void {
        $this->xmlWriter->startElement("permissions");

        $statement = $this->article->getCopyStatement();
        if ($statement !== "") {
            $this->xmlWriter->startElement("copyright-statement");
            $this->xmlWriter->writeRaw($this->article->getCopyStatement());
            $this->xmlWriter->endElement();
        }

        $cyear = $this->article->getCopyRightYear();
        if ($cyear !== "") {
            $this->xmlWriter->startElement("copyright-year");
            $this->xmlWriter->writeRaw($this->article->getCopyRightYear());
            $this->xmlWriter->endElement();
        }

        $cholder = $this->article->getCopyRightHolder();
        if ($cholder !== "") {
            $this->xmlWriter->startElement("copyright-holder");
            $this->xmlWriter->writeRaw($this->article->getCopyRightHolder());
            $this->xmlWriter->endElement();
        }

        $this->xmlWriter->startElement("license");
        $uri  = $this->article->getLicenseUrl();
        $type = $this->article->getLicenseType();
        if (isset($type)) $this->xmlWriter->writeAttribute("license-type", $type);
        if (isset($uri))  $this->xmlWriter->writeAttribute("xlink:href", $uri);
        $this->xmlWriter->startElement("license-p");
        $para = $this->article->getLicenseParagraph();
        if (isset($para)) $this->xmlWriter->writeRaw($para);
        $this->xmlWriter->endElement(); // </license-p>
        $this->xmlWriter->endElement(); // </license>

        $this->xmlWriter->endElement(); // </permissions>
    }


    /**
     * Writes the {@code <abstract>} element.
     *
     * Iterates over the abstract's paragraphs (via {@code getAbstract()->getParagraphs()}).
     * Each paragraph is written as a {@code <p>} element; if the paragraph has
     * a JATS ID, an {@code id} attribute is added.
     *
     * @return void
     */
    private function writeAbstract(): void {
        $this->xmlWriter->startElement("abstract");

        foreach ($this->article->getAbstract()->getParagraphs() as $para) {
            $this->xmlWriter->startElement("p");
            $jid = $para->getJatsID();
            if (isset($jid)) $this->xmlWriter->writeAttribute("id", $jid);
            $this->xmlWriter->writeRaw($para->getText());
            $this->xmlWriter->endElement(); // </p>
        }

        $this->xmlWriter->endElement(); // </abstract>
    }


    /**
     * Writes the {@code <kwd-group kwd-group-type="author">} element.
     *
     * Each keyword is written as a {@code <kwd>} element. Nothing is written
     * if the Article has no keywords.
     *
     * @return void
     */
    private function writeKeywords(): void {
        if (count($this->article->getKeywords()) === 0) return;

        $this->xmlWriter->startElement("kwd-group");
        $this->xmlWriter->writeAttribute("kwd-group-type", "author");

        foreach ($this->article->getKeywords() as $keyword) {
            $this->xmlWriter->startElement("kwd");
            $this->xmlWriter->writeRaw($keyword->getName());
            $this->xmlWriter->endElement();
        }

        $this->xmlWriter->endElement(); // </kwd-group>
    }
}

?>