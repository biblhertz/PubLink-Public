<?php
namespace Biblhertz\Article\Adapters;

use Biblhertz\Article\om\GalleyFile;
use Biblhertz\Article\om\Article;
use Biblhertz\Article\om\AAbstract;
use Biblhertz\Article\om\Paragraph;
use Biblhertz\Article\om\Author;
use Biblhertz\Article\om\Affiliation;
use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\Book;
use Biblhertz\Article\om\Chapter;
use Biblhertz\Article\om\ConferencePaper;
use Biblhertz\Article\om\JournalArticle;
use Biblhertz\Article\om\WebPage;
use Biblhertz\Article\om\Manuscript;
use Biblhertz\Article\om\Thesis;
use Biblhertz\Article\om\Person;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Publink\utilities\Utilities;
use SimpleXMLElement;

/**
 * JATSToOMAdapter
 *
 * Adapter that parses a JATS XML document and populates a PubLink
 * {@see Article} object model. Implements the Adapter pattern between
 * the JATS Publishing Tag Set (journal article metadata standard) and
 * the internal Article/Author/Affiliation/Reference domain objects.
 *
 * Processing pipeline (via {@see generateObjectModel()}):
 *   1. The JATS XML galley file is always added first.
 *   2. An optional cover image file is added if supplied.
 *   3. Any additional galley files registered via {@see addGalleyFile()} are added.
 *   4. {@see importArticle()} parses the XML into three structural sections:
 *        - Section 0 (front): journal metadata, article metadata, authors,
 *          affiliations, abstract, keywords, dates, DOI, copyright, license.
 *        - Section 1 (body): raw XML stored as a body tag for downstream rendering.
 *        - Section 2 (back): reference list, "cite-as" section, footnotes.
 *
 * JATS document structure assumed:
 * ```
 * <article>
 *   <front>   <!-- child 0 -->
 *   <body>    <!-- child 1 -->
 *   <back>    <!-- child 2 -->
 * </article>
 * ```
 *
 * @package  Biblhertz\Article\Adapters
 * @author   Chris Tomlinson
 * @since    11th July 2023
 */
class JATSToOMAdapter {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var Article The Article object model being built */
    private Article $article;

    /** @var string Absolute path to the input directory for resolving relative file paths */
    private string $inputDir;

    /** @var string Absolute filesystem path to the JATS XML source file */
    private string $jatsXMLPath;

    /**
     * @var string File ID for the JATS XML galley entry in OJS.
     *             Used to cross-reference the file within the OJS import manifest.
     */
    private string $jatsXMLID;

    /** @var string OJS username to associate with the imported article */
    private string $ojsUser;

    /** @var bool When true, additional progress detail is written to the logger */
    private bool $verbose;

    /**
     * @var File Cover image File object, if one has been associated.
     *           Optional — checked via isset() in {@see generateObjectModel()}.
     *
     */
    private File $coverImageFile;

    /**
     * @var GalleyFile[] Additional galley files (PDFs, HTMLs, etc.) to attach
     *                   to the article beyond the mandatory JATS XML galley.
     */
    private array $galleyFiles = [];

    /** @var Logger Logger instance for progress and diagnostic output */
    private Logger $logger;

    /**
     * @var Reference[] Accumulator for reference objects built by {@see getReferences()}.
     *
     */
    private array $references = [];


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs the adapter, initialises an empty Article, and enables
     * verbose logging by default.
     *
     * Call the setter methods to supply the JATS XML path, OJS user, logger,
     * and any galley files before invoking {@see generateObjectModel()}.
     */
    public function __construct() {
        $this->article = new Article();
        $this->verbose = true;
    }


    /****************************************************************/
    /*  ACCESSOR METHODS                                            */
    /****************************************************************/

    /**
     * Returns the Article object model populated by {@see generateObjectModel()}.
     *
     * @return Article
     */
    public function getArticle(): Article {
        return $this->article;
    }

    /**
     * Sets the input directory path for resolving relative file references.
     *
     * @param string $dir Absolute directory path
     */
    public function setInputDir(string $dir): void {
        $this->inputDir = $dir;
    }

    /**
     * Sets the absolute path to the JATS XML source file.
     *
     * @param string $path Absolute filesystem path to the .xml file
     */
    public function setJATSXMLPath(string $path): void {
        $this->jatsXMLPath = $path;
    }

    /**
     * Sets the OJS file ID for the JATS XML galley entry.
     *
     * @param string $id OJS file identifier string
     */
    public function setJATSXMLID(string $id): void {
        $this->jatsXMLID = $id;
    }

    /**
     * Sets the OJS username to associate with the imported article.
     *
     * @param string $user OJS username
     */
    public function setOJSUser(string $user): void {
        $this->ojsUser = $user;
    }

    /**
     * Enables or disables verbose progress logging.
     *
     * @param bool $v True to enable verbose output
     */
    public function setVerbose(bool $v): void {
        $this->verbose = $v;
    }

    /**
     * Sets the cover image File object to attach to this article.
     *
     * Optional — if not set, no cover image galley is added.
     *
     * @param File $file Cover image File object
     */
    public function setCoverImageFile(File $file): void {
        $this->coverImageFile = $file;
    }

    /**
     * Adds an additional GalleyFile (e.g. PDF, HTML) to be attached to the article.
     *
     * May be called multiple times to register multiple galley files. They are
     * processed in registration order during {@see generateObjectModel()}.
     *
     * @param GalleyFile $file
     */
    public function addGalleyFile(GalleyFile $file): void {
        $this->galleyFiles[] = $file;
    }

    /**
     * Sets the logger for progress and diagnostic output.
     *
     * @param Logger $l
     */
    public function setLogger(Logger $l): void {
        $this->logger = $l;
    }


    /****************************************************************/
    /*  GENERATION METHODS                                          */
    /****************************************************************/

    /**
     * Builds the Article object model from the configured JATS XML file and
     * associated galley files.
     *
     * Galley file order:
     *   1. JATS XML galley — always added first, with base64 encoding and size.
     *   2. Cover image — added if {@see setCoverImageFile()} was called.
     *   3. Additional galleys — added in registration order.
     *   4. Article metadata — imported by {@see importArticle()} from the XML.
     *
     * The section reference is hardcoded to "ART"; the OJS username setter is
     * currently commented out.
     */
    public function generateObjectModel(): void {

        // --- 1. JATS XML galley (mandatory, always first) ---
        $id = 100;  // Galley ID seed; incremented for each subsequent galley
        $this->logger->print("Adding JATS XML Galley File to OM :: " . $this->jatsXMLPath);
        $galley = new GalleyFile();
        $galley->setGalleyFilePath($this->jatsXMLPath);
        $galley->setGalleyFileAltText("JATS XML Galley File for this article");
        $galley->setType(GalleyFile::$JATSXML);
        $galley->setID($id);
        $galley->setFileID($this->jatsXMLID);
        $galley->setBase64Encoding($this->jatsXMLPath);
        $galley->setGalleyFileSize();
        $galley->setName(basename($this->jatsXMLPath));
        $this->article->addGalleyFile($galley);
        $id++;

        // --- 2. Cover image galley (optional) ---
        if (isset($this->coverImageFile)) {
            $this->logger->print("Adding Cover Image File to OM :: " . $this->coverImageFile->getName());
            $galley = GalleyFile::getGalleyFile($this->coverImageFile);
            $galley->setGalleyFileAltText("Cover Image File for this article");
            $galley->setType(GalleyFile::$COVER_IMAGE);
            $this->article->addGalleyFile($galley);
        }

        // --- 3. Additional galley files ---
        foreach ($this->galleyFiles as $file) {
            $this->logger->print("Scanning Galley Files :: Found :: " . $file->getName());
            $galley = GalleyFile::getGalleyFile($file);
            $this->article->addGalleyFile($galley);
            $this->logger->print("Added Galley File :: " . $galley->getGalleyFileName() . " :: " . $galley->getGalleyFileAltText());
        }

        // --- 4. Parse article metadata from JATS XML ---
        $this->article->setSectionRef("ART");
        $fcontent = file_get_contents($this->jatsXMLPath);
        if ($fcontent === false) {
            throw new \Exception("Could not read JATS XML file: " . $this->jatsXMLPath);
        }
        $this->importArticle($fcontent);
    }

    /**
     * Parses a JATS XML string and populates all article metadata fields.
     *
     * Iterates over the top-level children of the <article> element, treating
     * them as indexed sections:
     *   - Index 0 (front): journal metadata, article metadata, authors,
     *     abstract, keywords, dates, DOI, copyright, and license.
     *   - Index 1 (body): the raw XML body is stored as a tag string for
     *     downstream rendering without further parsing.
     *   - Index 2 (back): reference list, cite-as section, and footnote group.
     *
     * All text values are passed through {@see Utilities::to_utf()} to
     * normalise encoding. The xml:lang attribute is extracted via XPath and
     * truncated to a 2-character ISO language code if longer.
     *
     * @param string $fcontent Raw JATS XML file contents
     * @throws \Exception On XML parse failure or any other processing error;
     *                    error details are written to the logger before re-throwing
     */
    private function importArticle(string $fcontent): void {
        try {
            $this->logger->print("Importing Article from :: " . $this->jatsXMLPath);
            $xml = simplexml_load_string($fcontent);
            if ($xml === false) {
                throw new \Exception("Failed to parse JATS XML from: " . $this->jatsXMLPath);
            }

            $c = 0;
            foreach ($xml->children() as $xmlarticle) {

                // --- Section 0: <front> — all metadata ---
                if ($c == 0) {

                    // Extract and normalise the locale/language code
                    $result = $xml->xpath('//@xml:lang');
                    $lang   = (string) $result[0];
                    if (strlen($lang) > 2) $lang = substr($lang, 0, 2);  // Truncate e.g. "en-US" → "en"
                    $this->logger->print("Extracted Locale as $lang");
                    if (!empty($lang)) $this->article->setLocale($lang);

                    // Journal-level metadata from <journal-meta>
                    $this->article->setJournalName(Utilities::to_utf((string) $xmlarticle->{'journal-meta'}->{'journal-title-group'}->{'journal-title'}));
                    $this->article->setJournalAbbreviation(Utilities::to_utf((string) $xmlarticle->{'journal-meta'}->{'journal-title-group'}->{'abbrev-journal-title'}));
                    $this->article->setJournalID(Utilities::to_utf((string) $xmlarticle->{'journal-meta'}->{'journal-id'}));
                    $this->article->setJournalISSN(Utilities::to_utf((string) $xmlarticle->{'journal-meta'}->{'issn'}));
                    $this->article->setJournalPublisher(Utilities::to_utf((string) $xmlarticle->{'journal-meta'}->{'publisher'}->{'publisher-name'}));
                    $this->article->setJournalLocation(Utilities::to_utf((string) $xmlarticle->{'journal-meta'}->{'publisher'}->{'publisher-loc'}));

                    // Article-level titles from <title-group>
                    $this->article->setTitle(Utilities::to_utf((string) $xmlarticle->{'article-meta'}->{'title-group'}->{'article-title'}));
                    $this->article->setSubTitle(Utilities::to_utf((string) $xmlarticle->{'article-meta'}->{'title-group'}->{'subtitle'}));
                    $this->article->setAltTitle(Utilities::to_utf((string) $xmlarticle->{'article-meta'}->{'title-group'}->{'alt-title'}));

                    // <trans-title-group xml:lang="en"> → English translated title
                    foreach ($xmlarticle->{'article-meta'}->{'title-group'}->{'trans-title-group'} as $ttg) {
                        $lang = (string) ($ttg->attributes('xml', true)['lang'] ?? '');
                        if (strtolower($lang) === 'en') {
                            $this->article->setTransTitle(Utilities::to_utf(trim((string) $ttg->{'trans-title'})));
                            break;
                        }
                    }

                    // Authors and affiliations — affiliations may be at article-meta level
                    // or nested inside contrib-group; fall back to the nested form if top-level is empty
                    $authors      = $xmlarticle->{'article-meta'}->{'contrib-group'};
                    $affiliations = $xmlarticle->{'article-meta'}->{'aff'};
                    if ((string) $affiliations === "") {
                        // No top-level <aff> elements — try contrib-group > aff
                        $affiliations = $xmlarticle->{'article-meta'}->{'contrib-group'}->{'aff'};
                    }

                    // Keywords from <kwd-group>
                    $keywords = $xmlarticle->{'article-meta'}->{'kwd-group'}->{'kwd'};
                    if (isset($keywords)) {
                        foreach ($keywords as $keyword) {
                            $this->article->setKeyword((string) $keyword);
                        }
                    }

                    // Abstract — handle both single-paragraph and multi-paragraph forms,
                    // including <p> nested inside <sec> children of <abstract>
                    $abstract = new AAbstract();
                    $paras    = $xmlarticle->{'article-meta'}->{'abstract'}->{'p'};
                    if (!isset($paras) || count($paras) == 0) {
                        $paras = $xmlarticle->{'article-meta'}->{'abstract'}->xpath('.//p') ?: [];
                    }
                    if (empty($paras)) {
                        // No <p> found anywhere — treat the entire <abstract> as one paragraph
                        $paragraph = new Paragraph(Utilities::to_utf($xmlarticle->{'article-meta'}->{'abstract'}->asXML()));
                        $paragraph->setJatsID(uniqid());
                        $abstract->addParagraph($paragraph);
                    } else {
                        foreach ($paras as $p) {
                            $paragraph = new Paragraph(Utilities::to_utf($p->asXML()));
                            $pid = (string) $p->attributes()->{'id'};
                            $paragraph->setJatsID(!empty($pid) ? $pid : uniqid());
                            $abstract->addParagraph($paragraph);
                        }
                    }
                    $this->article->setAbstract($abstract);

                    $this->getAuthors($authors, $affiliations);

                    // Publication date — zero-pad single-digit month and day
                    $month = (string) $xmlarticle->{'article-meta'}->{'pub-date'}->{'month'};
                    $day   = (string) $xmlarticle->{'article-meta'}->{'pub-date'}->{'day'};
                    if ($month < 10 && strlen($month) == 1) $month = "0" . $month;
                    if ($day   < 10 && strlen($day)   == 1) $day   = "0" . $day;
                    $year = (string) $xmlarticle->{'article-meta'}->{'pub-date'}->{'year'};
                    $this->article->setDate($year . "-" . $month . "-" . $day);
                    $this->article->setVolume((string) $xmlarticle->{'article-meta'}->{'volume'});
                    $this->article->setIssue((string) $xmlarticle->{'article-meta'}->{'issue'});
                    $this->article->setStartPage((string) $xmlarticle->{'article-meta'}->{'fpage'});
                    $this->article->setEndPage((string) $xmlarticle->{'article-meta'}->{'lpage'});
                    $this->article->setYear($year);
                    $this->article->setMonth($month);
                    $this->article->setDay($day);
                    $this->article->setHistoryTag($xmlarticle->{'article-meta'}->{'history'}->asXML());

                    // Copyright fields — only set if the element exists
                    if (isset($xmlarticle->{'article-meta'}->{'permissions'}->{'copyright-holder'}))
                        $this->article->setCopyRightHolder(Utilities::to_utf((string) $xmlarticle->{'article-meta'}->{'permissions'}->{'copyright-holder'}));
                    if (isset($xmlarticle->{'article-meta'}->{'permissions'}->{'copyright-year'}))
                        $this->article->setCopyRightYear(Utilities::to_utf((string) $xmlarticle->{'article-meta'}->{'permissions'}->{'copyright-year'}));

                    $cstatement = $xmlarticle->{'article-meta'}->{'permissions'}->{'copyright-statement'};
                    if (isset($cstatement))
                        $this->article->setCopyStatement(Utilities::to_utf($cstatement->asXML()));

                    $licensep = $xmlarticle->{'article-meta'}->{'permissions'}->{'license'}->{'license-p'};
                    if (isset($licensep))
                        $this->article->setLicenseParagraph(Utilities::to_utf($licensep->asXML()));

                    // License URL from xlink:href and license-type attribute
                    $license = $xmlarticle->{'article-meta'}->{'permissions'}->{'license'};
                    if (isset($license)) {
                        // Extract xlink:href using the XLink namespace
                        $atts = $license->attributes('http://www.w3.org/1999/xlink');
                        if (isset($atts)) {
                            foreach ($atts as $a => $b) {
                                if ($a === "href") $this->article->setLicenseUrl((string) $b);
                            }
                        }
                        // Extract license-type from the default namespace attributes
                        foreach ($license->attributes() as $a => $b) {
                            if ($a === "license-type") $this->article->setLicenseType((string) $b);
                        }
                    }

                    // DOI — scan all <article-id> elements for pub-id-type="doi"
                    $dois = $xmlarticle->{'article-meta'}->{'article-id'};
                    $doi  = "";
                    foreach ($dois as $doirec) {
                        $doistr = $doirec->attributes()->{'pub-id-type'};
                        if ((string) $doistr === "doi")
                            $doi = str_replace("https://doi.org/", "", (string) $doirec);
                    }
                    $this->article->setDOI($doi);

                // --- Section 1: <body> — store raw XML for rendering ---
                } elseif ($c == 1) {
                    $this->article->setBodyTag($xmlarticle->asXML());

                // --- Section 2: <back> — references, cite-as, footnotes ---
                } elseif ($c == 2) {

                    // Extract the optional "cite-as" section if present
                    $xpath = $xmlarticle->xpath("//sec[@sec-type='cite-as']");
                    if (isset($xpath) && is_array($xpath) && !empty($xpath[0])) {
                        $cite = $xpath[0];
                        if ($cite instanceof SimpleXMLElement) {
                            $this->article->setCiteAs($cite->asXML());
                            if ($this->verbose) {
                                $this->logger->print("Cite Section :: $cite");
                                $this->logger->println();
                            }
                        }
                    }

                    // Process all <ref-list> elements (a JATS document may have multiple)
                    $reflists = $xmlarticle->{'ref-list'};
                    foreach ($reflists as $reflist) {
                        $this->article->setReferences($this->getReferences($reflist));
                    }

                    $this->article->setFootNotesTag($xmlarticle->{'fn-group'}->asXML());
                }

                $c++;
            }

            if ($this->verbose) {
                $this->logger->print("Created Article Object");
                $this->logger->println();
            }

        } catch (\Exception $e) {
            // Log full error context before re-throwing so the worker log captures it
            $this->logger->print("!!! ERROR :: in " . $e->getFile() . " on line " . $e->getLine() . "::" . $e->getMessage());
            error_log("!!! ERROR :: in " . $e->getFile() . " on line " . $e->getLine() . "::" . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Parses the <contrib-group> element and populates the Article with Author objects.
     *
     * For each <contrib> child element the following fields are extracted:
     * - JATS ID, unique ID (falls back to JATS ID if not set)
     * - First name, last name
     * - ORCID (from <contrib-id contrib-id-type="orcid">)
     * - Email, biography
     * - Deceased and corresponding-author flags
     * - Affiliations — resolved by matching <xref ref-type="aff" rid="...">
     *   against the provided $affxml element set
     *
     * Authors are added to the Article only if at least one of firstName or
     * lastName is non-empty. Each resolved Affiliation is added to both the
     * Author object and the Article-level affiliation list.
     *
     * @param SimpleXMLElement $authorxml The <contrib-group> element
     * @param SimpleXMLElement $affxml    The <aff> element(s) — may be at
     *                                    article-meta level or inside contrib-group
     */
    private function getAuthors(SimpleXMLElement $authorxml, SimpleXMLElement $affxml): void {
        $this->logger->print("Authors :: " . $authorxml);
        $this->logger->print("Affiliations :: " . $affxml);

        foreach ($authorxml->children() as $author) {
            $authorObj = new Author();

            // Identity
            $authorObj->setJatsID(Utilities::to_utf($author->attributes()->{'id'}));
            if (empty($authorObj->getJatsID()))     $authorObj->setJatsID(uniqid());
            if (empty($authorObj->getUniqueID()))   $authorObj->setUniqueID($authorObj->getJatsID());

            $authorObj->setFirstName(Utilities::to_utf((string) $author->{'name'}->{'given-names'}));
            $authorObj->setLastName(Utilities::to_utf((string) $author->{'name'}->{'surname'}));

            // ORCID — only set if contrib-id-type is explicitly "orcid"
            if (isset($author->{'contrib-id'})) {
                $contribType = $author->{'contrib-id'}->attributes()->{'contrib-id-type'};
                if (!empty($contribType) && (string) $contribType === "orcid")
                    $authorObj->setOrcID(Utilities::to_utf((string) $author->{'contrib-id'}));
            }

            $authorObj->setEmail(Utilities::to_utf((string) $author->{'email'}));
            $authorObj->setBiography(Utilities::to_utf((string) $author->{'bio'}->asXML()));

            // Deceased flag
            $deceased = $author->attributes()->{'deceased'};
            $authorObj->setDeceased(isset($deceased) && (string) $deceased === "true");

            // Corresponding author flag
            $corresp = $author->attributes()->{'corresp'};
            $authorObj->setCorrespondingAuthor(isset($corresp) && (string) $corresp === "yes");

            // Affiliations — resolve each <xref ref-type="aff"> back to the <aff> element
            foreach ($author->{'xref'} as $xref) {
                if ((string) $xref->attributes()->{'ref-type'} === "aff") {
                    $affKey = (string) $xref->attributes()->{'rid'};

                    foreach ($affxml as $aff) {
                        $affid = (string) $aff->attributes()->{'id'};
                        if ($this->verbose) $this->logger->print("Affiliation Keys :: $affKey :: $affid");

                        if ($affid === $affKey) {
                            $affiliationObj = new Affiliation();
                            $affiliationObj->setJatsID($affid);
                            $affiliationObj->setCity(Utilities::to_utf((string) $aff->{'city'}));
                            $affiliationObj->setCountry(Utilities::to_utf((string) $aff->{'country'}));
                            $affiliationObj->setAddress(Utilities::to_utf((string) $aff->{'addr-line'}));

                            // Institution name and division from <institution content-type="orgname/orgdiv1">
                            foreach ($aff->{'institution'} as $inst) {
                                if ((string) $inst->attributes()->{'content-type'} === "orgname")
                                    $affiliationObj->setName(Utilities::to_utf((string) $inst));
                                elseif ((string) $inst->attributes()->{'content-type'} === "orgdiv1")
                                    $affiliationObj->setDivision(Utilities::to_utf((string) $inst));
                            }

                            // Fallback: if no orgname institution element, use the raw <aff> XML as the name
                            if (empty($affiliationObj->getName()))
                                $affiliationObj->setName(Utilities::to_utf((string) $aff->asXML()));

                            $authorObj->addAffiliation($affiliationObj);
                            $this->getArticle()->addAffiliation($affiliationObj);
                        }
                    }
                }
            }

            // Only add authors with at least one name component
            if ($authorObj->getFirstName() !== "" || $authorObj->getLastName() !== "")
                $this->getArticle()->addAuthor($authorObj);
        }
    }

    /**
     * Parses a <ref-list> element and returns an array of typed Reference objects.
     *
     * For each <ref> child, the publication type is read from the
     * <element-citation> (falling back to <mixed-citation> if absent) and
     * mapped to the appropriate Reference subclass:
     *
     * | JATS publication-type               | Domain class     |
     * |--------------------------------------|------------------|
     * | book, confproc                       | Book             |
     * | chapter                              | Chapter          |
     * | article-journal, journal, preprint   | JournalArticle   |
     * | paper-conference                     | ConferencePaper  |
     * | manuscript                           | Manuscript       |
     * | webpage                              | WebPage          |
     * | thesis                               | Thesis           |
     *
     * Authors are extracted from <person-group> elements, distinguishing
     * editors (person-group-type="editor") from authors. If no <person-group>
     * is present, <string-name> elements within the citation are used instead.
     *
     * The reference label is taken from <label> if present, otherwise the
     * cleaned <ref id> attribute is used. After construction each reference
     * has createFromJatsXMLFragment() called to populate any remaining fields.
     *
     * @param SimpleXMLElement $refxml A <ref-list> element
     * @return Reference[]             Array of populated Reference subclass objects
     *
     * $this->references accumulates across multiple calls — if getReferences()
     * is called more than once (e.g. for documents with multiple <ref-list>
     * sections), references from earlier lists will be included in later results.
     */
    private function getReferences(SimpleXMLElement $refxml): array {

        foreach ($refxml->children() as $ref) {
            $label = Utilities::to_utf((string) $ref->{'label'});
            $id    = Utilities::to_utf(JATSToOMAdapter::cleanPubId((string) $ref->attributes()->{'id'}));
            if (empty($label)) $label = $id;    // Fall back to cleaned ID if no label element

            if (!empty($id)) {
                $citation = $ref->{'element-citation'};

                if (isset($citation)) {
                    $atts = $citation->attributes();
                    if (isset($atts)) {
                        $type = Utilities::to_utf((string) $atts->{'publication-type'});
                    } else {
                        // Fallback: try <mixed-citation> if <element-citation> has no attributes
                        $citation = $ref->{'mixed-citation'};
                        $atts     = $citation->attributes();
                        if (isset($atts))
                            $type = Utilities::to_utf((string) $atts->{'publication-type'});
                    }

                    $this->logger->print("Reference Type retrieved is $type");

                    // Map publication-type to the appropriate Reference subclass
                    if ($type === "book" || $type === "confproc") {
                        $refObj = new Book();
                        $this->logger->print("Created Book Reference Object");
                    } elseif ($type === "chapter") {
                        $refObj = new Chapter();
                        $this->logger->print("Created Chapter Reference Object");
                    } elseif ($type === "article-journal" || $type === "journal" || $type === "preprint") {
                        $type   = "article-journal";    // Normalise all journal-type variants
                        $refObj = new JournalArticle();
                        $this->logger->print("Created Journal Article Reference Object");
                    } elseif ($type === "paper-conference") {
                        $refObj = new ConferencePaper();
                        $this->logger->print("Created Conference Paper Reference Object");
                    } elseif ($type === "manuscript") {
                        $refObj = new Manuscript();
                        $this->logger->print("Created Manuscript Reference Object");
                    } elseif ($type === "webpage") {
                        $refObj = new WebPage();
                        $this->logger->print("Created Web Page Reference Object");
                    } elseif ($type === "thesis") {
                        $refObj = new Thesis();
                        $this->logger->print("Created Thesis Reference Object");
                    } else {
                        // Unknown type — log and skip this reference
                        $this->logger->print("!! ERROR :: unknown reference type encountered :: $type");
                        error_log("Unknown reference type encountered in JATS document :: $type");
                        $this->logger->println();
                        continue;
                    }

                    // Common fields shared by all reference types
                    if (isset($citation->{'year'}))
                        $refObj->setYear(Utilities::to_utf((string) $citation->{'year'}));
                    if (isset($citation->{'pub-id'})) {
                        $refObj->setPubId(Utilities::to_utf((string) $citation->{'pub-id'}));
                        $refObj->setPubIdType(Utilities::to_utf((string) $citation->{'pub-id'}->attributes()->{'pub-id-type'}));
                    }
                    if (isset($citation->{'uri'}))
                        $refObj->setURI(Utilities::to_utf((string) $citation->{'uri'}));
                    if (isset($citation->{'source'})) {
                        $src = (string) $citation->{'source'};
                        if (empty($src)) $src = trim(strip_tags($citation->{'source'}->asXML()));
                        $refObj->setSource(Utilities::to_utf($src));
                    }
                    if (isset($citation->{'publisher-name'}))
                        $refObj->setPublisher(Utilities::to_utf((string) $citation->{'publisher-name'}));
                    if (isset($citation->{'publisher-loc'}))
                        $refObj->setAddress(Utilities::to_utf((string) $citation->{'publisher-loc'}));

                    $refObj->setLabel($label);
                    $refObj->setJatsID($id);
                    $refObj->setPublicationType($type);

                    // Parse authors and editors from <person-group> or <string-name>
                    $authors  = $citation->{'person-group'};
                    $authArr  = [];
                    $edArr    = [];

                    if (!count($authors)) {
                        // No <person-group> — fall back to bare <string-name> elements
                        $this->logger->print("Adding single author");
                        foreach ($citation->{'string-name'} as $a) {
                            $fn = isset($a->{'given-names'}) ? Utilities::to_utf((string) $a->{'given-names'}) : "";
                            $ln = isset($a->{'surname'})     ? Utilities::to_utf((string) $a->{'surname'})     : "";
                            if (!empty($fn) && !empty($ln)) {
                                $authArr[] = $this->getAuthorObj($fn, $ln);
                                $this->logger->print("Added Reference Author :: $fn $ln");
                            }
                        }
                    } else {
                        // Extract from <person-group> — iterate over all <name>/<string-name> children
                        foreach ($authors as $a) {
                            $pgType = (string) $a->attributes()->{'person-group-type'};
                            $this->logger->print("Person Group Type :: $pgType");

                            foreach ($a->{'name'} as $name) {
                                $fn = Utilities::to_utf((string) $name->{'given-names'});
                                $ln = Utilities::to_utf((string) $name->{'surname'});
                                if (!empty($fn) && !empty($ln)) {
                                    $person = $this->getAuthorObj($fn, $ln);
                                    if ($pgType === "editor") $edArr[] = $person; else $authArr[] = $person;
                                    $this->logger->print("Added Reference Author :: $fn $ln");
                                }
                            }
                            foreach ($a->{'string-name'} as $name) {
                                $fn = Utilities::to_utf((string) $name->{'given-names'});
                                $ln = Utilities::to_utf((string) $name->{'surname'});
                                if (!empty($fn) && !empty($ln)) {
                                    $person = $this->getAuthorObj($fn, $ln);
                                    if ($pgType === "editor") $edArr[] = $person; else $authArr[] = $person;
                                    $this->logger->print("Added Reference Author :: $fn $ln");
                                }
                            }
                        }
                    }

                    $refObj->setAuthors($authArr);
                    if (count($edArr)) $refObj->setEditors($edArr);

                    // Delegate remaining field population to the Reference object itself
                    $refObj->createFromJatsXMLFragment($citation);
                    $this->logger->print($refObj->getTitle());

                    $this->references[] = $refObj;
                    $this->logger->println();
                }
            }
        }

        return $this->references;
    }

    /**
     * Constructs a minimal Author object from a first and last name.
     *
     * Used when building reference author lists where only name data is
     * available (no ORCID, email, or affiliation).
     *
     * @param string $fn First (given) name
     * @param string $ln Last (family) name
     * @return Author
     */
    private function getAuthorObj(string $fn, string $ln): Author {
        $author = new Author();
        $author->setFirstName($fn);
        $author->setLastName($ln);
        return $author;
    }

    /**
     * Strips well-known URL prefixes from a publication identifier string.
     *
     * Removes "https://hdl.handle.net/" and "https://doi.org/" prefixes and
     * trims surrounding whitespace, leaving a bare DOI or handle string
     * suitable for storage and display.
     *
     * @param string $pid Raw publication ID (may be a full URL or a bare ID)
     * @return string     Cleaned publication ID
     */
    public static function cleanPubId(string $pid): string {
        $pid = trim($pid);
        $pid = str_replace("https://hdl.handle.net/", "", $pid);
        $pid = str_replace("https://doi.org/", "", $pid);
        return $pid;
    }
}
?>