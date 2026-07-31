<?php

namespace Biblhertz\Article\om;

use RenanBr\BibTexParser\Listener;
use RenanBr\BibTexParser\Parser;
use RenanBr\BibTexParser\Processor;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\Utilities;
use XMLWriter;
use SimpleXMLElement;

/**
 * Reference
 *
 * Abstract superclass for all reference types in the PubLink article model
 * ({@see JournalArticle}, {@see Book}, {@see Chapter}, {@see ConferencePaper},
 * {@see WebPage}, {@see Manuscript}). Extends {@see ArticleObject}.
 *
 * Provides the common bibliographic fields, BibTeX import/export, JATS XML
 * export helpers, CSL/JSON serialisation, and the reference-checking
 * infrastructure shared by all concrete reference subclasses.
 *
 * Subclasses must implement:
 * - {@see createFromJatsXMLFragment()} — populate fields from a JATS `<ref>` element
 * - {@see getFilterType()}             — return a CrossRef API type filter string
 * - {@see getJATSReference()}          — serialise to a JATS `<element-citation>` block
 *
 * **BibTeX field mappings** (`$mappings`) define the correspondence between
 * object model getter names and BibTeX field names, and are used by both
 * {@see updateFromBibtex()} (import) and {@see getBibtexReference()} (export).
 * Subclasses may override entries in `$mappings` to remap fields for their
 * specific type (e.g. {@see Chapter} remaps `Title` → `booktitle`).
 *
 * **Reference checking** stores candidate references from external API lookups
 * (CrossRef, Google Books, Primo) against a key (e.g. `"crossref"`, `"google"`,
 * `"alma"`) via {@see setRefCheck()} / {@see getRefCheck()}.
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
abstract class Reference extends ArticleObject {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * Whether this object may be edited via the GUI.
     * Editing is disabled by default for references; subclasses may override.
     *
     * @var mixed  Intentionally untyped for legacy compatibility.
     */
    public static bool $ALLOW_EDIT = false;

    /** @var string Citation label / BibTeX citation key. */
    protected string $label = "";

    /**
     * Public identifier for this reference (e.g. DOI, handle).
     * The identifier type is stored separately in {@see $pubIdType}.
     *
     * @var string
     */
    protected string $pubId = "";

    /**
     * Type of the public identifier stored in {@see $pubId}.
     * Common values: `"doi"`, `"pmid"`, `"other"`. Defaults to `"unset"`.
     *
     * @var string
     */
    protected string $pubIdType = "unset";

    /**
     * JATS/CSL publication type string (e.g. `"article-journal"`, `"book"`,
     * `"chapter"`, `"paper-conference"`). Set in each subclass constructor.
     *
     * @var string
     */
    protected string $publicationType = "";

    /**
     * Title of the referenced work.
     * Normalised through {@see Utilities::renderBibtexTitle()} on assignment.
     *
     * @var string
     */
    protected string $title = "";

    /** @var string Year of publication. */
    protected string $year = "";

    /** @var string URI or URL of the referenced work. */
    protected string $uri = "";

    /**
     * Abstract text of the referenced work.
     * Normalised through {@see Utilities::renderBibtexTitle()} on assignment.
     *
     * @var string
     */
    protected string $abstract = "";

    /**
     * Publisher name.
     * Normalised through {@see Utilities::renderBibtexTitle()} on assignment.
     *
     * @var string
     */
    protected string $publisher = "";

    /**
     * Publisher address or location.
     * Normalised through {@see Utilities::renderBibtexTitle()} on assignment.
     *
     * @var string
     */
    protected string $address = "";

    /** @var Author[]  Authors of the referenced work. */
    protected array $authors = [];

    /** @var Author[]  Editors of the referenced work (books, collections). */
    protected array $editors = [];

    /**
     * Formatted string representation of the author list.
     * Rebuilt automatically whenever authors are set via {@see setAuthors()}.
     *
     * @var string
     */
    protected string $authorList = "";

    /**
     * Formatted string representation of the editor list.
     * Rebuilt automatically whenever editors are set via {@see setEditors()}.
     *
     * @var string
     */
    protected string $editorList = "";

    /**
     * Series or collection title.
     * Normalised through {@see Utilities::renderBibtexTitle()} on assignment.
     *
     * @var string
     */
    protected string $series = "";

    /**
     * Keywords associated with this reference.
     * Normalised through {@see Utilities::renderBibtexTitle()} on assignment.
     *
     * @var string
     */
    protected string $keywords = "";

    /**
     * Language of the referenced work.
     * Normalised through {@see Utilities::renderBibtexTitle()} on assignment.
     *
     * @var string
     */
    protected string $language = "";

    /**
     * Miscellaneous notes field.
     * Normalised through {@see Utilities::renderBibtexTitle()} on assignment.
     * Also used to store HTML link annotations (e.g. Google Books info link).
     *
     * @var string
     */
    protected string $note = "";

    /** @var string Source identifier or import origin string. */
    protected string $source = "";

    /** @var string ISSN of the journal or series. */
    protected string $issn = "";

    /** @var string ISBN of the book or volume. */
    protected string $isbn = "";

    /**
     * Cached BibTeX field array used during export via {@see getBibtexReference()}.
     * Populated by iterating `$mappings` and calling the corresponding getters.
     *
     * @var array<string, string>
     */
    private array $bibtex = [];

    /**
     * BibTeX entry type string (e.g. `"article"`, `"book"`, `"incollection"`).
     * Set in each subclass constructor.
     *
     * @var string
     */
    private string $bibtexType = "";

    /**
     * Stores candidate references returned by external API checks (CrossRef,
     * Google Books, Primo/Alma). Keyed by adapter name (e.g. `"crossref"`,
     * `"google"`, `"alma"`), each value is a {@see ReferenceCollection}.
     *
     * @var array<string, ReferenceCollection>
     */
    private array $refCheck = [];

    /**
     * Title similarity score against the original query reference, set by API
     * adapters after resolution. Range 0.0–100.0; 0.0 means not yet scored.
     *
     * @var float
     */
    private float $matchPercent = 0.0;

    // ---------------------------------------------------------------
    // External API addresses (used by adapter classes)
    // ---------------------------------------------------------------

    /** @var string Base URL for CrossRef DOI content-negotiation requests. */
    public static string $CROSSREF_API_ADDRESS = "https://doi.org/";

    /** @var string NCBI ID converter API endpoint (PMID → DOI and vice versa). */
    public static string $PMID_API_ADDRESS = "https://www.ncbi.nlm.nih.gov/pmc/utils/idconv/v1.0/";

    /** @var string NCBI E-utilities efetch endpoint for PubMed XML retrieval. */
    public static string $ENTREZ_API_ADDRESS = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi";

    /**
     * Minimum similarity percentage required before a candidate reference
     * from an API check may be applied to the object model via the GUI.
     *
     * @var int
     */
    public static int $PERCENT_CUTOFF = 75;

    // ---------------------------------------------------------------
    // BibTeX field mappings (object model getter → BibTeX field name)
    // ---------------------------------------------------------------

    /**
     * Maps object model getter suffixes to their corresponding BibTeX field names.
     *
     * Used by {@see updateFromBibtex()} for import and {@see getBibtexReference()}
     * for export. The key is the getter suffix (e.g. `"Title"` → `getTitle()`);
     * the value is the BibTeX field name.
     *
     * Subclasses may override individual entries to remap fields for their
     * specific publication type (e.g. {@see Chapter} remaps `"Title"` →
     * `"booktitle"` and adds `"ChapterTitle"` → `"title"`).
     *
     * @var array<string, string>
     */
    protected array $mappings = [
        "Label"       => "citation-key",
        "Title"       => "title",
        "AuthorList"  => "authors",
        "EditorList"  => "editors",
        "PubId"       => "doi",
        "Uri"         => "uri",
        "Journal"     => "journal",
        "Year"        => "year",
        "Volume"      => "volume",
        "Number"      => "number",
        "Edition"     => "edition",
        "Pages"       => "pages",
        "Publisher"   => "publisher",
        "Address"     => "address",
        "Abstract"    => "abstract",
        "Keywords"    => "keywords",
        "Language"    => "langid",
        "Note"        => "note",
        "Issn"        => "issn",
        "Isbn"        => "isbn",
    ];

    /**
     * Shared {@see XMLWriter} instance used when serialising multiple references
     * into a single XML document. When set, {@see getJATSReference()} appends
     * to this writer rather than creating a standalone document.
     *
     * @var XMLWriter|null
     */
    protected ?XMLWriter $xmlWriter = null;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a Reference with a set of fields excluded from GUI
     * field-selection interfaces.
     *
     * The `disallowedFields` list prevents internal or structural properties
     * from appearing as selectable options in reference matching UIs.
     */
    public function __construct() {
        $this->disallowedFields = ["month", "copyright", "bibtex", "xmlWriter", "crossRefCheck", "label", "matchPercent", "refCheck"];
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set a shared XMLWriter for multi-reference document building.
     *
     * When set, subclass {@see getJATSReference()} implementations will append
     * to this writer rather than flushing a standalone document.
     *
     * @param  XMLWriter $writer  The shared writer instance.
     * @return void
     */
    public function setXMLWriter(XMLWriter $writer): void {
        $this->xmlWriter = $writer;
    }

    /**
     * Set the citation label / BibTeX citation key.
     *
     * Quotes and apostrophes are replaced with underscores to ensure the label
     * is safe for use as a BibTeX key and collection index. If the resulting
     * string is empty, a `uniqid()` fallback is used.
     *
     * @param  string $s  Raw label string.
     * @return void
     */
    public function setLabel(string $s): void {
        $clean = preg_replace('/[^\p{L}\p{N}._:]/u', '_', trim($s));
        $clean = preg_replace('/_+/', '_', $clean);
        $this->label = trim($clean, '_');
        if ($this->label === "") $this->label = uniqid();
    }

    /**
     * Get the citation label.
     *
     * @return string
     */
    public function getLabel(): string {
        return $this->label;
    }

    /**
     * Check whether a public identifier (DOI, etc.) is set.
     *
     *
     * @return bool  `true` if a pub ID is set, `false` if it is empty.
     */
    public function issetDOI(): bool {
        if (empty($this->pubId)) return false;
        return true;
    }

    /**
     * Check whether a given field name is allowed to be edited via the GUI.
     *
     * Compares the lowercased field name against {@see $disallowedFields}.
     *
     * @param  string $field  Field name to check.
     * @return bool  `true` if the field may be edited, `false` if it is disallowed.
     */
    public function fieldCanBeEdited(string $field): bool {
        if (in_array(strtolower($field), $this->disallowedFields)) return false;
        return true;
    }

    /**
     * Set the public identifier (e.g. DOI, handle).
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $s  Public identifier string.
     * @return void
     */
    public function setPubId(string $s): void {
        $this->pubId = trim($s);
    }

    /**
     * Get the public identifier.
     *
     * @return string
     */
    public function getPubId(): string {
        return $this->pubId;
    }

    /**
     * Set the public identifier type (e.g. `"doi"`, `"pmid"`, `"other"`).
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $s  Identifier type string.
     * @return void
     */
    public function setPubIdType(string $s): void {
        $this->pubIdType = trim($s);
    }

    /**
     * Get the public identifier type.
     *
     * @return string
     */
    public function getPubIdType(): string {
        return $this->pubIdType;
    }

    /**
     * Set the publication type string (e.g. `"article-journal"`, `"book"`).
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $s  Publication type.
     * @return void
     */
    public function setPublicationType(string $s): void {
        $this->publicationType = trim($s);
    }

    /**
     * Get the publication type string.
     *
     * @return string
     */
    public function getPublicationType(): string {
        return $this->publicationType;
    }

    /**
     * Set the title of the referenced work.
     * Normalised through {@see Utilities::renderBibtexTitle()} before storage.
     *
     * @param  string $s  Title string.
     * @return void
     */
    public function setTitle(string $s): void {
        $this->title = Utilities::renderBibtexTitle($s);
    }

    /**
     * Get the title of the referenced work.
     *
     * @return string
     */
    public function getTitle(): string {
        return $this->title;
    }

    /**
     * Set the series or collection title.
     * Normalised through {@see Utilities::renderBibtexTitle()} before storage.
     *
     * @param  string $s  Series title string.
     * @return void
     */
    public function setSeries(string $s): void {
        $this->series = Utilities::renderBibtexTitle($s);
    }

    /**
     * Get the series or collection title.
     *
     * @return string
     */
    public function getSeries(): string {
        return $this->series;
    }

    /**
     * Set the publication year.
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $s  Year string (e.g. `"2023"`).
     * @return void
     */
    public function setYear(string $s): void {
        $this->year = trim($s);
    }

    /**
     * Get the publication year.
     *
     * @return string
     */
    public function getYear(): string {
        return $this->year;
    }

    /**
     * Set the URI or URL for this reference.
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $s  URI string.
     * @return void
     */
    public function setURI(string $s): void {
        $this->uri = trim($s);
    }

    /**
     * Get the URI or URL for this reference.
     *
     * @return string
     */
    public function getURI(): string {
        return $this->uri;
    }

    /**
     * Set the author list from an array of {@see Author} objects.
     *
     * Delegates to {@see setAuthors()} and triggers a rebuild of the cached
     * {@see $authorList} string.
     *
     * @param  Author[] $a  Array of author objects.
     * @return void
     */
    public function setAuthorList(array $a): void {
        $this->setAuthors($a);
        $this->getAuthorList(true);
    }

    /**
     * Set the authors array and rebuild the cached author list string.
     *
     * @param  Author[] $a  Array of {@see Author} objects.
     * @return void
     */
    public function setAuthors(array $a): void {
        $this->authors = $a;
        $this->getAuthorList(true);
    }

    /**
     * Get the authors array.
     *
     * @return Author[]
     */
    public function getAuthors(): array {
        return $this->authors;
    }

    /**
     * Build and return a formatted string list of authors.
     *
     * Rebuilds {@see $authorList} from the current `$authors` array on every
     * call. Format is controlled by the `$commas` and `$and` flags:
     * - `$commas = true`  → names separated by commas (e.g. `"Smith, Jones"`)
     * - `$and = true`     → names separated by `" and "` (BibTeX style)
     *
     * @param  bool $commas  Separate names with commas. Default `true`.
     * @param  bool $and     Separate names with `" and "`. Default `false`.
     * @return string        Formatted author list string.
     */
    public function getAuthorList(bool $commas = true, bool $and = false): string {
        $this->authorList = self::getStringListFromAuthors($this->authors, $commas, $and);
        return $this->authorList;
    }

    /**
     * Return the last name of the first author, or false if no authors are set.
     *
     * @return string|false  First author's last name, or `false`.
     */
    private function getFirstAuthor(): string|false {
        foreach ($this->getAuthors() as $a) return $a->getLastName();
        return false;
    }

    /**
     * Get the editors array.
     *
     * @return Author[]
     */
    public function getEditors(): array {
        return $this->editors;
    }

    /**
     * Set the editors array and rebuild the cached editor list string.
     *
     * @param  Author[] $eds  Array of {@see Author} objects acting as editors.
     * @return void
     */
    public function setEditors(array $eds): void {
        $this->editors = $eds;
        $this->getEditorList(true);
    }

    /**
     * Build and return a formatted string list of editors.
     *
     * Uses the same formatting logic as {@see getAuthorList()}.
     *
     * @param  bool $commas  Separate names with commas. Default `true`.
     * @param  bool $and     Separate names with `" and "`. Default `false`.
     * @return string        Formatted editor list string.
     */
    public function getEditorList(bool $commas = true, bool $and = false): string {
        $this->editorList = self::getStringListFromAuthors($this->editors, $commas, $and);
        return $this->editorList;
    }


    /**
     * Set the editor list from an array of {@see Author} objects.
     *
     * Delegates to {@see setEditors()} and triggers a rebuild of the cached
     * {@see $editorList} string.
     *
     * @param  Author[] $a  Array of author objects.
     * @return void
     */
    public function setEditorList(array $a): void {
        $this->setEditors($a);
        $this->getEditorList(true);
    }

    /**
     * Get the BibTeX field mappings array.
     *
     * @return array<string, string>
     */
    public function getMappings(): array {
        return $this->mappings;
    }

    /**
     * Set the raw BibTeX field array directly.
     *
     * @param  array<string, string> $s  BibTeX field name → value pairs.
     * @return void
     */
    public function setBibtex(array $s): void {
        $this->bibtex = $s;
    }

    /**
     * Get the raw BibTeX field array.
     *
     * @return array<string, string>
     */
    public function getBibtex(): array {
        return $this->bibtex;
    }

    /**
     * Set the BibTeX entry type (e.g. `"article"`, `"book"`, `"incollection"`).
     *
     * @param  string $s  BibTeX entry type.
     * @return void
     */
    public function setBibtexType(string $s): void {
        $this->bibtexType = $s;
    }

    /**
     * Get the BibTeX entry type.
     *
     * @return string
     */
    public function getBibtexType(): string {
        return $this->bibtexType;
    }

    /**
     * Build and return a comma-separated display string from an array of
     * {@see Person} or {@see Author} objects using their full names.
     *
     * Unlike {@see getStringListFromAuthors()}, this method uses
     * {@see Person::getFullName()} (`"First Last"`) rather than
     * {@see Author::getCompleteName()} (which includes particles and suffixes).
     *
     * @param  array  $list    Array of objects with a `getFullName()` method.
     * @param  bool   $commas  Append a comma after each name. Default `true`.
     * @return string          Comma-separated name string.
     */
    public function getPersonList(array $list, bool $commas = true): string {
        $authors = "";
        foreach ($list as $a) {
            $authors .= $a->getFullName();
            if ($commas) $authors .= ", ";
        }
        if ($commas) $authors = substr($authors, 0, strlen($authors) - 2);
        return $authors;
    }


    /**
     * Set the publisher name.
     * Trimmed and normalised through {@see Utilities::renderBibtexTitle()}.
     *
     * @param  string $s  Publisher name.
     * @return void
     */
    public function setPublisher(string $s): void {
        $this->publisher = Utilities::renderBibtexTitle(trim($s));
    }

    /**
     * Get the publisher name.
     *
     * @return string
     */
    public function getPublisher(): string {
        return $this->publisher;
    }

    /**
     * Set the publisher address or location.
     * Trimmed and normalised through {@see Utilities::renderBibtexTitle()}.
     *
     * @param  string $s  Address string.
     * @return void
     */
    public function setAddress(string $s): void {
        $this->address = Utilities::renderBibtexTitle(trim($s));
    }

    /**
     * Get the publisher address.
     *
     * @return string
     */
    public function getAddress(): string {
        return $this->address;
    }

    /**
     * Set the abstract text.
     * Trimmed and normalised through {@see Utilities::renderBibtexTitle()}.
     *
     * @param  string $s  Abstract text.
     * @return void
     */
    public function setAbstract(string $s): void {
        $this->abstract = Utilities::renderBibtexTitle(trim($s));
    }

    /**
     * Get the abstract text.
     *
     * @return string
     */
    public function getAbstract(): string {
        return $this->abstract;
    }

    /**
     * Set the keywords string.
     * Trimmed and normalised through {@see Utilities::renderBibtexTitle()}.
     *
     * @param  string $s  Keywords string.
     * @return void
     */
    public function setKeywords(string $s): void {
        $this->keywords = Utilities::renderBibtexTitle(trim($s));
    }

    /**
     * Get the keywords string.
     *
     * @return string
     */
    public function getKeywords(): string {
        return $this->keywords;
    }

    /**
     * Set the language of the referenced work.
     * Trimmed and normalised through {@see Utilities::renderBibtexTitle()}.
     *
     * @param  string $s  Language string (e.g. `"en"`, `"german"`).
     * @return void
     */
    public function setLanguage(string $s): void {
        $this->language = Utilities::renderBibtexTitle(trim($s));
    }

    /**
     * Get the language string.
     *
     * @return string
     */
    public function getLanguage(): string {
        return $this->language;
    }

    /**
     * Set the notes field.
     * Trimmed and normalised through {@see Utilities::renderBibtexTitle()}.
     *
     * @param  string $s  Notes string.
     * @return void
     */
    public function setNote(string $s): void {
        $this->note = Utilities::renderBibtexTitle(trim($s));
    }

    /**
     * Get the notes field.
     *
     * @return string
     */
    public function getNote(): string {
        return $this->note;
    }

    /**
     * Get the source identifier or import origin string.
     *
     * @return string
     */
    public function getSource(): string {
        return $this->source;
    }

    /**
     * Set the source identifier or import origin string.
     *
     * @param  string $s  Source string.
     * @return void
     */
    public function setSource(string $s): void {
        $this->source = $s;
    }

    /**
     * Set the ISSN.
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $s  ISSN string.
     * @return void
     */
    public function setIssn(string $s): void {
        $this->issn = trim($s);
    }

    /**
     * Get the ISSN.
     *
     * @return string
     */
    public function getIssn(): string {
        return $this->issn;
    }

    /**
     * Set the ISBN.
     * Leading and trailing whitespace is trimmed.
     *
     * @param  string $s  ISBN string.
     * @return void
     */
    public function setIsbn(string $s): void {
        $this->isbn = trim($s);
    }

    /**
     * Get the ISBN.
     *
     * @return string
     */
    public function getIsbn(): string {
        return $this->isbn;
    }


    /**
	 * get a string list from an array of Author objects
	 * 
	 * @param array contains Authors
	 * @param bool separate by commas default = true
	 * @param bool separate by and default = false
	 * 
	 * 
	 * @return string the author string
	 */
	private static function getStringListFromAuthors(array $authorArr,bool $commas=true,bool $and=false):string{
		$authors="";
		if(is_array($authorArr)){
			foreach($authorArr as $a){
				$authors.=$a->getCompleteName();
				if($commas)$authors.=",";
				else if($and)$authors.=" and"; 
				$authors.=" ";
			}
			$authors=trim($authors);
			if($commas)$authors=substr($authors,0,strlen($authors)-1);
			else if($and)$authors=substr($authors,0,strlen($authors)-4);
		}
		return $authors;
	}



    /****************************************************************/
    /* REFERENCE CHECK METHODS                                      */
    /****************************************************************/

    /**
     * Retrieve the reference check results, optionally filtered by adapter key.
     *
     * If `$key` is null or empty, the entire `$refCheck` array is returned.
     * If `$key` is provided (e.g. `"crossref"`, `"google"`, `"alma"`), the
     * corresponding {@see ReferenceCollection} is returned, or `null` if not set.
     *
     * @param  mixed $key  Optional adapter key to retrieve a specific result.
     * @return mixed       The full array, a specific collection, or `null`.
     */
    public function getRefCheck(mixed $key = null): array|ReferenceCollection|null {
        if (empty($key)) return $this->refCheck;
        if (isset($this->refCheck[$key])) return $this->refCheck[$key];
        return null;
    }

    /**
     * Store one or more reference check results from API adapters.
     *
     * Merges the supplied array into `$refCheck`. Each entry should be keyed
     * by the adapter name (e.g. `["crossref" => $collection]`). Existing keys
     * are overwritten.
     *
     * @param  array<string, ReferenceCollection> $s  Adapter key → collection pairs.
     * @return void
     */
    public function setRefCheck(array $s): void {
        foreach ($s as $key => $val) {
            $this->refCheck[$key] = $val;
        }
    }

    /**
     * Set the title match percentage for this candidate reference.
     *
     * @param  float $percent  Similarity score 0.0–100.0 from {@see ReferenceAPIAdapter::scoreTitleMatch()}.
     * @return void
     */
    public function setMatchPercent(float $percent): void {
        $this->matchPercent = $percent;
    }

    /**
     * Get the title match percentage for this candidate reference.
     *
     * @return float  Similarity score 0.0–100.0, or 0.0 if not yet scored.
     */
    public function getMatchPercent(): float {
        return $this->matchPercent;
    }


    /****************************************************************/
    /* ABSTRACT METHODS                                             */
    /****************************************************************/

    /**
     * Populate this reference from a JATS XML `<ref>` citation fragment.
     * Must be implemented by all concrete subclasses.
     *
     * @param  SimpleXMLElement $xml  The JATS citation XML fragment.
     * @return void
     */
    abstract public function createFromJatsXMLFragment(SimpleXMLElement $xml): void;

    /**
     * Extract text from a SimpleXMLElement that may contain inline markup
     * (e.g. <italic>, <bold>) as its only content.
     *
     * SimpleXML's (string) cast returns only direct text nodes, so an element
     * like <source><italic>Title</italic></source> yields "". This method falls
     * back to stripping all tags from the element's raw XML when that happens.
     */
    protected static function innerText(SimpleXMLElement $el): string {
        $text = (string) $el;
        return !empty($text) ? $text : trim(strip_tags($el->asXML()));
    }

    /**
     * Return the CrossRef API type filter string for this reference type.
     * Must be implemented by all concrete subclasses.
     *
     * @return string  Comma-separated CrossRef type filter (e.g. `"type:journal-article"`).
     */
    abstract public function getFilterType(): string;

    /**
     * Serialise this reference to a JATS `<element-citation>` XML block.
     * Must be implemented by all concrete subclasses.
     *
     * @return string|true  Serialised XML string, or `true` when appending to
     *                      a shared {@see XMLWriter}.
     */
    abstract public function getJATSReference(): string|true;


    /****************************************************************/
    /* PAGE HANDLING METHODS                                        */
    /****************************************************************/

    /**
     * Extract and set first/last page values from a BibTeX field array.
     *
     * Attempts to split the `pages` field on four common separators in order
     * of preference: en-dash (`–`), hyphen (`-`), double hyphen (`--`),
     * and double en-dash (`––`). The first separator that produces exactly
     * two parts is used.
     *
     * Only applies to reference types that have page fields:
     * {@see Chapter}, {@see JournalArticle}, and {@see ConferencePaper}.
     *
     * @param  array $vals  BibTeX field array, must contain a `pages` key.
     * @return void
     */
    public function updatePages(array $vals): void {
        if (!empty($vals['pages'])) {
            $pages = explode("–",  trim($vals['pages']));
            if (count($pages) != 2) $pages = explode("-",  trim($vals['pages']));
            if (count($pages) != 2) $pages = explode("--", trim($vals['pages']));
            if (count($pages) != 2) $pages = explode("––", trim($vals['pages']));

            if (count($pages) == 2) {
                if (is_a($this, "Biblhertz\Article\om\Chapter")        ||
                    is_a($this, "Biblhertz\Article\om\JournalArticle") ||
                    is_a($this, "Biblhertz\Article\om\ConferencePaper")) {
                    $this->setFirstPage($pages[0]);
                    $this->setLastPage($pages[1]);
                }
            }
        }
    }

    /**
     * Return the page range as a formatted string, or null if not applicable.
     *
     * Returns `"{first} - {last}"` for {@see Chapter}, {@see JournalArticle},
     * and {@see ConferencePaper} when both page values are set.
     * Returns `null` for all other reference types or when pages are absent.
     *
     * @return string|null  Formatted page range (e.g. `"12 - 34"`), or `null`.
     */
    public function getPages(): string|null {
        if (is_a($this, "Biblhertz\Article\om\Chapter")        ||
            is_a($this, "Biblhertz\Article\om\JournalArticle") ||
            is_a($this, "Biblhertz\Article\om\ConferencePaper")) {
            $first = $this->getFirstPage();
            $last  = $this->getLastPage();
            if (!empty($first) && !empty($last)) return $first . " - " . $last;
        }
        return null;
    }


    /****************************************************************/
    /* BIBTEX IMPORT                                                */
    /****************************************************************/

    /**
     * Populate this reference's fields from a parsed BibTeX field array.
     *
     * Handles the following fields common to all reference types:
     * - `doi`          → pub ID and type (`"doi"`)
     * - `year`         → publication year
     * - `date`         → publication year (extracted from full date via `strtotime`)
     * - `label`        → citation label
     * - `citation-key` → citation label (title-cased; takes priority over `label`)
     * - `title`        → title
     * - `url` / `URL`  → URI
     * - `author`       → authors (parsed via {@see Author::parseBibtexAuthors()})
     * - `publisher`    → publisher (falls back to `school` for theses)
     * - `address` / `publisher-loc` → publisher address
     * - `abstract`     → abstract
     * - `series`       → series title
     * - `keywords`     → keywords
     * - `language` / `langid` → language (`language` takes priority)
     * - `source`       → source string
     * - `note`         → notes field
     * - `issn`         → ISSN
     *
     * Subclasses call `parent::updateFromBibtex()` and then handle their own
     * type-specific fields (e.g. `volume`, `journal`, `booktitle`).
     *
     * @param  array $vals  Associative array of BibTeX field names → values.
     * @return void
     */
    public function updateFromBibtex(array $vals): void {
        if (!empty($vals['doi'])) {
            $this->setPubIdType("doi");
            $this->setPubId($vals['doi']);
        }

        if (!empty($vals['year']))  $this->setYear($vals['year']);

        $this->setLabel($vals['label'] ?? '');

        // Full date field (BibLaTeX): extract year component
        if (!empty($vals['date'])) {
            $this->setYear(date('Y', strtotime($vals['date'])));
        }

        if (!empty($vals['title']))        $this->setTitle(trim($vals['title']));

        // citation-key takes priority as the canonical label
        if (!empty($vals['citation-key']))
            $this->setLabel(ucwords(strtolower($vals['citation-key'])));

        if (!empty($vals['url']))          $this->setURI(trim($vals['url']));

        if (!empty($vals['author'])) {
            $auths = Author::parseBibtexAuthors($vals['author']);
            $this->setAuthors($auths);
            $this->getAuthorList(false, true);
        }

        // publisher fallback: use 'school' for thesis-type entries
        if (!empty($vals['publisher']))
            $this->setPublisher(trim($vals['publisher']));
        elseif (!empty($vals['school']))
            $this->setPublisher(trim($vals['school']));

        if (!empty($vals['address']))          $this->setAddress(trim($vals['address']));
        if (!empty($vals['publisher-loc']))    $this->setAddress(trim($vals['publisher-loc']));
        if (!empty($vals['abstract']))         $this->setAbstract(trim($vals['abstract']));
        if (!empty($vals['series']))           $this->setSeries(trim($vals['series']));
        if (!empty($vals['keywords']))         $this->setKeywords(trim($vals['keywords']));

        // language field: BibTeX uses 'language', BibLaTeX uses 'langid'
        if (!empty($vals['language']))
            $this->setLanguage(trim($vals['language']));
        elseif (!empty($vals['langid']))
            $this->setLanguage(trim($vals['langid']));

        if (!empty($vals['URL']))    $this->setURI(trim($vals['URL']));
        if (!empty($vals['source'])) $this->setSource(trim($vals['source']));
        if (!empty($vals['note']))   $this->setNote(trim($vals['note']));
        if (!empty($vals['issn']))   $this->setIssn(trim($vals['issn']));
    }


    /****************************************************************/
    /* BIBTEX EXPORT                                                */
    /****************************************************************/

    /**
     * BibTeX fields whose values require double braces to preserve casing
     * (e.g. `title = {{The Title}}`). Applies to `title`, `booktitle`,
     * and `series`.
     *
     * @var string[]
     */
    private array $double = ["title", "booktitle", "series"];

    /**
     * Serialise this reference to a BibTeX entry string.
     *
     * Iterates {@see $mappings}, calls the corresponding getter for each
     * mapped field, and builds the BibTeX field list. Special handling:
     * - `AuthorList` → serialised with `" and "` separator (BibTeX convention)
     * - `Pages`      → hyphens normalised to `--` (BibTeX range convention)
     * - `Language`   → skipped if `langid` is already present in `$bibtex`
     * - Fields in {@see $double} are wrapped in double braces (`{{...}}`)
     * - Fields starting with `_`, the `type` field, and `citation-key` are
     *   omitted from the output
     * - Duplicate `authors`/`author` and `editors`/`editor` keys are resolved
     *   by removing the less-specific key
     *
     * @return string  Complete BibTeX entry string (e.g. `"@article{Smith2023, ...}"`).
     */
    public function getBibtexReference(): string {
        foreach ($this->mappings as $key => $value) {
            $func = "get$key";
            if ($func === "getAuthorList") {
                $this->bibtex['author'] = trim($this->getAuthorList(false, true));
            } elseif ($func === "getPages") {
                $pages = $this->getPages();
                if (!empty($pages)) $this->bibtex['pages'] = str_replace(" - ", "--", $pages);
            } elseif ($func === "getLanguage") {
                if (!empty($this->bibtex['langid'])) continue;
            } elseif (method_exists($this, $func)) {
                $objVal = $this->{$func}();
                if ($value !== "") $this->bibtex[$value] = $objVal;
            }
        }

        // Resolve duplicate author/editor keys
        if (isset($this->bibtex['authors']) && isset($this->bibtex['author']))
            unset($this->bibtex['authors']);
        if (isset($this->bibtex['editors']) && isset($this->bibtex['editor']))
            unset($this->bibtex['editors']);
        if (!empty($this->bibtex['url']) && !empty($this->bibtex['uri']))
            unset($this->bibtex['url']);

        $str = "@" . $this->bibtexType . "{" . $this->getLabel();

        foreach ($this->bibtex as $key => $value) {
            $first = substr($key, 0, 1);
            if (!empty($value)
                && $first !== "_"
                && $key !== "type"
                && $key !== "citation-key") {
                $str .= ",\n";
                if (in_array($key, $this->double))
                    $str .= "$key = {{" . $value . "}}";
                else
                    $str .= "$key = {" . $value . "}";
            }
        }

        $str .= "\n}";
        return $str;
    }


    /****************************************************************/
    /* JATS EXPORT HELPERS                                          */
    /****************************************************************/

    /**
     * Write a JATS `<pub-id>` element to the supplied XMLWriter.
     *
     * Writes the pub ID only when both `$pubId` is non-empty and `$pubIdType`
     * is not `"unset"`. The `pub-id-type` attribute is set to the value of
     * {@see $pubIdType} (e.g. `"doi"`).
     *
     * Called by subclass {@see getJATSReference()} implementations.
     *
     * @param  XMLWriter $xmlWriter  The writer to append the element to.
     * @return void
     */
    protected function getJatsDOI(XMLWriter $xmlWriter): void {
        $type = $this->getPubIdType();
        $doi  = $this->getPubId();
        if (empty($doi) || $type === "unset") return;
        $xmlWriter->startElement("pub-id");
        $xmlWriter->writeAttribute("pub-id-type", $this->getPubIdType());
        $xmlWriter->writeRaw($doi);
        $xmlWriter->endElement();
    }


    /****************************************************************/
    /* CSL / JSON EXPORT                                            */
    /****************************************************************/

    /**
     * Serialise this reference to a CSL-JSON string for use with citation
     * style rendering (e.g. via citeproc-js or the CSL PHP library).
     *
     * Produces a JSON array containing a single CSL item. Common fields
     * mapped for all types:
     * - `id`             ← label
     * - `title`          ← title
     * - `issued`         ← year (as `date-parts` array, only if numeric)
     * - `author`         ← authors (as `[{given, family}]` array; `von` particle
     *                      appended to `given` if present)
     * - `type`           ← publication type
     * - `publisher`      ← publisher (if set)
     * - `publisher-place`← address (if set)
     * - `DOI`            ← pub ID (only when type is `"doi"`)
     * - `collection-title`← series (if set)
     * - `URL`            ← URI (if set)
     * - `page`           ← page range (if applicable)
     *
     * Type-specific fields:
     * - **JournalArticle** — `issue`, `volume`, `container-title` (journal)
     * - **Chapter**        — `title` (chapter title), `container-title` (book title),
     *                        `page`, `editor` list
     * - **ConferencePaper**— `container-title` (conference name)
     * - **Book**           — `edition`, `volume`, `container-title` (book title),
     *                        `collection-number`, `editor` list
     *
     * Authors or editors with empty `family` or `given` names are excluded
     * from the output arrays.
     *
     * @return string  JSON-encoded CSL item array.
     */
    public function getAsJson(): string {
        $authors = $this->getAuthors();
        $authArr = $info = [];

        foreach ($authors as $a) {
            $vals = [];
            $von  = $a->getVon();
            // Append von particle to given name if present (CSL has no separate field)
            $vals['given']  = empty($von) ? $a->getFirstName() : $a->getFirstName() . " $von";
            $vals['family'] = $a->getLastName();
            if ($vals['family'] !== "" && $vals['given'] !== "")
                array_push($authArr, $vals);
        }

        $info['id']    = $this->getLabel();
        $info['title'] = $this->getTitle();
        if (is_numeric($this->getYear()))
            $info['issued'] = ["date-parts" => [[$this->getYear()]]];
        $info['author'] = $authArr;
        $info['type']   = $this->publicationType;

        $publisher = $this->getPublisher();
        if (!empty($publisher)) $info['publisher'] = $publisher;

        $address = $this->getAddress();
        if (!empty($address)) $info['publisher-place'] = $address;

        $doi = $this->getPubId();
        if (!empty($doi) && $this->getPubIdType() === "doi") $info['DOI'] = $doi;

        $series = $this->getSeries();
        if (!empty($series)) $info['collection-title'] = $series;

        $url = $this->getURI();
        if (!empty($url)) $info['URL'] = $url;

        if (method_exists($this, "getFirstPage") && method_exists($this, "getLastPage")) {
            $pages = $this->getPages();
            if (!empty($pages)) $info['page'] = $pages;
        }

        if ($this instanceof \Biblhertz\Article\om\JournalArticle) {
            $issue = $this->getNumber();
            if (!empty($issue))   $info['issue'] = $issue;
            $volume = $this->getVolume();
            if (!empty($volume))  $info['volume'] = $volume;
            $journal = $this->getJournal();
            if (!empty($journal)) $info['container-title'] = $journal;

        } elseif ($this instanceof \Biblhertz\Article\om\Chapter) {
            // For chapters, CSL 'title' = chapter title; 'container-title' = book title
            $chapterTitle = $this->getChapterTitle();
            if (!empty($chapterTitle)) {
                $info['title'] = $chapterTitle;
            }
            $bookTitle = $this->getTitle();
            if (!empty($bookTitle)) {
                $info['container-title'] = $bookTitle;
            }
            $pages = $this->getPages();
            if (!empty($pages)) $info['page'] = $pages;

            $eds = [];
            foreach ($this->getEditors() as $a) {
                $ed = ['family' => $a->getLastName(), 'given' => $a->getFirstName()];
                if ($ed['family'] !== "" && $ed['given'] !== "")
                    array_push($eds, $ed);
            }
            $info['editor'] = $eds;

        } elseif ($this instanceof \Biblhertz\Article\om\ConferencePaper) {
            $conference = $this->getConference();
            if (!empty($conference)) $info['container-title'] = $conference;

        } elseif ($this instanceof \Biblhertz\Article\om\Book) {
            $edition = $this->getEdition();
            if (!empty($edition))  $info['edition'] = $edition;
            $volume = $this->getVolume();
            if (!empty($volume))   $info['volume'] = $volume;
            $title = $this->getTitle();
            if (!empty($title))    $info['container-title'] = $title;
            $number = $this->getNumber();
            if (!empty($number))   $info['collection-number'] = $number;

            $eds = [];
            foreach ($this->getEditors() as $a) {
                $ed = ['family' => $a->getLastName(), 'given' => $a->getFirstName()];
                if ($ed['family'] !== "" && $ed['given'] !== "")
                    array_push($eds, $ed);
            }
            $info['editor'] = $eds;
        }

        $info = [$info];
        return json_encode($info);
    }
}
?>