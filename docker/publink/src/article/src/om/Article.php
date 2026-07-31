<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\ArticleObject;
use Biblhertz\Article\om\Keyword;
use Biblhertz\Article\om\AAbstract;
use Biblhertz\Article\om\ReferenceCollection;

/**
 * Article
 *
 * Represents a journal article and serves as the central domain object in the
 * PubLink publishing system. Extends {@see ArticleObject}.
 *
 * Holds all metadata required for export to OJS (Open Journal Systems) and
 * JATS XML, including bibliographic fields, licensing and copyright information,
 * author and affiliation collections, keywords, references, and galley files.
 *
 * Three categories of data are managed by this class:
 * - **Parsed fields** — strongly-typed properties populated during import
 *   (title, authors, DOI, dates, license, etc.).
 * - **Raw XML chunks** — unparsed JATS fragments stored as strings for
 *   pass-through to export (`$historyTag`, `$bodyTag`, `$footNotesTag`).
 * - **Collections** — authors, affiliations, keywords, galley files, and
 *   references, each managed through dedicated add/get/remove methods.
 *
 * A unique JATS ID is assigned automatically on construction via `uniqid()`.
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class Article extends ArticleObject {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string Full name of the journal (OJS journal name). */
    private string $journalName = "";

    /** @var string OJS internal journal identifier. */
    private string $journalID = "";

    /** @var string Abbreviated journal title. */
    private string $journalAbbreviation = "";

    /** @var string Journal ISSN. */
    private string $journalISSN = "";

    /** @var string Name of the journal publisher. */
    private string $journalPublisher = "";

    /** @var string Geographic location of the journal publisher. */
    private string $journalLocation = "";

    /** @var string Primary article title. */
    private string $title = "";

    /** @var string Article subtitle. */
    private string $subTitle = "";

    /** @var string Alternative article title (e.g. translated title). */
    private string $altTitle = "";

    /** @var string Translated title with xml:lang="en" from <trans-title-group>. */
    private string $transTitle = "";

    /** @var string Journal volume number. */
    private string $volume = "";

    /** @var string Journal issue number. */
    private string $issue = "";

    /** @var string Full publication date string. */
    private string $date = "";

    /** @var int Four-digit year of publication. */
    private int $year = 0;

    /** @var string Numeric month of publication (1–12). */
    private string $month = "";

    /** @var int Numeric day of publication (1–31). */
    private int $day = 0;

    /** @var string Digital Object Identifier for this article. */
    private string $doi = "";

    /** @var string First page of the article within the issue. */
    private string $startPage = "";

    /** @var string Last page of the article within the issue. */
    private string $endPage = "";

    /** @var AAbstract|null Structured abstract composed of {@see Paragraph} objects. */
    private ?AAbstract $abstract = null;

    /** @var string OJS section reference name for this article. */
    private string $sectionRef = "";

    /** @var string URL of the license applied to this article. */
    private string $licenseUrl = "";

    /** @var string Short license type identifier (e.g. "CC BY 4.0"). */
    private string $licenseType = "";

    /** @var string Full license paragraph text for inclusion in exports. */
    private string $licenseParagraph = "";

    /** @var string Copyright statement for this article. */
    private string $copyStatement = "";

    /** @var string Name of the copyright holder. */
    private string $copyRightHolder = "";

    /** @var string Year in which copyright was asserted. */
    private string $copyRightYear = "";

    /**
     * OJS username associated with this article's submission.
     * Used during OJS XML export; not part of the core article model.
     *
     * @var string
     */
    private string $ojsUserName = "";

    /**
     * Locale code for this article (e.g. `"en_US"`).
     * Used during OJS XML export; not part of the core article model.
     *
     * @var string
     */
    private string $locale = "";

    // ---------------------------------------------------------------
    // Raw XML chunks — stored as strings for pass-through to JATS export
    // ---------------------------------------------------------------

    /** @var string Raw JATS `<history>` XML fragment. */
    private string $historyTag = "";

    /** @var string Raw JATS `<body>` XML fragment. */
    private string $bodyTag = "";

    /** @var string Raw JATS `<fn-group>` / footnotes XML fragment. */
    private string $footNotesTag = "";

    /** @var string "Cite this article as" formatted string. */
    private string $citeAs = "";

    // ---------------------------------------------------------------
    // Collections
    // ---------------------------------------------------------------

    /** @var Keyword[] Article keywords. */
    private array $keywords = [];

    /** @var Author[] Author objects associated with this article. */
    private array $authors = [];

    /** @var Affiliation[] Affiliation objects associated with this article's authors. */
    private array $affiliations = [];

    /**
     * Galley files attached to this article, indexed by their JATS ID.
     * Types include JATS XML, PDF, HTML, cover images, and dependent files.
     *
     * @var GalleyFile[]
     */
    private array $galleyFiles = [];

    /** @var ReferenceCollection Bibliographic references cited in this article. */
    private ReferenceCollection $references;

    /**
     * When `true`, this article object is treated as read-only and should not
     * be modified by the GUI or import processes.
     *
     * @var bool
     */
    protected bool $readOnly = false;

    /**
     * Whether this object may be edited via the GUI.
     *
     * @var bool
     */
    public static bool $ALLOW_EDIT = true;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise the article with an empty {@see ReferenceCollection}, a unique
     * JATS ID, and a list of fields that are excluded from reference field
     * selection in the GUI (`disallowedFields`).
     */
    public function __construct() {
        $this->references = new ReferenceCollection();
        $this->disallowedFields = ["historyTag", "bodyTag", "footNotesTag", "readOnly", "citeAs"];
        $this->setJatsID(uniqid());
    }


    /****************************************************************/
    /* INTERFACE METHODS — BIBLIOGRAPHIC FIELDS                     */
    /****************************************************************/

    /** @param string $s Primary article title. */
    public function setTitle(string $s): void { $this->title = $s; }

    /** @return string Primary article title. */
    public function getTitle(): string { return $this->title; }

    /** @param string $s Article subtitle. */
    public function setSubTitle(string $s): void { $this->subTitle = $s; }

    /** @return string Article subtitle. */
    public function getSubTitle(): string { return $this->subTitle; }

    /** @param string $s Alternative title (e.g. translation). */
    public function setAltTitle(string $s): void { $this->altTitle = $s; }

    /** @return string Alternative title. */
    public function getAltTitle(): string { return $this->altTitle; }

    /** @param string $s English translated title from <trans-title-group xml:lang="en">. */
    public function setTransTitle(string $s): void { $this->transTitle = $s; }

    /** @return string English translated title. */
    public function getTransTitle(): string { return $this->transTitle; }

    /** @param string $s Journal name. */
    public function setJournalName(string $s): void { $this->journalName = $s; }

    /** @return string Journal name. */
    public function getJournalName(): string { return $this->journalName; }

    /** @param string $s Journal ISSN. */
    public function setJournalISSN(string $s): void { $this->journalISSN = $s; }

    /** @return string Journal ISSN. */
    public function getJournalISSN(): string { return $this->journalISSN; }

    /** @param string $s Journal publisher name. */
    public function setJournalPublisher(string $s): void { $this->journalPublisher = $s; }

    /** @return string Journal publisher name. */
    public function getJournalPublisher(): string { return $this->journalPublisher; }

    /** @param string $s Journal publisher location. */
    public function setJournalLocation(string $s): void { $this->journalLocation = $s; }

    /** @return string Journal publisher location. */
    public function getJournalLocation(): string { return $this->journalLocation; }

    /** @param string $s OJS journal identifier. */
    public function setJournalID(string $s): void { $this->journalID = $s; }

    /** @return string OJS journal identifier. */
    public function getJournalID(): string { return $this->journalID; }

    /** @param string $s Abbreviated journal title. */
    public function setJournalAbbreviation(string $s): void { $this->journalAbbreviation = $s; }

    /** @return string Abbreviated journal title. */
    public function getJournalAbbreviation(): string { return $this->journalAbbreviation; }

    /** @param AAbstract $s Structured abstract object. */
    public function setAbstract(AAbstract $s): void { $this->abstract = $s; }

    /** @return AAbstract|null Structured abstract object, or null if not set. */
    public function getAbstract(): ?AAbstract { return $this->abstract; }

    /** @param int $s Four-digit publication year. */
    public function setYear(int $s): void { $this->year = $s; }

    /** @return int Four-digit publication year. */
    public function getYear(): int { return $this->year; }

    /** @param string $s Journal volume number. */
    public function setVolume(string $s): void { $this->volume = $s; }

    /** @return string Journal volume number. */
    public function getVolume(): string { return $this->volume; }

    /** @param string $s Journal issue number. */
    public function setIssue(string $s): void { $this->issue = $s; }

    /** @return string Journal issue number. */
    public function getIssue(): string { return $this->issue; }

    /**
     * Set the publication date string.
     *
     * @param string $s Publication date (format may vary by source).
     */
    public function setDate(string $s): void {
        $this->date = $s;
    }

    /** @return string Publication date string. */
    public function getDate(): string { return $this->date; }

    /** @param string $s Numeric month of publication. */
    public function setMonth(string $s): void { $this->month = $s; }

    /** @return string Numeric month of publication. */
    public function getMonth(): string { return $this->month; }

    /** @param int $s Numeric day of publication. */
    public function setDay(int $s): void { $this->day = $s; }

    /** @return int Numeric day of publication. */
    public function getDay(): int { return $this->day; }

    /** @param string $doi Digital Object Identifier. */
    public function setDOI(string $doi): void { $this->doi = $doi; }

    /** @return string Digital Object Identifier. */
    public function getDOI(): string { return $this->doi; }

    /** @param string $s First page number within the issue. */
    public function setStartPage(string $s): void { $this->startPage = $s; }

    /** @return string First page number. */
    public function getStartPage(): string { return $this->startPage; }

    /** @param string $s Last page number within the issue. */
    public function setEndPage(string $s): void { $this->endPage = $s; }

    /** @return string Last page number. */
    public function getEndPage(): string { return $this->endPage; }

    /** @param string $s OJS section reference name. */
    public function setSectionRef(string $s): void { $this->sectionRef = $s; }

    /** @return string OJS section reference name. */
    public function getSectionRef(): string { return $this->sectionRef; }


    /****************************************************************/
    /* INTERFACE METHODS — LICENSE & COPYRIGHT                      */
    /****************************************************************/

    /** @param string $s License URL (e.g. `"https://creativecommons.org/licenses/by/4.0/"`). */
    public function setLicenseUrl(string $s): void { $this->licenseUrl = $s; }

    /** @return string License URL. */
    public function getLicenseUrl(): string { return $this->licenseUrl; }

    /** @param string $s Short license type identifier (e.g. `"CC BY 4.0"`). */
    public function setLicenseType(string $s): void { $this->licenseType = $s; }

    /** @return string Short license type identifier. */
    public function getLicenseType(): string { return $this->licenseType; }

    /** @param string $s Full license paragraph for inclusion in exports. */
    public function setLicenseParagraph(string $s): void { $this->licenseParagraph = $s; }

    /** @return string Full license paragraph. */
    public function getLicenseParagraph(): string { return $this->licenseParagraph; }

    /** @param string $s Copyright statement. */
    public function setCopyStatement(string $s): void { $this->copyStatement = $s; }

    /** @return string Copyright statement. */
    public function getCopyStatement(): string { return $this->copyStatement; }

    /** @param string $s Name of the copyright holder. */
    public function setCopyRightHolder(string $s): void { $this->copyRightHolder = $s; }

    /** @return string Name of the copyright holder. */
    public function getCopyRightHolder(): string { return $this->copyRightHolder; }

    /** @param string $s Year in which copyright was asserted. */
    public function setCopyRightYear(string $s): void { $this->copyRightYear = $s; }

    /** @return string Copyright year. */
    public function getCopyRightYear(): string { return $this->copyRightYear; }


    /****************************************************************/
    /* INTERFACE METHODS — OJS / EXPORT FIELDS                      */
    /****************************************************************/

    /** @param string $s OJS username for this submission. */
    public function setOJSUserName(string $s): void { $this->ojsUserName = $s; }

    /** @return string OJS username. */
    public function getOJSUserName(): string { return $this->ojsUserName; }

    /** @param string $s Locale code (e.g. `"en_US"`). */
    public function setLocale(string $s): void { $this->locale = $s; }

    /** @return string Locale code. */
    public function getLocale(): string { return $this->locale; }

    /** @param string $s Raw JATS `<history>` XML fragment. */
    public function setHistoryTag(string $s): void { $this->historyTag = $s; }

    /** @return string Raw JATS `<history>` XML fragment. */
    public function getHistoryTag(): string { return $this->historyTag; }

    /** @param string $s Raw JATS `<body>` XML fragment. */
    public function setBodyTag(string $s): void { $this->bodyTag = $s; }

    /** @return string Raw JATS `<body>` XML fragment. */
    public function getBodyTag(): string { return $this->bodyTag; }

    /** @param string $s Raw JATS footnotes XML fragment. */
    public function setFootNotesTag(string $s): void { $this->footNotesTag = $s; }

    /** @return string Raw JATS footnotes XML fragment. */
    public function getFootNotesTag(): string { return $this->footNotesTag; }

    /** @param string $s Formatted "cite this article as" string. */
    public function setCiteAs(string $s): void { $this->citeAs = $s; }

    /** @return string Formatted "cite this article as" string. */
    public function getCiteAs(): string { return $this->citeAs; }


    /****************************************************************/
    /* AUTHOR METHODS                                               */
    /****************************************************************/

    /**
     * Replace the entire author collection.
     *
     * @param Author[] $authors  Array of {@see Author} objects.
     */
    public function setAuthors(array $authors): void {
        $this->authors = $authors;
    }

    /**
     * Return all authors associated with this article.
     *
     * @return Author[]
     */
    public function getAuthors(): array {
        return $this->authors;
    }

    /**
     * Add an author to the article if they do not already exist.
     *
     * Duplicate detection is performed via {@see Author::authorExists()},
     * which compares authors by identity (typically JATS ID). An author is
     * only appended if no existing author in the collection matches.
     *
     * @param  Author $author  The author to add.
     * @return void
     */
    public function addAuthor(Author $author): void {
        $exists = false;
        foreach ($this->authors as $a) {
            if ($author->authorExists($a)) {
                $exists = true;
                break;
            }
        }
        if (!$exists) array_push($this->authors, $author);
    }

    /**
     * Return the corresponding author for this article, or false if none is set.
     *
     * Iterates the author collection and returns the first author whose
     * {@see Author::getCorrespondingAuthor()} flag is true.
     *
     * @return Author|false  The corresponding author, or `false` if not found.
     */
    public function getCorrespondingAuthor(): Author|false {
        foreach ($this->authors as $a) {
            if ($a->getCorrespondingAuthor()) return $a;
        }
        return false;
    }

    /**
     * Retrieve an author by their JATS ID.
     *
     * @param  string $jid  The JATS ID to search for.
     * @return Author|false  The matching author, or `false` if not found.
     */
    public function getAuthorByJatsID(string $jid): Author|false {
        foreach ($this->authors as $a) {
            if ($a->getJatsID() === $jid) return $a;
        }
        return false;
    }


    /****************************************************************/
    /* KEYWORD METHODS                                              */
    /****************************************************************/

    /**
     * Create a new {@see Keyword} from a string and append it to the collection.
     *
     * @param  string $s  Keyword text.
     * @return void
     */
    public function setKeyword(string $s): void {
        $keyword = new Keyword();
        $keyword->setName($s);
        array_push($this->keywords, $keyword);
    }

    /**
     * Return all keywords associated with this article.
     *
     * @return Keyword[]
     */
    public function getKeywords(): array {
        return $this->keywords;
    }

    /**
     * Replace the entire keyword collection.
     *
     * @param Keyword[] $a  Array of {@see Keyword} objects.
     */
    public function setKeywords(array $a): void {
        $this->keywords = $a;
    }

    /**
     * Remove a keyword from the collection by its array key.
     *
     * @param  string $key  The array key of the keyword to remove.
     * @return void
     */
    public function removeKeyword(string $key): void {
        unset($this->keywords[$key]);
    }


    /****************************************************************/
    /* REFERENCE METHODS                                            */
    /****************************************************************/

    /**
     * Add a single reference to the article's {@see ReferenceCollection}.
     *
     * The reference is keyed by its label. Throws on duplicate or invalid labels.
     *
     * @param  Reference $ref  The reference to add.
     * @return void
     * @throws \Exception  Propagates any exception thrown by the collection.
     */
    public function addReference(Reference $ref): void {
        $this->references->offsetSet($ref->getLabel(), $ref);
    }

    /**
     * Replace the reference collection from a plain array of {@see Reference} objects.
     *
     * Constructs a new {@see ReferenceCollection} and calls {@see addReference()}
     * for each item. Invalid references ({@see \InvalidArgumentException}) are
     * logged and skipped without aborting the import.
     *
     * @param  Reference[] $refs  Array of reference objects.
     * @return void
     */
    public function setReferences(array $refs): void {
        $this->references = new ReferenceCollection();
        foreach ($refs as $ref) {
            try {
                $this->addReference($ref);
            } catch (InvalidArgumentException $e) {
                continue;
            }
        }
    }

    /**
     * Return the full reference collection for this article.
     *
     * @return ReferenceCollection
     */
    public function getReferences(): ReferenceCollection {
        return $this->references;
    }

    /**
     * Retrieve a reference by its label key.
     *
     * @param  string $key  The reference label to search for.
     * @return Reference|false  The matching reference, or `false` if not found.
     */
    public function getReferencefromKey(string $key): Reference|false {
        foreach ($this->references as $ref) {
            if ($ref->getLabel() === $key) return $ref;
        }
        return false;
    }

    /**
     * Update a reference's data from an external API search result.
     *
     * Looks up the reference by key and delegates to
     * {@see Reference::updateFromAPISearch()} if found.
     *
     * @param  string $key   The label key of the reference to update.
     * @param  array  $vals  Associative array of field values from the API search.
     * @return void
     */
    public function updateReferenceFromAPISearch(string $key, array $vals): void {
        $ref = $this->getReferencefromKey($key);
        if ($ref !== false) $ref->updateFromAPISearch($vals);
    }


    /****************************************************************/
    /* AFFILIATION METHODS                                          */
    /****************************************************************/

    /**
     * Add an affiliation to the article if it does not already exist.
     *
     * Duplicate detection uses {@see Affiliation::affiliationExists()},
     * which compares by JATS ID.
     *
     * @param  Affiliation $affiliation  The affiliation to add.
     * @return void
     */
    public function addAffiliation(Affiliation $affiliation): void {
        $exists = false;
        foreach ($this->affiliations as $a) {
            if ($affiliation->affiliationExists($a)) {
                $exists = true;
                break;
            }
        }
        if (!$exists) array_push($this->affiliations, $affiliation);
    }

    /**
     * Retrieve an affiliation by its JATS ID.
     *
     * @param  string $key  The JATS ID to search for.
     * @return Affiliation|false  The matching affiliation, or `false` if not found.
     */
    public function getAffiliationFromKey(string $key): Affiliation|false {
        foreach ($this->affiliations as $aff) {
            if ($aff->getJatsID() === $key) return $aff;
        }
        return false;
    }

    /**
     * Return all affiliations associated with this article.
     *
     * @return Affiliation[]
     */
    public function getAffiliations(): array {
        return $this->affiliations;
    }


    /****************************************************************/
    /* GALLEY FILE METHODS                                          */
    /*                                                              */
    /* The galley files array is indexed by the JATS ID of each     */
    /* GalleyFile object, which must be unique within the article.  */
    /****************************************************************/

    /**
     * Return all galley files attached to this article.
     *
     * @return GalleyFile[]  Array indexed by JATS ID.
     */
    public function getGalleyFiles(): array {
        return $this->galleyFiles;
    }

    /**
     * Add a galley file to the article if it does not already exist.
     *
     * Existence is checked via {@see galleyFileExists()} using the file's JATS ID.
     *
     * @param  GalleyFile $galley  The galley file to add.
     * @return void
     */
    public function addGalleyFile(GalleyFile $galley): void {
        if (!$this->galleyFileExists($galley)) {
            $this->galleyFiles[$galley->getJatsID()] = $galley;
        }
    }

    /**
     * Check whether a galley file is already attached to this article.
     *
     * @param  GalleyFile $galley  The galley file to check.
     * @return bool  `true` if a file with the same JATS ID is already present.
     */
    public function galleyFileExists(GalleyFile $galley): bool {
        return isset($this->galleyFiles[$galley->getJatsID()]);
    }

    /**
     * Remove a galley file and all its dependent files from the article.
     *
     * Before removing the target file, iterates the collection and removes any
     * file whose genre is {@see GalleyFile::$DEPENDANT_GENRE} and whose parent
     * key matches `$key`. The attached JATS XML file is protected and cannot
     * be removed via this method.
     *
     * @param  string $key  The JATS ID of the galley file to remove.
     * @return void
     */
    public function removeGalleyFile(string $key): void {
        // Protect the attached JATS XML file from deletion
        $jatsFile = $this->getJATSXMLFile();
        if ($jatsFile && $key === $jatsFile->getJatsID()) return;

        if (isset($this->galleyFiles[$key])) {
            $removeGalley = $this->galleyFiles[$key];
            // Remove dependent files first
            foreach ($this->galleyFiles as $gkey => $galley) {
                if ($galley->getGenre() === GalleyFile::$DEPENDANT_GENRE
                    && $galley->getParent() === $key) {
                    unset($this->galleyFiles[$gkey]);
                }
            }
            unset($this->galleyFiles[$key]);
        }
    }

    /**
     * Retrieve a galley file by its JATS ID.
     *
     * @param  string $jid  The JATS ID to search for.
     * @return GalleyFile|false  The matching file, or `false` if not found.
     */
    public function getGalleyFileByJatsID(string $jid): GalleyFile|false {
        foreach ($this->galleyFiles as $a) {
            if ($a->getJatsID() === $jid) return $a;
        }
        return false;
    }

    /**
     * Retrieve a galley file by its file system ID.
     *
     * @param  string $fid  The file ID to search for.
     * @return GalleyFile|false  The matching file, or `false` if not found.
     */
    public function getGalleyByFileID(string $fid): GalleyFile|false {
        foreach ($this->galleyFiles as $a) {
            if ($a->getFileID() === $fid) return $a;
        }
        return false;
    }

    /**
     * Remove the attached JATS XML file from the galley files collection.
     *
     * Unlike {@see removeGalleyFile()}, this method bypasses the protection
     * check and removes the JATS XML file directly. Used when replacing or
     * re-attaching the source XML.
     *
     * @return void
     */
    public function removeAssociatedJATSFile(): void {
        $jatsFile = $this->getJATSXMLFile();
        if ($jatsFile) unset($this->galleyFiles[$jatsFile->getJatsID()]);
    }

    /**
     * Return all non-dependent, non-cover-image galley files.
     *
     * Filters out files with genre {@see GalleyFile::$DEPENDANT_GENRE} and
     * type {@see GalleyFile::$COVER_IMAGE}, returning the remaining files
     * as a plain (non-indexed) array.
     *
     * @return GalleyFile[]
     */
    public function getNonDependantGalleyFiles(): array {
        $nonDependants = [];
        foreach ($this->galleyFiles as $galley) {
            if ($galley->getGenre() !== GalleyFile::$DEPENDANT_GENRE &&
                $galley->getType() !== GalleyFile::$COVER_IMAGE) {
                $nonDependants[] = $galley;
            }
        }
        return $nonDependants;
    }

    /**
     * Return all galley files that are neither the JATS XML nor a cover image.
     *
     * Suitable for listing supplementary files (PDF, HTML, etc.) without
     * including structural or presentation files.
     *
     * @return GalleyFile[]
     */
    public function getNonJATSGalleyFiles(): array {
        $galleys = [];
        foreach ($this->galleyFiles as $galley) {
            if ($galley->getType() !== GalleyFile::$COVER_IMAGE &&
                $galley->getType() !== GalleyFile::$JATSXML) {
                $galleys[] = $galley;
            }
        }
        return $galleys;
    }

    /**
     * Return all galley files that could serve as a parent for dependent files.
     *
     * Returns files whose type is JATS XML, generic XML, or HTML.
     *
     * @return GalleyFile[]
     */
    public function getPotentialParentGalleyFiles(): array {
        $galleys = [];
        foreach ($this->galleyFiles as $galley) {
            if ($galley->getType() === GalleyFile::$JATSXML ||
                $galley->getType() === GalleyFile::$XML     ||
                $galley->getType() === GalleyFile::$HTML) {
                $galleys[] = $galley;
            }
        }
        return $galleys;
    }

    /**
     * Return the cover image galley file if one is attached, or false.
     *
     * @return GalleyFile|false
     */
    public function getCoverImageFile(): GalleyFile|false {
        foreach ($this->galleyFiles as $galley) {
            if ($galley->getType() === GalleyFile::$COVER_IMAGE) return $galley;
        }
        return false;
    }

    /**
     * Return the attached JATS XML galley file if one exists, or false.
     *
     * @return GalleyFile|false
     */
    public function getJATSXMLFile(): GalleyFile|false {
        foreach ($this->galleyFiles as $galley) {
            if ($galley->getType() === GalleyFile::$JATSXML) return $galley;
        }
        return false;
    }


    /****************************************************************/
    /* UTILITY METHODS                                              */
    /****************************************************************/

    /**
     * Return the reference-check status of the article's reference collection.
     *
     * Delegates to {@see ReferenceCollection::getReferenceCheck()}.
     *
     * @return bool  Reference check status.
     */
    public function getReferenceCheck(): bool {
        return $this->references->getReferenceCheck();
    }

    /**
     * Set the reference-check flag on the article's reference collection.
     *
     * Delegates to {@see ReferenceCollection::setReferenceCheck()}.
     *
     * @param  bool $b  The check flag value.
     * @return void
     */
    public function setReferenceCheck(bool $b): void {
        $this->references->setReferenceCheck($b);
    }

    /** @param bool $b  Set the read-only state of this article. */
    public function setReadOnly(bool $b): void { $this->readOnly = $b; }

    /** @return bool  Whether this article is read-only. */
    public function isReadOnly(): bool { return $this->readOnly; }

    /**
     * Remove a Keyword or GalleyFile from the article by type and JATS ID.
     *
     * Looks up the item in the appropriate collection, then delegates removal
     * to {@see removeKeyword()} or {@see removeGalleyFile()} as appropriate.
     *
     * @param  string $type    Either `"Keyword"` or `"GalleyFile"`.
     * @param  string $jatsId  The JATS ID of the item to remove.
     * @return bool  `true` if the item was found and removed, `false` otherwise.
     */
    public function removeItem(string $type, string $jatsId): bool {
        $items = null;
        if ($type === "Keyword")          $items = $this->getKeywords();
        elseif ($type === "GalleyFile")   $items = $this->getGalleyFiles();

        if (isset($items)) {
            foreach ($items as $key => $item) {
                if ($item->getJatsID() === $jatsId) {
                    if ($type === "Keyword")         $this->removeKeyword($key);
                    elseif ($type === "GalleyFile")  $this->removeGalleyFile($key);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Add an item to the article from a POST data array.
     *
     * Currently only supports `"Keyword"` as the item type, delegating to
     * {@see setKeyword()} with the `name` value from the post array.
     *
     * @param  string $type  The type of item to add (currently only `"Keyword"`).
     * @param  array  $post  Associative POST array; must contain `name` for keywords.
     * @return bool  Always returns `true`.
     */
    public function addItem(string $type, array $post): bool {
        if ($type === "Keyword") {
            $this->setKeyword($post['name']);
        }
        return true;
    }
}
?>