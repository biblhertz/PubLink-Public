<?php
namespace Biblhertz\Article\Adapters;

use Biblhertz\Article\om\GalleyFile;
use Biblhertz\Article\om\Article;
use Biblhertz\Article\om\Author;
use Biblhertz\Article\om\Affiliation;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Article\utilities\Utilities;
use DomDocument;
use DOMXPath;
use Exception;
use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Article\adapters\JATSXMLValidator;
use PDODatabase;

/********************************************************************/
/*      OMToJATSArticleAdapter                                      */
/*                                                                  */
/*      Author  :   Chris Tomlinson                                 */
/*      Date    :   11th July 2023                                  */
/*                                                                  */
/*      Exports the internal Article object model to a JATS XML     */
/*      document by merging metadata from the Article OM into an    */
/*      existing JATS XML template file.                            */
/*                                                                  */
/********************************************************************/

/**
 * Merges Article object model data into an existing JATS XML document.
 *
 * This adapter operates in-place on a source JATS XML file: it loads the
 * file, updates or inserts metadata nodes from the {@see Article} object model,
 * validates the result against the JATS schema, then saves the modified
 * document to an output path.
 *
 * After a successful export the adapter also:
 * - Replaces the existing JATS XML galley entry on the Article with a new
 *   {@see GalleyFile} pointing at the output file.
 * - Re-serialises and persists the updated Article to the database via the
 *   associated {@see SerializedObject}.
 *
 * Fields written to the JATS document:
 * - Journal title, article title, subtitle
 * - Volume, issue, first/last page (only when {@code <elocation-id>} is absent)
 * - Copyright year and holder
 * - Abstract paragraph
 * - Publication date (split into day/month/year nodes)
 * - Keywords (existing {@code <kwd>} nodes are replaced wholesale)
 * - Authors and affiliations (existing {@code <contrib>} and {@code <aff>}
 *   nodes are replaced wholesale; affiliations are de-duplicated by JATS ID)
 *
 * @package  Biblhertz\Article\Adapters
 * @author   Chris Tomlinson
 * @since    2023-07-11
 */
class OMToJATSArticleAdapter {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /**
     * The Article object model whose data will be merged into the JATS document.
     *
     * @var Article
     */
    private Article $article;

    /**
     * Absolute filesystem path to the source JATS XML template file to read from.
     *
     * @var string
     */
    private string $jatsXMLPath;

    /**
     * Absolute filesystem path for the modified JATS XML output file.
     *
     * @var string
     */
    private string $outputFilePath;

    /**
     * Verbose logging flag.
     *
     * @var bool
     */
    private bool $verbose;

    /**
     * The SerializedObject wrapper used to persist the updated Article to the database.
     *
     * @var SerializedObject
     */
    private SerializedObject $serializedObject;

    /**
     * Database connection used for persisting the serialised Article.
     *
     * @var PDODatabase
     */
    private PDODatabase $objDB;

    /**
     * Logger instance for recording export progress and errors.
     *
     * @var Logger
     */
    private Logger $logger;


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Constructs an empty adapter instance.
     *
     * All dependencies must be injected via setters before calling
     * {@see exportJATSArticle()}.
     */
    public function __construct() {
    }


    /****************************************************************/
    /*  INTERFACE METHODS                                           */
    /****************************************************************/

    /**
     * Sets the Article object model to export.
     *
     * @param Article $a The populated Article to serialise into JATS XML.
     * @return void
     */
    public function setArticle(Article $a): void {
        $this->article = $a;
    }

    /**
     * Returns the Article object model.
     *
     * @return Article
     */
    public function getArticle(): Article {
        return $this->article;
    }

    /**
     * Sets the path to the source JATS XML template file.
     *
     * @param string $path Absolute path to the input JATS XML file.
     * @return void
     */
    public function setJATSXMLPath(string $path): void {
        $this->jatsXMLPath = $path;
    }

    /**
     * Sets the output path for the merged JATS XML file.
     *
     * @param string $path Absolute path where the modified XML will be saved.
     * @return void
     */
    public function setOutputFilePath(string $path): void {
        $this->outputFilePath = $path;
    }

    /**
     * Enables or disables verbose logging.
     *
     * @param bool $v True for verbose output.
     * @return void
     */
    public function setVerbose(bool $v): void {
        $this->verbose = $v;
    }

    /**
     * Sets the SerializedObject used to persist the updated Article to the database.
     *
     * @param SerializedObject $o The wrapper object for database persistence.
     * @return void
     */
    public function setSerializedObject(SerializedObject $o): void {
        $this->serializedObject = $o;
    }

    /**
     * Sets the Logger instance for recording export activity.
     *
     * @param Logger $l Logger to use.
     * @return void
     */
    public function setLogger(Logger $l): void {
        $this->logger = $l;
    }


    /****************************************************************/
    /*  OTHER METHODS                                               */
    /****************************************************************/

    /**
     * Exports the Article object model into a JATS XML file.
     *
     * Reads the source JATS XML template from {@see $jatsXMLPath}, merges all
     * Article metadata into the DOM tree using {@see editOrAddNode()}, saves
     * the result to {@see $outputFilePath}, then validates it against the JATS
     * schema via {@see JATSXMLValidator}.
     *
     * Author/affiliation handling: all existing {@code <contrib>} and
     * {@code <aff>} elements are removed and rebuilt from the Article OM.
     * Affiliations are de-duplicated by JATS ID so that shared institutions
     * appear only once in the {@code <contrib-group>}.
     *
     * On success the adapter:
     * 1. Removes any existing JATS XML galley files from the Article.
     * 2. Creates a new {@see GalleyFile} for the output file and adds it.
     * 3. Re-serialises the Article and updates the {@code serialized_object}
     *    database row via {@see SerializedObject}.
     *
     * @throws Exception If the generated JATS XML fails schema validation,
     *                   or if any DOM/file operation throws.
     * @return void
     */
    public function exportJATSArticle(): void {

        try {
            // Load the source JATS XML template
            $fcontent = file_get_contents($this->jatsXMLPath);
            if ($fcontent === false) {
                throw new Exception("Failed to read JATS XML file: " . $this->jatsXMLPath);
            }
            $this->logger->print("Importing Article from :: " . $this->jatsXMLPath);

            $doc = new DOMDocument();
            $doc->formatOutput      = true;
            $doc->preserveWhiteSpace = false;
            $doc->loadXML($fcontent);
            $xpath = new DOMXPath($doc);

            // --- Journal and article title metadata ---
            $this->editOrAddNode($doc, $xpath, "/article/front/journal-meta/journal-title-group", "journal-title", $this->getArticle()->getJournalName());
            $this->editOrAddNode($doc, $xpath, "/article/front/article-meta/title-group",          "article-title", $this->getArticle()->getTitle());
            $this->editOrAddNode($doc, $xpath, "/article/front/article-meta/title-group",          "alt-title",     $this->getArticle()->getSubTitle());

            // --- Pagination: only written when <elocation-id> is absent ---
            // Articles using elocation IDs instead of page ranges must not have
            // volume/issue/fpage/lpage inserted, as they have a different structure.
            $eloc = $xpath->evaluate('/article/front/article-meta/elocation-id')->item(0);
            if (!isset($eloc)) {
                $this->editOrAddNode($doc, $xpath, "/article/front/article-meta", "volume", $this->getArticle()->getVolume(),    "elocation-id");
                $this->editOrAddNode($doc, $xpath, "/article/front/article-meta", "issue",  $this->getArticle()->getIssue(),     "volume");
                $this->editOrAddNode($doc, $xpath, "/article/front/article-meta", "fpage",  $this->getArticle()->getStartPage(), "issue");
                $this->editOrAddNode($doc, $xpath, "/article/front/article-meta", "lpage",  $this->getArticle()->getEndPage(),   "fpage");
            }

            // --- Copyright and abstract ---
            $this->editOrAddNode($doc, $xpath, "/article/front/article-meta/permissions", "copyright-year",   $this->getArticle()->getCopyRightYear());
            $this->editOrAddNode($doc, $xpath, "/article/front/article-meta/permissions", "copyright-holder", $this->getArticle()->getCopyRightHolder());
            $this->editOrAddNode($doc, $xpath, "/article/front/article-meta/abstract",    "p",                $this->getArticle()->getAbstract());

            // --- Publication date: split YYYY-MM-DD into day/month/year nodes ---
            $date = $this->getArticle()->getDate();
            if (!empty($date)) {
                $date = explode("-", $date);
                if (count($date) == 3) {
                    $this->editOrAddNode($doc, $xpath, "/article/front/article-meta/pub-date", "day",   $date[2]);
                    $this->editOrAddNode($doc, $xpath, "/article/front/article-meta/pub-date", "month", $date[1]);
                    $this->editOrAddNode($doc, $xpath, "/article/front/article-meta/pub-date", "year",  $date[0]);
                }
            }

            // --- Keywords: remove all existing <kwd> nodes then re-add from OM ---
            foreach ($xpath->evaluate('/article/front/article-meta/kwd-group/kwd') as $kwd) {
                $kwd->parentNode->removeChild($kwd);
            }

            $keywords = $this->getArticle()->getKeywords();
            $parent   = $xpath->evaluate('/article/front/article-meta/kwd-group');
            $parent   = $parent[0];

            if (count($keywords)) {
                // Create the <kwd-group> container if it doesn't yet exist
                if (!isset($parent)) {
                    $this->editOrAddNode($doc, $xpath, "/article/front/article-meta", "kwd-group", "");
                    $parent = $xpath->evaluate('/article/front/article-meta/kwd-group');
                    $parent = $parent[0];
                }
                foreach ($keywords as $keyword) {
                    $element = $doc->createElement("kwd", $keyword->getName());
                    if (isset($parent)) $parent->appendChild($element);
                }
            }

            // --- Authors and affiliations ---
            // Remove all existing <contrib> elements: we can't safely merge them,
            // so we rebuild the full list from the Article OM.
            foreach ($xpath->evaluate('/article/front/article-meta/contrib-group/contrib') as $author) {
                $author->parentNode->removeChild($author);
            }
            // Remove all existing <aff> elements for the same reason.
            foreach ($xpath->evaluate('/article/front/article-meta/contrib-group/aff') as $aff) {
                $aff->parentNode->removeChild($aff);
            }

            // Track unique affiliations by JATS ID to avoid duplicates in the output
            $addedAffiliations = [];

            foreach ($this->getArticle()->getAuthors() as $author) {
                $parent  = $xpath->evaluate('/article/front/article-meta/contrib-group');
                $parent  = $parent[0];

                // Build <contrib contrib-type="author"> with ORCID, name, email, and xref links
                $element = $doc->createElement("contrib");
                $element->setAttribute("contrib-type", "author");
                $insert  = $parent->appendChild($element);

                $cid = $doc->createElement("contrib-id", $author->getUniqueID());
                $cid->setAttribute("contrib-id-type", "orcid");
                $insert->appendChild($cid);

                $name = $doc->createElement("name");
                $name->appendChild($doc->createElement("surname",     $author->getLastName()));
                $name->appendChild($doc->createElement("given-names", $author->getFirstName()));
                $insert->appendChild($name);

                $insert->appendChild($doc->createElement("email", $author->getEmail()));

                // Write an <xref> for each affiliation and collect unique affiliations for later
                foreach ($author->getAffiliations() as $aff) {
                    $affilElement = $doc->createElement("xref");
                    $affilElement->setAttribute("ref-type", "aff");
                    $affilElement->setAttribute("rid", $aff->getJatsID());
                    $insert->appendChild($affilElement);

                    // Add to the unique affiliation list if not already present
                    $alreadyAdded = false;
                    foreach ($addedAffiliations as $a) {
                        if ($a->getJatsID() === $aff->getJatsID()) $alreadyAdded = true;
                    }
                    if (!$alreadyAdded) $addedAffiliations[] = $aff;
                }
            }

            // Append de-duplicated <aff> elements after all <contrib> elements
            $parent = $xpath->evaluate('/article/front/article-meta/contrib-group');
            $parent = $parent[0];
            foreach ($addedAffiliations as $aff) {
                $element = $doc->createElement("aff");
                $element->setAttribute("id", $aff->getJatsID());
                $parent->appendChild($element);

                $el1 = $doc->createElement("institution", $aff->getName());
                $el1->setAttribute("content-type", "orgname");
                $element->appendChild($el1);

                $el2 = $doc->createElement("institution", $aff->getDivision());
                $el2->setAttribute("content-type", "orgdiv1");
                $element->appendChild($el2);
            }

            // Save the modified document and validate against the JATS schema
            $doc->save($this->outputFilePath);

            $validator = new JATSXMLValidator($this->logger);
            //$validator->setLogger($this->logger);
            $valid = $validator->validateJATSXML($this->outputFilePath);

            if (!$valid) {
                throw new Exception("Generated JATS File failed validation against JATS schema");
            }

            // --- Galley file replacement ---
            // Remove the existing JATS XML galley (if any) and add a new one
            // pointing at the freshly generated output file.
            $this->logger->print("# galley files 1 :: " . count($this->getArticle()->getGalleyFiles()));

            $galleys = $this->getArticle()->getGalleyFiles();
            foreach ($galleys as $key => $galley) {
                if ($galley->getType() === GalleyFile::$JATSXML) {
                    $this->logger->print("Unsetting galley file $key :: " . $galley->getGalleyFilePath());
                    $this->getArticle()->removeGalleyFile($key);
                }
            }

            $this->logger->print("# galley files 2 :: " . count($this->getArticle()->getGalleyFiles()));

            $galley = new GalleyFile();
            $galley->setGalleyFilePath($this->outputFilePath);
            $galley->setGalleyFileAltText("JATS XML Galley File for this article");
            $galley->setType(GalleyFile::$JATSXML);
            $galley->setID(100);
            $galley->setBase64Encoding($this->outputFilePath);
            $galley->setGalleyFileSize();
            $this->getArticle()->addGalleyFile($galley);

            $this->logger->print("Added galley file :: " . $galley->getGalleyFilePath());
            $this->logger->print("# galley files 3 :: " . count($this->getArticle()->getGalleyFiles()));

            // Persist the updated Article back to the database via SerializedObject
            $serializedArticle = serialize($this->getArticle());
            $id = $this->serializedObject->getID();
            if (!is_int($id)) {
                throw new Exception("Invalid serialized object ID: expected int");
            }
            $vals = ['object' => $serializedArticle];
            $this->serializedObject->getObjDB()->update(
                "serialized_object",
                $vals,
                "id=" . $id
            );

        } catch (Exception $e) {
            $this->logger->print("!!! ERROR :: in " . $e->getFile() . " on line " . $e->getLine() . "::" . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Updates an existing XML node's value, or creates and inserts it if absent.
     *
     * Searches the DOM for {@code $path/$node} using the supplied XPath object.
     * If the node is found, its text content is updated in place and the method
     * returns immediately.
     *
     * If the node is not found, a new element is created and inserted using one
     * of two strategies depending on whether {@code $after} is provided:
     *
     * - **After mode** (`$after` non-empty): inserts the new element as the next
     *   sibling of the first element named {@code $after} in the document.
     *   If no such element exists the insertion is silently skipped.
     * - **Append mode** (`$after` empty / false): appends the new element as the
     *   last child of the node matched by {@code $path}.
     *   If {@code $path} matches nothing the insertion is silently skipped.
     *
     * @param DOMDocument $dom   The document to operate on.
     * @param DOMXPath    $xpath XPath evaluator bound to {@see $dom}.
     * @param string      $path  XPath expression identifying the parent element
     *                           (e.g. {@code "/article/front/article-meta"}).
     * @param string      $node  Local name of the child element to update or create
     *                           (e.g. {@code "volume"}).
     * @param string      $value Text content to set on the element.
     * @param bool|string $after Optional sibling reference: the local element name
     *                           after which the new node should be inserted.
     *                           Pass {@code false} (default) to append to parent.
     * @return void
     */
    private function editOrAddNode(DomDocument $dom, DOMXPath $xpath, string $path, string $node, string $value, bool|string $after = false): void {

        // If the node already exists, update its value and return
        $res = $xpath->query($path . "/" . $node);
        if ($res->length > 0) {
            $res[0]->nodeValue = $value;
            return;
        }

        $element = $dom->createElement($node, $value);

        if ($after) {
            // Insert as the next sibling of the named reference element
            $refNode = $dom->getElementsByTagName($after)->item(0);
            if ($refNode !== null) {
                $refNode->parentNode->insertBefore($element, $refNode->nextSibling);
            }
            return;
        }

        // Default: append as last child of the parent matched by $path
        $existingNode = $xpath->query($path)->item(0);
        if ($existingNode === null) return;
        $existingNode->appendChild($element);
    }
}

?>