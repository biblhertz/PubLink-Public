<?php
namespace Biblhertz\Article\Adapters;

use Biblhertz\Article\om\GalleyFile;
use Biblhertz\Article\om\Article;
use Biblhertz\Article\om\Author;
use Biblhertz\Article\om\Affiliation;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Article\utilities\Utilities;
use Biblhertz\Article\utilities\FileCreator;

/**
 * CSVToOMAdapter
 *
 * Adapter that populates a PubLink {@see Article} object model from a flat
 * associative CSV row array. Implements the Adapter pattern between a
 * spreadsheet-based editorial workflow and the internal Article domain object.
 *
 * Expected CSV column names (keys in $csvArray):
 *   - Metadata:  "Copyright Holder", "Copyright Year", "License Url",
 *                "Section reference", "Start Page", "End Page",
 *                "Date", "Year", "Article Title", "Article Subtitle",
 *                "DOI", "Abstract", "Keywords" (semicolon-separated)
 *   - Authors:   "Author 1", "Author 2", ... (format: "LastName, FirstName")
 *                "Email 1", "Email 2", ...
 *                "Affiliation 1", "Affiliation 2", ... (format: "Name, Division")
 *   - Galleys:   "Cover Image", "Cover Image Alt Text" (optional)
 *                "Galley File 1", "Galley File Alt Text 1", "Galley File Genre 1", ...
 *
 * Authors and galley files are discovered by iterating numbered suffixes
 * (1, 2, 3...) until no matching key is found in $csvArray.
 *
 * @package  Biblhertz\Article\Adapters
 * @author   Chris Tomlinson
 * @since    11th July 2023
 */
class CSVToOMAdapter {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var Article The Article object model being populated */
    private Article $article;

    /** @var string Path to the directory containing input files referenced by galley paths */
    private string $inputDir;

    /** @var string OJS username to associate with the imported article */
    private string $ojsUser;

    /** @var bool Whether to enable verbose progress logging */
    private bool $verbose;

    /**
     * @var array<string,mixed> Associative array of CSV column values for a
     *                          single article row, keyed by column header name
     */
    private array $csvArray;

    /**
     * @var string Filename of the CSV source file.
     */
    private string $fileName = '';


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs the adapter and initialises an empty Article object.
     *
     * Call the setter methods to supply $csvArray, $ojsUser, and $inputDir
     * before invoking {@see generateObjectModel()}.
     */
    public function __construct() {
        $this->article = new Article();
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
     * Sets the directory path used to resolve relative galley file references.
     *
     * @param string $dir Absolute path to the input directory
     */
    public function setInputDir(string $dir): void {
        $this->inputDir = $dir;
    }

    /**
     * Sets the source CSV filename.
     *
     * @param string $file Filename (not used internally beyond storage)
     *
     */
    public function setFileName(string $file): void {
        $this->fileName = $file;
    }

    /**
     * Sets the OJS username to associate with the imported article.
     *
     * Stored on the Article via setOJSUserName() during {@see generateObjectModel()}.
     *
     * @param string $user OJS username string
     */
    public function setOJSUser(string $user): void {
        $this->ojsUser = $user;
    }

    /**
     * Enables or disables verbose logging output during generation.
     *
     * @param bool $v True for verbose output
     */
    public function setVerbose(bool $v): void {
        $this->verbose = $v;
    }

    /**
     * Supplies the parsed CSV row as an associative array.
     *
     * Must be called before {@see generateObjectModel()}. Keys should match
     * the expected column names described in the class docblock.
     *
     * @param array<string,mixed> $a Associative CSV row array
     */
    public function setCSVArray(array $a): void {
        $this->csvArray = $a;
    }


    /****************************************************************/
    /*  GENERATION METHODS                                          */
    /****************************************************************/

    /**
     * Populates the Article object model from the supplied CSV row array.
     *
     * Processes fields in the following order:
     *   1. Administrative metadata (OJS user, copyright, license, section, pages).
     *   2. Publication metadata (date, year, title, subtitle, DOI, abstract, keywords).
     *   3. Authors — iterated from "Author 1" upward until no key is found.
     *   4. Cover image galley (optional — skipped if "Cover Image" is absent).
     *   5. Numbered galley files — iterated from "Galley File 1" upward.
     *
     * Date handling: if "Date" contains a slash-delimited string (d/m/Y or
     * m/d/Y), it is parsed via strtotime() and reformatted as Y-m-d. Dates
     * that are already in Y-m-d format are passed through unchanged.
     *
     * Keywords are split on ';' and empty strings are filtered out before
     * being set on the Article.
     *
     */
    public function generateObjectModel(): void {

        // --- Administrative metadata ---
        $this->article->setOJSUserName($this->ojsUser);
        $this->article->setCopyRightHolder($this->csvArray["Copyright Holder"]);
        $this->article->setCopyRightYear($this->csvArray["Copyright Year"]);
        $this->article->setLicenseUrl($this->csvArray["License Url"]);
        $this->article->setSectionRef($this->csvArray["Section reference"]);
        $this->article->setStartPage($this->csvArray["Start Page"]);
        $this->article->setEndPage($this->csvArray["End Page"]);

        // --- Date: normalise slash-delimited dates to Y-m-d ---
        $date  = $this->csvArray["Date"];
        $parts = explode("/", $date);
        if (count($parts) == 3) {
            $timestamp = strtotime($date);
            if ($timestamp !== false) $date = date("Y-m-d", $timestamp);
        }
        $this->article->setDate($date);

        // --- Publication metadata ---
        $this->article->setYear($this->csvArray["Year"]);
        $this->article->setTitle($this->csvArray["Article Title"]);
        $this->article->setSubTitle($this->csvArray["Article Subtitle"]);
        $this->article->setDOI($this->csvArray["DOI"]);
        $this->article->setAbstract($this->csvArray["Abstract"]);

        // Split semicolon-delimited keywords and strip empty entries
        $keywords = explode(";", $this->csvArray["Keywords"]);
        $keyArr   = [];
        foreach ($keywords as $key)
            if (strlen($key)) $keyArr[] = $key;
        $this->article->setKeywords($keyArr);

        // --- Authors: iterate numbered columns until no key is found ---
        $c = 1;
        while (isset($this->csvArray["Author $c"])) {
            $this->addAuthor($c);   // BUG in original: called as addAuthor($c, $authors)
            $c++;
        }

        // --- Cover image galley (optional) ---
        if (isset($this->csvArray["Cover Image"])) {
            $galley = new GalleyFile();
            $galley->setGalleyFilePath($this->csvArray["Cover Image"]);
            $galley->setGalleyFileAltText($this->csvArray["Cover Image Alt Text"]);
            $galley->setType(GalleyFile::$COVER_IMAGE);
            $this->article->addGalleyFile($galley);
        }

        // --- Numbered galley files: iterate until no key is found ---
        $c = 1;
        while (isset($this->csvArray["Galley File $c"])) {
            $galley = new GalleyFile();
            $galley->setGalleyFilePath($this->csvArray["Galley File $c"]);
            $galley->setGalleyFileAltText($this->csvArray["Galley File Alt Text $c"]);
            if (isset($this->csvArray["Galley File Genre $c"]))
                $galley->setGenre($this->csvArray["Galley File Genre $c"]);
            $galley->setTypeFromFileType();
            $this->article->addGalleyFile($galley);
            $c++;
        }
    }

    /**
     * Constructs an Author from a numbered CSV column set and adds it to the Article.
     *
     * Reads "Author $c" (expected format: "LastName, FirstName"), "Email $c",
     * and "Affiliation $c" (expected format: "Name, Division") from $csvArray.
     * All fields except the author name itself are optional — missing columns
     * are silently skipped.
     *
     * Affiliation division is taken from the second comma-delimited segment if
     * present; if only one segment exists, only the name is set.
     *
     * @param int $c The 1-based author index corresponding to the column suffix
     *
     */
    private function addAuthor(int $c): void {
        $author = new Author();

        // Parse "LastName, FirstName" — split on first comma
        $names = explode(",", $this->csvArray["Author $c"]);
        if (count($names) == 2) {
            $author->setFirstName(trim($names[1]));
            $author->setLastName(trim($names[0]));
        }
        else if (count($names) == 1) 
              $author->setLastName(trim($names[0]));

        if (isset($this->csvArray["Email $c"]))
            $author->setEmail($this->csvArray["Email $c"]);

        // Parse "InstitutionName, DivisionName" affiliation if present
        if (isset($this->csvArray["Affiliation $c"])) {
            $affiliation = new Affiliation();
            $names = explode(",", $this->csvArray["Affiliation $c"]);
            $affiliation->setName(trim($names[0]));
            if (isset($names[1])) $affiliation->setDivision(trim($names[1]));
            $author->addAffiliation($affiliation);
        }

        $this->article->addAuthor($author);
    }
}
?>