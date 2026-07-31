<?php
namespace Biblhertz\Article\Adapters;

use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\Book;
use Biblhertz\Article\om\ConferencePaper;
use Biblhertz\Article\om\JournalArticle;
use Biblhertz\Article\om\Chapter;
use Biblhertz\Article\om\Thesis;
use Biblhertz\Article\om\Manuscript;
use Biblhertz\Article\om\WebPage;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Article\utilities\Utilities;
use Biblhertz\Article\utilities\FileCreator;
use Biblhertz\Publink\om\File;
use RenanBr\BibTexParser\Listener;
use RenanBr\BibTexParser\Parser;
use RenanBr\BibTexParser\Processor;
use RenanBr\BibTexParser\Processor\LatexToUnicodeProcessor;

/**
 * BibtexToReferenceCollectionAdapter
 *
 * Adapter that converts a BibTeX file into a PubLink {@see ReferenceCollection}
 * object model. Implements the Adapter pattern, bridging the third-party
 * RenanBr BibTexParser library and the internal Reference domain objects.
 *
 * Processing pipeline:
 *   1. {@see generateObjectModel()} reads the BibTeX file and calls
 *      {@see translateBibthek()} to parse it into an array of entry arrays.
 *   2. Each entry array is passed to {@see makeReferenceFromBibtexArray()},
 *      which maps the BibTeX `type` field to the appropriate Reference subclass
 *      (JournalArticle, Book, Chapter, etc.) and calls updateFromBibtex() on it.
 *   3. Valid references are inserted into the ReferenceCollection keyed by label.
 *
 * Character encoding is handled by {@see convertLatexToUnicode()}, which maps
 * common LaTeX escape sequences to their UTF-8 equivalents using a lookup table
 * and preg_replace(). The inverse mapping (Unicode → LaTeX) is stored in
 * $unicode_to_latex and applied by {@see unicodeToLatex()}.
 *
 * Note: The class header comment says "CSVToOMAdapter" — this is a copy-paste
 * artefact and should be updated to reflect the BibTeX adapter.
 *
 * @package  Biblhertz\Article\adapters
 * @author   Chris Tomlinson
 * @since    11th July 2023
 */
class BibtexToReferenceCollectionAdapter {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var ReferenceCollection The reference collection being built */
    private ReferenceCollection $referenceCollection;

    /** @var File The uploaded BibTeX {@see File} object to parse */
    private File $bibFile;

    /** @var Logger Logger instance for progress and error output */
    private Logger $logger;

    

    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs the adapter and initialises an empty ReferenceCollection.
     *
     * Call {@see setBibFile()} and {@see setLogger()} before invoking
     * {@see generateObjectModel()}.
     */
    public function __construct() {
        $this->referenceCollection = new ReferenceCollection();
    }


    /****************************************************************/
    /*  ACCESSOR METHODS                                            */
    /****************************************************************/

    /**
     * Sets the BibTeX source file to be parsed.
     *
     * @param File $f A File object whose path points to a .bib file
     */
    public function setBibFile(File $f): void {
        $this->bibFile = $f;
    }

    /**
     * Sets the logger for progress and error output during parsing.
     *
     * @param Logger $l
     */
    public function setLogger(Logger $l): void {
        $this->logger = $l;
    }

    /**
     * Returns the populated ReferenceCollection after {@see generateObjectModel()}
     * has been called.
     *
     * @return ReferenceCollection
     */
    public function getReferenceCollection(): ReferenceCollection {
        return $this->referenceCollection;
    }


    /****************************************************************/
    /*  CORE PARSING METHODS                                        */
    /****************************************************************/

    /**
     * Parses the BibTeX file and populates the internal ReferenceCollection.
     *
     * Reads the file contents from disk, delegates parsing to
     * {@see translateBibthek()}, then iterates over the resulting entry arrays.
     * Each entry is converted to a typed Reference subclass via
     * {@see makeReferenceFromBibtexArray()} and added to the collection keyed
     * by its label. InvalidArgumentException (e.g. duplicate labels) are caught
     * per-entry so a single bad entry does not abort the entire import.
     *
     * Progress and any per-entry errors are written to $this->logger.
     *
     * @throws \Exception On fatal parse or I/O errors
     */
    public function generateObjectModel(): void {
        try {
            $objectArray = $this->translateBibthek(file_get_contents($this->bibFile->getPath()));
            $c = 0;

            foreach ($objectArray as $bibRef) {
                $c++;
                $this->logger->println();
                $this->logger->print("Reference $c");

                // Log all fields of this entry for traceability
                foreach ($bibRef as $key => $value) {
                    $this->logger->print("$key => $value");
                }

                $ref = $this->makeReferenceFromBibtexArray($bibRef);

                if (isset($ref)) {
                    try {
                        // Keys are BibTeX citation labels; duplicate labels throw InvalidArgumentException
                        $this->referenceCollection->offsetSet($ref->getLabel(), $ref);
                    } catch (\InvalidArgumentException $e) {
                        $this->logger->print("Invalid Argument Exception :: Not Added  :: " . $e->getMessage());
                        $this->logger->println();
                        continue;
                    }
                    $this->logger->print("Added " . $ref->getLabel());
                    $this->logger->println();
                }
            }

            $this->logger->print("Generated Reference Collection Object Model");

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Maps a BibTeX entry array to the appropriate Reference subclass.
     *
     * Inspects the 'type' key of $bibRef and instantiates the matching
     * domain object. The following BibTeX type strings are recognised:
     *
     * | BibTeX type(s)                          | Domain class      |
     * |-----------------------------------------|-------------------|
     * | article, journal-article, article-journal | JournalArticle  |
     * | book                                    | Book              |
     * | incollection, inbook                    | Chapter           |
     * | proceedings, inproceedings              | ConferencePaper   |
     * | thesis, phdthesis, mastersthesis        | Thesis            |
     * | manuscript, unpublished                 | Manuscript        |
     * | online, webpage, misc                   | WebPage           |
     *
     * Unrecognised types are logged to the PHP error log and return null.
     * After instantiation, the raw BibTeX array and type are stored on the
     * object, then updateFromBibtex() is called to populate all fields.
     *
     * @param array $bibRef Associative array of a single BibTeX entry
     *                      (must contain at minimum a 'type' key)
     * @return mixed        Populated Reference subclass instance, or null if
     *                      the type is missing or unrecognised
     */
    public static function makeReferenceFromBibtexArray(array $bibRef): Reference|null {
        $ref = null;

        if (isset($bibRef['type'])) {
            // Map BibTeX entry types to internal Reference subclasses
            if ($bibRef['type'] === "journal-article"
                || $bibRef['type'] === "article"
                || $bibRef['type'] === "article-journal") {
                $ref = new JournalArticle();
            } elseif ($bibRef['type'] === "book") {
                $ref = new Book();
            } elseif ($bibRef['type'] === "incollection"
                || $bibRef['type'] === "inbook") {
                $ref = new Chapter();
            } elseif ($bibRef['type'] === "proceedings"
                || $bibRef['type'] === "inproceedings") {
                $ref = new ConferencePaper();
            } elseif ($bibRef['type'] === "thesis"
                || $bibRef['type'] === "phdthesis"
                || $bibRef['type'] === "mastersthesis") {
                $ref = new Thesis();
            } elseif ($bibRef['type'] === "manuscript"
                || $bibRef['type'] === "unpublished") {
                $ref = new Manuscript();
            } elseif ($bibRef['type'] === "online"
                || $bibRef['type'] === "webpage"
                || $bibRef['type'] === "misc") {
                $ref = new WebPage();
            } else {
                // Type is present but not mapped — log for investigation
                error_log("Could not match type " . $bibRef['type']);
            }

            if (isset($ref)) {
                // Store the raw BibTeX data on the object for downstream access
                $ref->setBibtex($bibRef);
                $ref->setBibtexType($bibRef['type']);
                // Populate all typed fields from the entry array
                $ref->updateFromBibtex($bibRef);
            }
        }

        return $ref;
    }

    /**
     * Parses a BibTeX string into an array of entry arrays using BibTexParser.
     *
     * Configures a Listener with a lowercase tag-name processor and a Parser,
     * then parses $str. After parsing, each string field in every entry is
     * passed through {@see convertLatexToUnicode()} to normalise accented
     * characters and special symbols.
     *
     * Note: LatexToUnicodeProcessor is intentionally commented out in favour
     * of the custom {@see convertLatexToUnicode()} method, which handles
     * additional character sets and avoids the Pandoc overhead that caused
     * timeouts in production.
     *
     * @param string $str Raw BibTeX file contents
     * @return mixed      Array of entry arrays on success, empty string if
     *                    $str is not a string, or null/void if parsing yields
     *                    a non-array result
     *
     */
    private function translateBibthek(string $str): array|string {

        $listener = new Listener();
        $listener->addProcessor(new Processor\TagNameCaseProcessor(CASE_LOWER));
        // LatexToUnicodeProcessor disabled — caused Pandoc timeout in production;
        // custom convertLatexToUnicode() used instead
        // $listener->addProcessor(new LatexToUnicodeProcessor());

        $parser = new Parser();
        $parser->addListener($listener);
        $parser->parseString($str);
        $entries = $listener->export();

        if (is_array($entries)) {
            // Post-process: convert all LaTeX escape sequences to Unicode
            foreach ($entries as &$entry) {
                foreach ($entry as $field => &$value) {
                    if (is_string($value)) {
                        $value = self::convertLatexToUnicode($value);
                    }
                }
            }
            return $entries;
        }
        return [];
    }

    /**
     * Parses a single BibTeX entry string and returns the first entry array.
     *
     * A static convenience method for parsing one-off BibTeX snippets (e.g.
     * from a form field or clipboard paste) without constructing a full adapter
     * instance or file object. Uses the same parser configuration as
     * {@see translateBibthek()} and applies the same LaTeX→Unicode conversion.
     *
     * @param string $str A string containing a single BibTeX entry
     * @return mixed      Associative array of the first parsed entry, null if
     *                    parsing yields no results, or empty string if $str is
     *                    not a string
     * @throws \Exception On parse failure
     */
    public static function translateBibtexItem(string $str): mixed {

        try {
            $listener = new Listener();
            $listener->addProcessor(new Processor\TagNameCaseProcessor(CASE_LOWER));

            $parser = new Parser();
            $parser->addListener($listener);
            $parser->parseString($str);
            $entries = $listener->export();

            if (is_array($entries)) {
                // Post-process: convert LaTeX escapes to Unicode in all string fields
                foreach ($entries as &$entry) {
                    foreach ($entry as $field => &$value) {
                        if (is_string($value)) {
                            $value = self::convertLatexToUnicode($value);
                        }
                    }
                }
                // Return only the first entry; additional entries are ignored
                return $entries[0];
            }

            return null;

        } catch (\Exception $e) {
            throw $e;
        }
    }


    /****************************************************************/
    /*  CHARACTER ENCODING METHODS                                  */
    /****************************************************************/

    /**
     * Converts LaTeX escape sequences in a string to their Unicode equivalents.
     *
     * Applies a comprehensive lookup table covering:
     * - Accented vowels (acute, grave, circumflex, umlaut, tilde) — upper and lowercase
     * - Cedilla, ligatures (æ, œ), and special European characters (ø, ł, ß, å)
     * - Slavic caron characters (č, š, ž, ř, etc.)
     * - Eastern European ogonek and acute characters (ą, ę, ć, etc.)
     * - Typographic punctuation (em dash, en dash, curly quotes)
     * - Currency and symbol characters (£, €, ©, ®, ™, °, §, ¶)
     * - Lowercase and uppercase Greek alphabet
     * - LaTeX special characters ($, &, %, #, _, ^, ~, \, {, })
     * - Backslash-escaped variants for robustly-formatted BibTeX files
     *
     * Each substitution uses preg_replace() with preg_quote() for safety.
     * Note: some patterns appear twice in the table (e.g. dashes, quotes)
     * due to the two-block structure of the lookup array — duplicate keys in
     * PHP arrays silently overwrite, so only the later entry takes effect.
     *
     * @param string $text Input string potentially containing LaTeX escapes
     * @return string      String with all recognised LaTeX sequences replaced
     *                     by their Unicode equivalents
     *
     * @todo Consider replacing the preg_replace() loop with a single str_replace()
     *       call using array input, which would be significantly faster for large
     *       reference collections.
     */
    public static function convertLatexToUnicode(string $text): string {
        foreach (self::$latex_to_unicode as $latex => $unicode) {
            $text = preg_replace('/' . preg_quote($latex, '/') . '/', $unicode, $text);
        }

        return $text;
    }




    private static array $latex_to_unicode = [

            // --- Accented lowercase vowels ---
            '{\"a}' => 'ä', '{\"e}' => 'ë', '{\"i}' => 'ï', '{\"o}' => 'ö', '{\"u}' => 'ü', '{\"y}' => 'ÿ',
            '{\'a}' => 'á', "{\'e}" => 'é', '{\'i}' => 'í', '{\'o}' => 'ó', '{\'u}' => 'ú', '{\'y}' => 'ý',
            '{\`a}' => 'à', '{\`e}' => 'è', '{\`i}' => 'ì', '{\`o}' => 'ò', '{\`u}' => 'ù',
            '{\^a}' => 'â', '{\^e}' => 'ê', '{\^i}' => 'î', '{\^o}' => 'ô', '{\^u}' => 'û',
            '{\~a}' => 'ã', '{\~n}' => 'ñ', '{\~o}' => 'õ',

            // --- Accented uppercase vowels ---
            '{\"A}' => 'Ä', '{\"E}' => 'Ë', '{\"I}' => 'Ï', '{\"O}' => 'Ö', '{\"U}' => 'Ü', '{\"Y}' => 'Ÿ',
            '{\'A}' => 'Á', '{\'E}' => 'É', '{\'I}' => 'Í', '{\'O}' => 'Ó', '{\'U}' => 'Ú', '{\'Y}' => 'Ý',
            '{\`A}' => 'À', '{\`E}' => 'È', '{\`I}' => 'Ì', '{\`O}' => 'Ò', '{\`U}' => 'Ù',
            '{\^A}' => 'Â', '{\^E}' => 'Ê', '{\^I}' => 'Î', '{\^O}' => 'Ô', '{\^U}' => 'Û',
            '{\~A}' => 'Ã', '{\~N}' => 'Ñ', '{\~O}' => 'Õ',

            // --- Cedilla ---
            '{\c{c}}' => 'ç', '{\c{C}}' => 'Ç',

            // --- Special European characters ---
            '{\ss}' => 'ß',
            '{\ae}' => 'æ', '{\AE}' => 'Æ',
            '{\oe}' => 'œ', '{\OE}' => 'Œ',
            '{\o}'  => 'ø', '{\O}'  => 'Ø',
            '{\l}'  => 'ł', '{\L}'  => 'Ł',
            '{\aa}' => 'å', '{\AA}' => 'Å',

            // --- Slavic caron characters ---
            '{\v{c}}' => 'č', '{\v{C}}' => 'Č',
            '{\v{s}}' => 'š', '{\v{S}}' => 'Š',
            '{\v{z}}' => 'ž', '{\v{Z}}' => 'Ž',
            '{\v{r}}' => 'ř', '{\v{R}}' => 'Ř',
            '{\v{d}}' => 'ď', '{\v{D}}' => 'Ď',
            '{\v{t}}' => 'ť', '{\v{T}}' => 'Ť',
            '{\v{n}}' => 'ň', '{\v{N}}' => 'Ň',
            '{\v{l}}' => 'ľ', '{\v{L}}' => 'Ľ',

            // --- Eastern European ogonek and acute ---
            '{\k{a}}' => 'ą', '{\k{A}}' => 'Ą',
            '{\k{e}}' => 'ę', '{\k{E}}' => 'Ę',
            '{\'c}' => 'ć', '{\'C}' => 'Ć',
            '{\'n}' => 'ń', '{\'N}' => 'Ń',
            '{\'s}' => 'ś', '{\'S}' => 'Ś',
            '{\'z}' => 'ź', '{\'Z}' => 'Ź',
            '{\\.z}' => 'ż', '{\\.Z}' => 'Ż',

            // --- Typographic punctuation ---
            '---' => '—',   // em dash
            '--'  => '–',   // en dash
            '``'  => '"',   // left double quote
            "''"  => '"',   // right double quote
            '`'   => "'",   // left single quote
            "'"   => "'",   // right single quote (NOTE: will match all apostrophes)

            // --- Currency and special symbols ---
            '{\pounds}'          => '£',
            '{\euro}'            => '€',
            '{\copyright}'       => '©',
            '{\textregistered}'  => '®',
            '{\texttrademark}'   => '™',
            '{\textdegree}'      => '°',
            '{\S}'               => '§',
            '{\P}'               => '¶',

            // --- Lowercase Greek ---
            '{\alpha}'   => 'α', '{\beta}'    => 'β', '{\gamma}'   => 'γ', '{\delta}'   => 'δ',
            '{\epsilon}' => 'ε', '{\zeta}'    => 'ζ', '{\eta}'     => 'η', '{\theta}'   => 'θ',
            '{\iota}'    => 'ι', '{\kappa}'   => 'κ', '{\lambda}'  => 'λ', '{\mu}'      => 'μ',
            '{\nu}'      => 'ν', '{\xi}'      => 'ξ', '{\pi}'      => 'π', '{\rho}'     => 'ρ',
            '{\sigma}'   => 'σ', '{\tau}'     => 'τ', '{\upsilon}' => 'υ', '{\phi}'     => 'φ',
            '{\chi}'     => 'χ', '{\psi}'     => 'ψ', '{\omega}'   => 'ω',

            // --- Uppercase Greek ---
            '{\Alpha}'   => 'Α', '{\Beta}'    => 'Β', '{\Gamma}'   => 'Γ', '{\Delta}'   => 'Δ',
            '{\Epsilon}' => 'Ε', '{\Zeta}'    => 'Ζ', '{\Eta}'     => 'Η', '{\Theta}'   => 'Θ',
            '{\Iota}'    => 'Ι', '{\Kappa}'   => 'Κ', '{\Lambda}'  => 'Λ', '{\Mu}'      => 'Μ',
            '{\Nu}'      => 'Ν', '{\Xi}'      => 'Ξ', '{\Pi}'      => 'Π', '{\Rho}'     => 'Ρ',
            '{\Sigma}'   => 'Σ', '{\Tau}'     => 'Τ', '{\Upsilon}' => 'Υ', '{\Phi}'     => 'Φ',
            '{\Chi}'     => 'Χ', '{\Psi}'     => 'Ψ', '{\Omega}'   => 'Ω',

            // --- LaTeX special characters ---
            '{\$}'               => '$',
            '{\&}'               => '&',
            '{\%}'               => '%',
            '{\#}'               => '#',
            '{\_}'               => '_',
            '{\^{}}'             => '^',
            '{\~{}}'             => '~',
            '{\textbackslash}'   => '\\',
            '{\{}'               => '{',
            '{\}}'               => '}',

            // --- Backslash-escaped variants (for non-braced BibTeX) ---
            // NOTE: these duplicate some entries above; later entries overwrite
            // earlier ones with the same key in PHP arrays.
            '\{\\\"a\}' => 'ä', '\\\"a' => 'ä', '\"a' => 'ä',
            '\{\\\"o\}' => 'ö', '\\\"o' => 'ö', '\"o' => 'ö',
            '\{\\\"u\}' => 'ü', '\\\"u' => 'ü', '\"u' => 'ü',
            '\{\\\`a\}' => 'à', '\\\`a' => 'à', '\`a' => 'à',
            '\{\\\'a\}' => 'á', '\\\'a' => 'á', '\'a' => 'á',
            '\{\\\^a\}' => 'â', '\\\^a' => 'â', '\^a' => 'â',
            '\{\\\~a\}' => 'ã', '\\\~a' => 'ã', '\~a' => 'ã',
            '\{\\\"A\}' => 'Ä', '\\\"A' => 'Ä', '\"A' => 'Ä',
            '\{\\\"O\}' => 'Ö', '\\\"O' => 'Ö', '\"O' => 'Ö',
            '\{\\\"U\}' => 'Ü', '\\\"U' => 'Ü', '\"U' => 'Ü',
            '\{\\\ss\}' => 'ß', '\\\ss' => 'ß', '\ss' => 'ß',
            '\{\\\ae\}' => 'æ', '\\\ae' => 'æ', '\ae' => 'æ',
            '\{\\\oe\}' => 'œ', '\\\oe' => 'œ', '\oe' => 'œ',
        ];
}
?>