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
 * OMToCSVAdapter
 *
 * Adapter that serialises a PubLink {@see Article} object model to a CSV file.
 * This is the inverse of {@see CSVToOMAdapter} — the output CSV follows the
 * same column structure expected by that adapter, allowing a round-trip between
 * the Article domain object and a spreadsheet-based editorial workflow.
 *
 * Output column order:
 *   - Copyright metadata: "Copyright Holder", "Copyright Year", "License Url"
 *   - Publication metadata: "Section reference", "Start Page", "End Page",
 *     "Date", "Year", "Article Title", "Article Subtitle", "DOI",
 *     "Abstract", "Keywords" (semicolon-separated)
 *   - Authors (numbered): "Author N" (format: "LastName, FirstName"),
 *     "Email N", "Affiliation N"
 *   - First author email summary: "Author Email"
 *   - Cover image (if present): "Cover Image", "Cover Image Alt Text"
 *   - Galley files (numbered, excluding cover image): "Galley File N",
 *     "Galley File Alt Text N", "Galley File Genre N"
 *
 * Journal-level fields (Journal Name, Volume, Issue) are intentionally
 * commented out in the current implementation.
 *
 * @package  Biblhertz\Article\Adapters
 * @author   Chris Tomlinson
 * @since    11th July 2023
 *
 */
class OMToCSVAdapter {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var Article The Article object model to serialise */
    private Article $article;

    /** @var string Input directory path (stored but not currently used in generateCSV()) */
    private string $inputDir;

    /** @var string OJS username (stored but not currently used in generateCSV()) */
    private string $ojsUser;

    /** @var bool Verbose logging flag (stored but not currently used in generateCSV()) */
    private bool $verbose;

    /** @var string Absolute path to the output CSV file to be written */
    private string $fileName;


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs the adapter with the Article to export and the output file path.
     *
     * @param Article $article The populated Article object model to serialise
     * @param string  $uri     Absolute path to the CSV file to write
     */
    public function __construct(Article $article, string $uri) {
        $this->article  = $article;
        $this->fileName = $uri;
    }


    /****************************************************************/
    /*  ACCESSOR METHODS                                            */
    /****************************************************************/

    /**
     * Returns the Article object model being serialised.
     *
     * @return Article
     */
    public function getArticle(): Article {
        return $this->article;
    }

    /**
     * Sets the input directory path.
     *
     * @param string $dir Absolute directory path
     */
    public function setInputDir(string $dir): void {
        $this->inputDir = $dir;
    }

    /**
     * Overrides the output CSV file path set in the constructor.
     *
     * @param string $file Absolute path to the output CSV file
     */
    public function setFileName(string $file): void {
        $this->fileName = $file;
    }

    /**
     * Sets the OJS username to associate with the export.
     *
     * @param string $user OJS username string
     */
    public function setOJSUser(string $user): void {
        $this->ojsUser = $user;
    }

    /**
     * Enables or disables verbose logging output.
     *
     * @param bool $v True for verbose output
     */
    public function setVerbose(bool $v): void {
        $this->verbose = $v;
    }


    /****************************************************************/
    /*  GENERATION METHODS                                          */
    /****************************************************************/

    /**
     * Serialises the Article object model to a CSV file at $this->fileName.
     *
     * Opens the file via {@see FileCreator}, writes one key-value row per
     * metadata field, then closes the file. Each row is a two-element array
     * [column_name, value], mirroring the format expected by {@see CSVToOMAdapter}.
     *
     * Author handling: each author is written as three consecutive rows
     * ("Author N", "Email N", "Affiliation N"). The email of the first author
     * is additionally written to a summary "Author Email" row after the loop.
     *
     * Keywords are joined with ';' before writing (matching the semicolon-
     * delimited format that CSVToOMAdapter splits on).
     *
     * Galley files: the cover image (if present) is written separately using
     * dedicated column names. All other galley types are written as numbered
     * rows, skipping any entry whose type matches GalleyFile::$COVER_IMAGE.
     *
     * Note: journal-level fields (Journal Name, Volume, Issue) are commented
     * out in the current implementation and not written to the CSV.
     *
     * @todo Abstract is passed directly to writeCSV() — if getAbstract() returns
     *       an AAbstract object (as set by JATSToOMAdapter) rather than a plain
     *       string, this will produce a serialised object representation or trigger
     *       a fatal error. Verify that getAbstract() returns a string in this context,
     *       or call a dedicated toString/render method on the AAbstract object.
     * @todo $inputDir, $ojsUser, and $verbose are stored but never used within
     *       generateCSV(). Consider removing them if they remain unused, or
     *       document the intended future use.
     */
    public function generateCSV(): void {
        $csvFile = new FileCreator();
        $csvFile->setFileName($this->fileName);
        $csvFile->openFile();

        // --- Copyright and license metadata ---
        // Note: Journal Name, Volume, Issue are intentionally omitted (commented out)
        $csvFile->writeCSV(["Copyright Holder", $this->article->getCopyRightHolder()]);
        $csvFile->writeCSV(["Copyright Year",   $this->article->getCopyRightYear()]);
        $csvFile->writeCSV(["License Url",      $this->article->getLicenseUrl()]);

        // --- Publication metadata ---
        $csvFile->writeCSV(["Section reference", $this->article->getSectionRef()]);
        $csvFile->writeCSV(["Start Page",        $this->article->getStartPage()]);
        $csvFile->writeCSV(["End Page",          $this->article->getEndPage()]);
        $csvFile->writeCSV(["Date",              $this->article->getDate()]);
        $csvFile->writeCSV(["Year",              $this->article->getYear()]);
        $csvFile->writeCSV(["Article Title",     $this->article->getTitle()]);
        $csvFile->writeCSV(["Article Subtitle",  $this->article->getSubTitle()]);
        $csvFile->writeCSV(["DOI",               $this->article->getDOI()]);
        $csvFile->writeCSV(["Abstract",          $this->article->getAbstract()->getAsText()]);  // @todo verify string return

        // Join keywords with ';' to match the format CSVToOMAdapter splits on
        $keyStr = "";
        foreach ($this->article->getKeyWords() as $keyword) $keyStr .= $keyword . ";";
        $csvFile->writeCSV(["Keywords", $keyStr]);

        // --- Authors: one set of rows per author, numbered from 1 ---
        $authorEmail = "";  // Captures the first author's email for the summary row
        $c = 1;
        foreach ($this->article->getAuthors() as $author) {
            // Format: "LastName, FirstName" — mirrors CSVToOMAdapter's explode(",", ...) parsing
            $csvFile->writeCSV(["Author $c",      $author->getLastName() . ", " . $author->getFirstName()]);
            $csvFile->writeCSV(["Email $c",       $author->getEmail()]);
            $csvFile->writeCSV(["Affiliation $c", $author->getFirstAffiliation()]);
            if ($c === 1) $authorEmail = $author->getEmail();
            $c++;
        }
        // Summary row with just the first author's email (used by some OJS import workflows)
        $csvFile->writeCSV(["Author Email", $authorEmail]);

        // --- Cover image (optional) ---
        $coverImage = $this->article->getCoverImageFile();
        if ($coverImage) {
            $csvFile->writeCSV(["Cover Image",          $coverImage->getGalleyFilePath()]);
            $csvFile->writeCSV(["Cover Image Alt Text", $coverImage->getGalleyFileAltText()]);
        }

        // --- Numbered galley files (all types except cover image) ---
        $c = 1;
        foreach ($this->article->getGalleyFiles() as $galley) {
            if ($galley->getType() !== GalleyFile::$COVER_IMAGE) {
                $csvFile->writeCSV(["Galley File $c",          $galley->getGalleyFilePath()]);
                $csvFile->writeCSV(["Galley File Alt Text $c", $galley->getGalleyFileAltText()]);
                $csvFile->writeCSV(["Galley File Genre $c",    $galley->getGenre()]);
                $c++;
            }
        }

        $csvFile->closeFile();
    }
}
?>