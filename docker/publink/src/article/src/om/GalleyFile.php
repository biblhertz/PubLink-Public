<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\ArticleObject;
use Biblhertz\Publink\om\File;

/**
 * GalleyFile
 *
 * Represents a single galley file (publication-ready output file) attached
 * to an {@see Article} within the PubLink/OJS publishing system.
 * Extends {@see ArticleObject}.
 *
 * A galley file may be a primary publication format (PDF, HTML, XML), a
 * supporting image, the source JATS XML, a cover image, or a dependent
 * resource linked to a parent galley (e.g. an image embedded in an HTML galley).
 *
 * File types are identified by the integer constants defined on this class
 * (e.g. {@see $PDF}, {@see $JATSXML}). Genres are string identifiers used in
 * OJS XML export (e.g. `"Article Text"`, `"Cover Image"`, `"Dependant file"`).
 *
 * Key behaviours:
 * - A unique JATS ID is assigned automatically on construction via `uniqid()`.
 * - Base64 encoding is computed lazily from the file path via
 *   {@see setBase64Encoding()} or {@see getGalleyFileAsBase64()}.
 * - File size is set by calling {@see setGalleyFileSize()}, which reads from
 *   the file system at the path stored in `$galleyFilePath`.
 * - Dependent files reference their parent galley via the parent JATS ID
 *   stored in `$parent`.
 * - The static factory method {@see getGalleyFile()} constructs a fully
 *   populated GalleyFile from a {@see File} object, inferring type and alt
 *   text from the file extension.
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class GalleyFile extends ArticleObject {

    /****************************************************************/
    /* STATIC VARIABLES — FILE TYPE CONSTANTS                       */
    /****************************************************************/

    /** @var int File type constant: generic XML file. */
    public static int $XML = 1;

    /** @var int File type constant: PDF file. */
    public static int $PDF = 2;

    /** @var int File type constant: HTML file. */
    public static int $HTML = 3;

    /** @var int File type constant: cover image displayed with the article. */
    public static int $COVER_IMAGE = 4;

    /** @var int File type constant: generic image file (PNG, JPG, GIF, etc.). */
    public static int $IMAGE = 5;

    /** @var int File type constant: source JATS XML file for this article. */
    public static int $JATSXML = 6;

    /**
     * Whether this object may be edited via the GUI.
     *
     * @var bool
     */
    public static bool $ALLOW_EDIT = true;

    /**
     * List of valid OJS genre strings for galley files.
     * Used to populate genre selection dropdowns in the GUI.
     *
     * @var string[]
     */
    private static array $allowedGenres = [
        "Article Text",
        "Manuscript",
        "Figure",
        "Supplementary file (for review)",
        "Supplementary file (not for review)",
        "Dependant file",
        "Response to Reviewers",
        "Cover Image",
    ];

    /**
     * Genre string identifying a file as dependent on a parent galley.
     * Dependent files (e.g. images within an HTML galley) are linked to their
     * parent via the `$parent` JATS ID property.
     *
     * @var string
     */
    public static string $DEPENDANT_GENRE = "Dependant file";

    /**
     * Genre string identifying a file as a cover image.
     *
     * @var string
     */
    public static string $COVER_GENRE = "Cover Image";


    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * Absolute file system path to the galley file.
     * Used as the source for base64 encoding and file size calculations.
     *
     * @var string
     */
    private string $galleyFilePath = "";

    /**
     * Alt text for this galley file (used in OJS XML export and HTML display).
     *
     * @var string
     */
    private string $galleyFileAltText = "";

    /**
     * File type integer, one of the type constants defined on this class
     * (e.g. {@see $PDF}, {@see $HTML}, {@see $JATSXML}).
     *
     * @var int
     */
    private int $galleyFileType = 0;

    /**
     * Submission reference ID as used in OJS XML export.
     * Set via {@see setID()} from the OJS submission record.
     *
     * @var int
     */
    private int $id = 0;

    /**
     * OJS genre string for this file (e.g. `"Article Text"`, `"Cover Image"`).
     * Must be one of the values in {@see $allowedGenres}.
     * Defaults to `"Article Text"`.
     *
     * @var string
     */
    private string $genre = "Article Text";

    /**
     * Base64-encoded content of the galley file.
     * Populated by {@see setBase64Encoding()} or read on-demand via
     * {@see getGalleyFileAsBase64()}.
     *
     * @var string
     */
    private string $base64encoding = "";

    /**
     * File size in bytes, populated by {@see setGalleyFileSize()} from the
     * file system. Required for OJS XML export.
     *
     * @var int
     */
    private int $size = 0;

    /**
     * File ID from the underlying file storage system.
     * Used to link this galley back to a {@see File} record.
     *
     * @var int
     */
    private int $fileID = 0;

    /** @var string Display name for this galley file. */
    private string $name = "";

    /**
     * Locale code for this galley file (e.g. `"en"`, `"de"`).
     * Used during OJS XML export. Defaults to `"en"`.
     *
     * @var string
     */
    private string $locale = "en";

    /**
     * JATS ID of the parent galley file for dependent files.
     * Only set when `$genre` is {@see $DEPENDANT_GENRE}.
     *
     * @var string
     */
    private string $parent = "";

    /**
     * Reference to the {@see Article} that owns this galley file.
     *
     * @var Article
     */
    private Article $article;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a GalleyFile with a unique JATS ID and a list of fields
     * excluded from GUI field-selection interfaces.
     */
    public function __construct() {
        $this->setJatsID(uniqid());
        $this->disallowedFields = ["base64encoding", "galleyFileType", "size", "fileID"];
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the OJS submission reference ID.
     *
     * @param  int $id  OJS submission ID.
     * @return void
     */
    public function setID(int $id): void {
        $this->id = $id;
    }

    /**
     * Get the OJS submission reference ID.
     *
     * @return int
     */
    public function getID(): int {
        return $this->id;
    }

    /**
     * Set the parent article for this galley file.
     *
     * @param  Article $id  The owning article.
     * @return void
     */
    public function setArticle(Article $id): void {
        $this->article = $id;
    }

    /**
     * Get the parent article for this galley file.
     *
     * @return Article
     */
    public function getArticle(): Article {
        return $this->article;
    }

    /**
     * Set the JATS ID of the parent galley file.
     * Should only be set when this file's genre is {@see $DEPENDANT_GENRE}.
     *
     * @param  string $id  JATS ID of the parent GalleyFile.
     * @return void
     */
    public function setParent(string $id): void {
        $this->parent = $id;
    }

    /**
     * Get the JATS ID of the parent galley file.
     * Returns an empty string if this is not a dependent file.
     *
     * @return string
     */
    public function getParent(): string {
        return $this->parent;
    }

    /**
     * Set the display name for this galley file.
     *
     * @param  string $id  File display name.
     * @return void
     */
    public function setName(string $id): void {
        $this->name = $id;
    }

    /**
     * Get the display name for this galley file.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Set the file storage ID.
     *
     * @param  int $id  File ID from the file storage system.
     * @return void
     */
    public function setFileID(int $id): void {
        $this->fileID = $id;
    }

    /**
     * Get the file storage ID.
     *
     * @return int
     */
    public function getFileID(): int {
        return $this->fileID;
    }

    /**
     * Set the file type using one of the integer type constants on this class
     * (e.g. `GalleyFile::$PDF`, `GalleyFile::$HTML`).
     *
     * @param  int $type  File type constant.
     * @return void
     */
    public function setType(int $type): void {
        $this->galleyFileType = $type;
    }

    /**
     * Get the file type constant for this galley.
     *
     * @return int  One of the integer type constants defined on this class.
     */
    public function getType(): int {
        return $this->galleyFileType;
    }

    /**
     * Set the OJS genre string for this galley file.
     * Should be one of the values in {@see getAllowedGenres()}.
     *
     * @param  string $genre  Genre string (e.g. `"Article Text"`, `"Cover Image"`).
     * @return void
     */
    public function setGenre(string $genre): void {
        $this->genre = $genre;
    }

    /**
     * Get the OJS genre string for this galley file.
     *
     * @return string
     */
    public function getGenre(): string {
        return $this->genre;
    }

    /**
     * Return the list of valid OJS genre strings.
     *
     * @return string[]
     */
    public static function getAllowedGenres(): array {
        return self::$allowedGenres;
    }

    /**
     * Read a file from disk and store its contents as a base64-encoded string.
     *
     * @param  string $path  Absolute path to the file to encode.
     * @return void
     */
    public function setBase64Encoding(string $path): void {
        $data = file_get_contents($path);
        if ($data !== false) {
            $this->base64encoding = base64_encode($data);
        }
    }

    /**
     * Get the pre-computed base64-encoded content of this file.
     * Must be populated first by calling {@see setBase64Encoding()}.
     *
     * @return string  Base64-encoded file content.
     */
    public function getBase64Encoding(): string {
        return $this->base64encoding;
    }

    /**
     * Set the absolute file system path for this galley file.
     *
     * @param  string $path  Absolute file path.
     * @return void
     */
    public function setGalleyFilePath(string $path): void {
        $this->galleyFilePath = $path;
    }

    /**
     * Get the absolute file system path for this galley file.
     *
     * @return string
     */
    public function getGalleyFilePath(): string {
        return $this->galleyFilePath;
    }

    /**
     * Set the alt text for this galley file.
     *
     * @param  string $text  Alt text string.
     * @return void
     */
    public function setGalleyFileAltText(string $text): void {
        $this->galleyFileAltText = $text;
    }

    /**
     * Get the alt text for this galley file.
     *
     * @return string
     */
    public function getGalleyFileAltText(): string {
        return $this->galleyFileAltText;
    }

    /**
     * Get the stored file size in bytes.
     * Must be populated first by calling {@see setGalleyFileSize()}.
     *
     * @return int  File size in bytes.
     */
    public function getGalleyFileSize(): int {
        return $this->size;
    }

    /**
     * Read the file size from disk and store it.
     * Requires {@see $galleyFilePath} to be set.
     *
     * @return void
     */
    public function setGalleyFileSize(): void {
        $size = filesize($this->getGalleyFilePath());
        if ($size !== false) {
            $this->size = $size;
        }
    }

    /**
     * Get the base filename (including extension) from the stored file path.
     *
     * @return string  Filename (e.g. `"article.pdf"`).
     */
    public function getGalleyFileName(): string {
        return pathinfo($this->getGalleyFilePath(), PATHINFO_BASENAME);
    }

    /**
     * Get the file extension from the stored file path.
     *
     * @return string  Lowercase extension without leading dot (e.g. `"pdf"`, `"xml"`).
     */
    public function getGalleyFileType(): string {
        return pathinfo($this->getGalleyFilePath(), PATHINFO_EXTENSION);
    }

    /**
     * Read the file from disk and return its contents as a base64-encoded string.
     *
     * Unlike {@see getBase64Encoding()}, this method reads from disk on every
     * call rather than returning a cached value.
     *
     * @return string  Base64-encoded file content.
     */
    public function getGalleyFileAsBase64(): string {
        $data = file_get_contents($this->getGalleyFilePath());
        if ($data === false) return "";
        return base64_encode($data);
    }

    /**
     * Infer and set the file type constant from the stored file extension.
     *
     * Maps `pdf` → {@see $PDF}, `xml` → {@see $XML}, `html` → {@see $HTML}.
     * Unrecognised extensions are not handled; the type remains unchanged.
     *
     * @return void
     */
    public function setTypeFromFileType(): void {
        $type = $this->getGalleyFileType();
        switch ($type) {
            case "pdf":
                $this->galleyFileType = GalleyFile::$PDF;
				break;
            case "xml":
                $this->galleyFileType = GalleyFile::$XML;
				break;
            case "html":
                $this->galleyFileType = GalleyFile::$HTML;
				break;
			default:
				break;
        }
    }

    /**
     * Set the locale code for this galley file.
     *
     * @param  string $s  Locale code (e.g. `"en"`, `"de"`).
     * @return void
     */
    public function setLocale(string $s): void {
        $this->locale = $s;
    }

    /**
     * Get the locale code for this galley file.
     *
     * @return string
     */
    public function getLocale(): string {
        return $this->locale;
    }


    /****************************************************************/
    /* STATIC METHODS                                               */
    /****************************************************************/

    /**
     * Factory method: construct a GalleyFile from a {@see File} object.
     *
     * Populates the galley with path, base64 encoding, file size, file ID,
     * JATS ID, and display name from the file. The file type and alt text
     * are inferred from the file extension:
     *
     * | Extension             | Type                  | Alt text                        |
     * |-----------------------|-----------------------|---------------------------------|
     * | `pdf`                 | {@see $PDF}           | `"PDF Galley File for..."`      |
     * | `html`                | {@see $HTML}          | `"HTML Galley File for..."`     |
     * | `xml`                 | {@see $XML}           | `"XML Galley File for..."`      |
     * | `png`, `jpg`, `jpeg`, `gif` | {@see $IMAGE} | `"Image Galley File for..."`    |
     * | other                 | {@see $HTML}          | `"Galley File for..."`          |
     *
     * The OJS submission ID is set to a random integer (1–1000) as a placeholder.
     *
     * @param  File   $file  The source file to create the galley from.
     * @return GalleyFile    A fully populated GalleyFile instance.
     */
    public static function getGalleyFile(File $file): GalleyFile {
        $info = pathinfo($file->getPath());

        $galley = new GalleyFile();
        $galley->setGalleyFilePath($file->getPath());
        $galley->setID(rand(1, 1000));
        $galley->setBase64Encoding($file->getPath());
        $galley->setGalleyFileSize();
        $galley->setFileID($file->getID());
        $galley->setJatsID(uniqid());
        $galley->setName($info['basename']);

        switch ($info['extension']) {
            case "pdf":
                $galley->setGalleyFileAltText("PDF Galley File for this article");
                $galley->setType(GalleyFile::$PDF);
                break;

            case "html":
                $galley->setGalleyFileAltText("HTML Galley File for this article");
                $galley->setType(GalleyFile::$HTML);
                break;

            case "xml":
                $galley->setGalleyFileAltText("XML Galley File for this article");
                $galley->setType(GalleyFile::$XML);
                break;

            case "png":
            case "jpg":
            case "jpeg":
            case "gif":
                $galley->setType(GalleyFile::$IMAGE);
                $galley->setGalleyFileAltText("Image Galley File for this article");
                break;

            default:
                $galley->setType(GalleyFile::$HTML);
                $galley->setGalleyFileAltText("Galley File for this article");
                break;
        }

        return $galley;
    }

    /**
     * Update this galley file's editable fields from a POST data array.
     *
     * Updates alt text, display name, genre, and locale from the corresponding
     * POST keys. Also handles the bidirectional `COVER_IMAGE` ↔ `IMAGE` type
     * transition:
     * - If the genre is set to `"Cover Image"` and the current type is
     *   {@see $IMAGE}, the type is promoted to {@see $COVER_IMAGE}.
     * - If the type is currently {@see $COVER_IMAGE} and the genre is changed
     *   away from `"Cover Image"`, the type is demoted back to {@see $IMAGE}.
     *
     * @param  array $post  Associative POST array with keys: `alt_text`, `name`,
     *                      `genre` (optional), `locale` (optional).
     * @return void
     */
    public function updateGalley(array $post): void {
        $this->setGalleyFileAltText($post['alt_text']);
        $this->setName($post['name']);
        if (!empty($post['genre']))  $this->setGenre($post['genre']);
        if (!empty($post['locale'])) $this->setLocale($post['locale']);

        // Promote IMAGE → COVER_IMAGE when genre is set to Cover Image
        if (isset($post['genre']) && $post['genre'] === "Cover Image" &&
            $this->galleyFileType === self::$IMAGE) {
            $this->setType(self::$COVER_IMAGE);
        }

        // Demote COVER_IMAGE → IMAGE when genre is changed away from Cover Image
        if ($this->galleyFileType === self::$COVER_IMAGE &&
            isset($post['genre']) && $post['genre'] !== "Cover Image") {
            $this->setType(self::$IMAGE);
        }
    }
}
?>