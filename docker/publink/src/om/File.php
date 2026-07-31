<?php
namespace Biblhertz\Publink\om;

use Biblhertz\Publink\om\BHObject;
use Biblhertz\Article\adapters\JATSXMLValidator;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Publink\utilities\XMLValidator;
use Biblhertz\Publink\utilities\PDODatabase;
use Exception;
use Biblhertz\Publink\Config;


/**
 * File
 *
 * Represents a file record stored in the PubLink system, combining database
 * metadata with filesystem operations. Handles file upload, download, thumbnail
 * generation, XML type detection, and user-based access control.
 *
 * Each File instance maps to a row in the `file` table and references a
 * corresponding entry in `file_type`. Physical files are stored on disk at the
 * path recorded in the database.
 *
 * @package  Biblhertz\Publink\om
 * @author   Chris Tomlinson
 * @since    June 2023
 */
class File extends BHObject {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var string MIME type of the file (e.g. 'application/pdf', 'image/jpeg') */
    private string $type;

    /** @var int File size in bytes */
    private int $size;

    /** @var string Raw file contents, populated lazily on download */
    private string $content;

    /** @var string Absolute filesystem path to the file */
    private string $path;

    /** @var string Creation/upload timestamp (Y-m-d H:i:s) */
    private string $timestamp;

    /** @var int Foreign key referencing the owning user in `user_details` */
    private int $user_details_id;

    /** @var string Human-readable file extension label from `file_type.name` (e.g. 'JATS xml', 'pdf') */
    private string $fileExtension;

    /** @var mixed Base64-encoded thumbnail data URI for the file type icon, or null if unavailable */
    private mixed $icon;

    /** @var string Absolute filesystem path to the generated thumbnail image */
    protected string $thumbNailPath;


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs a File object, optionally hydrating it from the database.
     *
     * When an $id is supplied the constructor fetches the corresponding row from
     * `file` and performs two additional look-ups against `file_type` to resolve
     * the MIME type, icon, and extension label. If no $id is given an empty
     * shell object is returned, suitable for use with static factory methods.
     *
     * @param PDODatabase $objDB Active database connection wrapper
     * @param int|null    $id    Primary key of the `file` row to load, or null
     */
    public function __construct(PDODatabase $objDB, int $id = null) {
        $this->tableName = "file";
        $this->objDB     = $objDB;

        if (isset($id)) {
            $this->id = $id;
            $item = $this->fetchItem($id);         // Inherited BHObject helper

            if (!isset($item)) {
                throw new Exception("File not found: no file record exists with ID $id");
            }

            $this->name      = $item['name'];
            $this->size      = $item['size'];
            $this->timestamp = $item['timestamp'];
            $this->path      = $item['path'];
            $this->thumbNailPath    = $item['thumbnail_path'];
            $this->user_details_id  = $item['user_details_id'];

            // Resolve file_type fields with separate queries to keep concerns clear
            $this->type          = $objDB->preparedGetOne("select type from file_type where id = ?",      [$item['file_type_id']]);
            $this->icon          = $objDB->preparedGetOne("select thumbnail from file_type where id = ?", [$item['file_type_id']]);
            $this->fileExtension = $objDB->preparedGetOne("select name from file_type where id = ?",      [$item['file_type_id']]);
        }
    }


    /****************************************************************/
    /*  INTERFACE / ACCESSOR METHODS                                */
    /****************************************************************/

    /**
     * Returns the ID of the user who owns this file.
     *
     * @return int User ID from `user_details`
     */
    public function getUserID(): int {
        return $this->user_details_id;
    }

    /**
     * Returns the MIME type string for this file.
     *
     * @return string e.g. 'image/jpeg', 'application/pdf'
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * Returns the file-type icon thumbnail (base64 data URI) or null.
     *
     * @return mixed Base64 data URI string, or null if no icon is registered
     */
    public function getIcon(): mixed {
        return $this->icon;
    }

    /**
     * Returns the file size in bytes.
     *
     * @return int
     */
    public function getSize(): int {
        return $this->size;
    }

    /**
     * Returns the absolute filesystem path to this file.
     *
     * @return string
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * Returns the directory portion of the file's path.
     *
     * Uses PHP's pathinfo() to strip the filename, providing the containing
     * directory without a trailing separator.
     *
     * @return string Absolute directory path
     */
    public function getDirectory(): string {
        return pathinfo($this->path, PATHINFO_DIRNAME);
    }

    /**
     * Returns the absolute path to the generated thumbnail image.
     *
     * @return string
     */
    public function getThumbNailPath(): string {
        return $this->thumbNailPath;
    }

    /**
     * Overrides the thumbnail path (e.g. after regenerating the thumbnail).
     *
     * @param string $path New absolute path to the thumbnail file
     */
    public function setThumbNailPath(string $path): void {
        $this->thumbNailPath = $path;
    }

    /**
     * Returns the upload/creation timestamp.
     *
     * @return string Formatted as 'Y-m-d H:i:s'
     */
    public function getTimeStamp(): string {
        return $this->timestamp;
    }

    /**
     * Returns the human-readable extension label stored in `file_type.name`.
     *
     * Note: this may differ from the raw filesystem extension. For example a
     * validated JATS document returns 'JATS xml' rather than 'xml'.
     *
     * @return string
     */
    public function getFileExtension(): string {
        return $this->fileExtension;
    }

    /**
     * Returns the filename without its extension.
     *
     * Strips the directory component and extension from $this->path using
     * pathinfo(), giving just the bare filename stem.
     *
     * @return string e.g. 'my-article' for '/store/user1/my-article.xml'
     */
    public function getFileNameWithoutExtension(): string {
        return pathinfo(basename($this->path), PATHINFO_FILENAME);
    }

    /**
     * Returns true if this file has been identified as a JATS XML document.
     *
     * Performs a case-sensitive comparison against the 'JATS xml' type label
     * assigned during upload validation.
     *
     * @return bool
     */
    public function isJATS(): bool {
        return strcmp($this->fileExtension, "JATS xml") === 0;
    }

    /**
     * Returns true if this file is a BibTeX bibliography file.
     *
     * @return bool
     */
    public function isBibTex(): bool {
        return strcmp($this->fileExtension, "bib") === 0;
    }

    /**
     * Returns true if this file is a JSON file.
     *
     * @return bool
     */
    public function isJSON(): bool {
        return strcmp($this->fileExtension, "json") === 0;
    }


    /****************************************************************/
    /*  SECURITY / ACCESS-CONTROL METHODS                           */
    /****************************************************************/

    /**
     * Determines whether a given user may delete this file.
     *
     * Deletion is permitted only when the requesting user owns the file AND no
     * serialized object references it (i.e. it has not been promoted to an
     * article/publication object within PubLink).
     *
     * @param int $id User ID to check
     * @return bool   True if deletion is permitted
     */
    public function canDelete(int $id): bool {
        if ($id === $this->user_details_id) {
            // Prevent deletion if a serialized_object row links to this file
            if (!$this->hasLinkedObject()) return true;
        }
        return false;
    }

    /**
     * Checks whether this file is referenced by a serialized object record.
     *
     * A "linked object" is a row in `serialized_object` where file_id matches
     * this file's ID, indicating the file has been parsed and promoted to a
     * PubLink content object (e.g. a JATS article). Linked files are protected
     * from deletion to preserve data integrity.
     *
     * @return bool True if a linked serialized_object exists
     */
    public function hasLinkedObject(): bool {
        $hasObject = $this->getObjDB()->preparedGetOne(
            "select id from serialized_object where file_id = ?",
            [$this->id]
        );
        return isset($hasObject) && is_numeric($hasObject) && $hasObject > 0;
    }

    /**
     * Determines whether a given user may edit this file's metadata.
     *
     * Currently restricted to the file owner only.
     *
     * @param int $id User ID to check
     * @return bool
     */
    public function canEdit(int $id): bool {
        return $id === $this->user_details_id;
    }

    /**
     * Determines whether a given user may view/download this file.
     *
     * Currently restricted to the file owner only.
     *
     * @param int $id User ID to check
     * @return bool
     */
    public function canView(int $id): bool {
        return $id === $this->user_details_id;
    }

    /**
     * Determines whether a given user may execute this file.
     *
     * Currently restricted to the file owner only. Execution rights are
     * relevant for script-type files processed server-side.
     *
     * @param int $id User ID to check
     * @return bool
     */
    public function canExecute(int $id): bool {
        return $id === $this->user_details_id;
    }


    /****************************************************************/
    /*  INHERITED METHODS (BHObject overrides)                      */
    /****************************************************************/

    /**
     * Returns a minimal Bootstrap-styled HTML table representation of this file.
     *
     * Implements the abstract getAsTable() contract from BHObject.
     *
     * @return string HTML table string
     */
    public function getAsTable(): string {
        return "<table class=\"table\"><tr><th>ID</th><th>" . $this->getName() . "</th></tr></table>";
    }


    /****************************************************************/
    /*  DOWNLOAD METHODS                                            */
    /****************************************************************/

    /**
     * Streams the file to the browser as a forced download.
     *
     * Verifies that the requesting user has view rights, then reads the file
     * from disk and sends appropriate HTTP headers to trigger a Save-As dialog.
     * Terminates execution after output via exit.
     *
     * @param int $uid ID of the user requesting the download
     * @throws Exception If the user lacks view rights, the file is missing on
     *                   disk, or content has already been loaded into the object
     */
    public function downLoadFile(int $uid): void {
        if (!$this->canView($uid))
            throw new Exception("!!! You cannot view that file, you do not have the correct user rights<hr/>");

        if (!file_exists($this->path)) {
            http_response_code(404);
            echo "<p><strong>File not found:</strong> <em>" . htmlspecialchars($this->name) . "</em> does not exist in the file store.</p>";
            exit;
        }

        // Guard: content should not already be buffered in this object
        if (!isset($this->content)) {
            $this->content = file_get_contents($this->path);

            // Send headers to force browser download rather than inline display
            header("Pragma: ");
            header("Cache-Control: public");
            header("Content-Description: File Transfer");
            header("Content-length: " . $this->size);
            header("Content-type: " . $this->type);
            header("Content-Disposition: attachment; filename=\"" . rawurlencode($this->name) . "\"");
            echo $this->content;
        } else {
            throw new Exception("!!! Error content detected in object for file<br/><br/><b>" . $this->name . "</b><hr/>");
        }

        exit;
    }

    /**
     * Streams an image file to the browser as a binary download.
     *
     * Similar to downLoadFile() but uses readfile() for memory-efficient
     * streaming and forces octet-stream content type.
     *
     * @param int $uid ID of the user requesting the download
     * @throws Exception If the user lacks view rights or the file is missing
     *
     */
    public function downloadImage(int $uid): void {
        if (!$this->canView($uid))
            throw new Exception("!!! You cannot view that file, you do not have the correct user rights<hr/>");

        if (!file_exists($this->path)) {
            http_response_code(404);
            echo "<p><strong>File not found:</strong> <em>" . htmlspecialchars($this->name) . "</em> does not exist in the file store.</p>";
            exit;
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($this->path));   
        flush();
        readfile($this->path);                                
        exit;
    }


    /****************************************************************/
    /*  FILE STORE METHODS                                          */
    /****************************************************************/

    /**
     * Scans a directory and imports every file found into a user's file store.
     *
     * Moves each file from $dir into the user's designated storage directory,
     * skipping any file whose normalised basename already exists in the user's
     * account. After the move a `file` record is inserted, and a thumbnail is
     * generated for image-type files.
     *
     * @param string $dir  Absolute path of the source directory to scan
     * @param User   $user Owner of the file store receiving the files
     * @throws Exception   If a file's extension is not registered in `file_type`
     *
     */
    public function scanAndSave(string $dir, User $user): void {
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;

            $baseName = File::getBaseName($item);
            $path     = $user->getMyFileStoreDirectory() . $baseName;

            // Skip if a file with this name already exists for the user
            $exists = $this->objDB->preparedGetOne(
                "select id from file where name = ? and user_details_id = ?",
                [$baseName, $user->getID()]
            );
            if (is_numeric($exists) && $exists > 0) continue;

            $filename = $dir . DIRECTORY_SEPARATOR . $item;

            if (rename($filename, $path)) {
                $vals = [];
                $vals['name']            = $baseName;
                $vals['type']            = filetype($path);
                $vals['size']            = filesize($path);
                $vals['timestamp']       = date("Y-m-d H:i:s");
                $vals['user_details_id'] = $user->getID();
                $vals['path']            = $path;

                // Look up the registered file_type for this extension
                $type = $this->objDB->preparedSelect(          
                    "select id, type from file_type where name = ?",
                    [File::getFileExtensionFromBaseName($baseName)]
                );
                $type = $type->fetch();

                if ($this->objDB->numRows() != 1)
                    throw new Exception("!!! File type is not recognised by the system");

                $vals['file_type_id'] = $type['id'];

                // Generate a thumbnail for image files
                if (!strcmp($type['type'], "image")) {
                    $vals['thumbnail_path'] = File::makeThumbNailImage($path);
                }

                $id = $this->objDB->insert("file", $vals);
            }
        }
    }


    /****************************************************************/
    /*  DELETE METHODS — Use with caution                           */
    /****************************************************************/

    /**
     * Deletes this file from both the database and the filesystem.
     *
     * Checks canDelete() before proceeding. Removes the `file` row first so
     * any foreign-key constraint on `serialized_object.file_id` will cause an
     * early failure rather than leaving an orphaned disk file. The thumbnail is
     * also removed if it exists.
     *
     * @param int $uid ID of the user requesting deletion
     */
    public function deleteFile(int $uid): void {
        if ($this->canDelete($uid)) {
            // Remove database record first; FK constraint will abort if file is still linked
            $this->objDB->preparedStatement("delete from file where id = ?", [$this->id]);

            // Remove the main file from disk
            $realpath = realpath($this->path);
            if (file_exists($realpath)) unlink($realpath);

            // Remove the thumbnail if one exists
            if (isset($this->thumbNailPath) && file_exists($this->thumbNailPath)) {
                $realpath = realpath($this->thumbNailPath);
                if (file_exists($realpath)) unlink($realpath);
            }
        }
    }

    /**
     * Recursively deletes a directory and all of its contents.
     *
     * Traverses the directory tree depth-first, unlinking files and removing
     * sub-directories before finally removing the root directory itself.
     *
     * !! Use with extreme caution — this operation is irreversible.
     *
     * @param string $dir Absolute path to the directory to remove
     * @return bool       True on success, false if any deletion fails
     */
    public static function deleteDirectory(string $dir): bool {
        if (!file_exists($dir)) return true;
        if (!is_dir($dir))      return unlink($dir);    // It's a file, not a directory

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;

            // Recurse; bail immediately on any failure
            if (!File::deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }


    /****************************************************************/
    /*  STATIC FACTORY / UTILITY METHODS                            */
    /****************************************************************/

    /**
     * Handles an HTTP file upload and persists it to a user's file store.
     *
     * Normalises the filename, moves the temporary upload to the user's storage
     * directory, determines the file type (with special handling for XML files),
     * inserts a `file` record, and generates a thumbnail for images.
     *
     * @param PDODatabase $objDB      Active database connection
     * @param string      $uploadName Key in $_FILES for the uploaded file
     * @param User        $user       Owner of the destination file store
     * @return int                    New `file` primary key on success
     * @return false                  If move_uploaded_file() fails
     * @throws Exception              If a file with the same name already exists,
     *                                or if the extension is not in `file_type`
     */
    public static function saveAttachedFile(PDODatabase $objDB, string $uploadName, User $user): mixed {
        $baseName  = File::getBaseName($_FILES[$uploadName]['name']);
        $path      = $user->getMyFileStoreDirectory() . $baseName;
        $extension = File::getFileExtensionFromBaseName($baseName);

        // Reject duplicate filenames within the same user account
        $exists = $objDB->preparedGetOne(
            "select id from file where name = ? and user_details_id = ?",
            [$baseName, $user->getID()]
        );
        if (is_numeric($exists) && $exists > 0)
            throw new Exception("!!! A file with that name already exists in your account, please change the name if you want to upload it.");

        if (move_uploaded_file($_FILES[$uploadName]['tmp_name'], $path)) {
            $vals = [];
            $vals['name']            = $baseName;
            $vals['type']            = $_FILES[$uploadName]['type'];
            $vals['size']            = $_FILES[$uploadName]['size'];
            $vals['timestamp']       = date("Y-m-d H:i:s");
            $vals['user_details_id'] = $user->getID();
            $vals['path']            = $path;

            // XML files need deeper inspection to identify their schema (JATS, OJS, etc.)
            if (!strcmp($extension, "xml")) {
                $extension = File::validateXMLAgainstKnownTypes($path, $extension, $user);
            }

            // Look up the resolved extension in the file_type registry
            $type = $objDB->preparedSelect(
                "select id, type from file_type where name = ?",
                [$extension]
            );
            $type = $type->fetch();

            if ($objDB->numRows() != 1)
                throw new Exception("!!! File type is not recognised by the system");

            $vals['file_type_id'] = $type['id'];

            // Generate a thumbnail only for image-type files
            if (!strcmp($type['type'], "image")) {
                $vals['thumbnail_path'] = File::makeThumbNailImage($objDB, $path);
            }

            $id = $objDB->insert("file", $vals);
            return $id;
        }

        return false;
    }

    /**
     * Validates an XML file against known PubLink XML schemas.
     *
     * Tries each known schema in order of priority (JATS, then OJS) and returns
     * a specific type label if validation passes. Falls back to the original
     * $extension string if no known schema matches. Writes a log entry
     * regardless of outcome.
     *
     * @param string $path      Absolute filesystem path to the XML file
     * @param string $extension Fallback extension label if no schema matches
     * @param User   $user      User performing the validation (for logging)
     * @return string           'JATS xml', 'OJS xml', or the original $extension
     */
    private static function validationLogPath(string $prefix): string {
        $dt = new \DateTime('now');
        return Config::$JOB_LOG_DIR . DIRECTORY_SEPARATOR
            . $prefix . '_' . $dt->format('d-m-Y_H_i_s') . '_' . uniqid() . '.log';
    }

    public static function validateXMLAgainstKnownTypes(string $path, string $extension, User $user): string {
        $logger = new Logger();

        // --- JATS validation ---
        $jatsValidator = new JATSXMLValidator($logger);
        //$jatsValidator->setLogger($logger);
        $isJats = $jatsValidator->validateJATSXML($path);

        if ($isJats) {
            $logger->writeOutUserLogFile("xmlValidate", $user);
            $logger->writeOutLogFile(self::validationLogPath("jats_validate"));
            return "JATS xml";
        }

        // --- OJS/OMP native XML validation ---
        $ojsValidator = new XMLValidator();
        $ojsValidator->setLogger($logger);

        $isOJS=false;
        $ojsValidator->setTargetPath($path);
        foreach(Config::$OJS_XSD as $ojsxsd){
            $ojsValidator->setXSDPath($ojsxsd);
            $isOJS = $ojsValidator->validateXML();
            if($isOJS)break;
        }

        $logger->writeOutUserLogFile("xmlValidate", $user);
        if ($isOJS) {
            $logger->writeOutLogFile(self::validationLogPath("ojs_validate"));
            return "OJS xml";
        }

        $logger->writeOutLogFile(self::validationLogPath("xml_validate"));
        return $extension;
    }

    /**
     * Creates a proportionally scaled thumbnail image from a source image file.
     *
     * Reads the source image using the appropriate GD factory function based on
     * extension, scales it to $desired_height while preserving aspect ratio, and
     * saves the result alongside the original with a 'thumb_' prefix. The
     * thumbnail is then base64-encoded as a data URI and written back to the
     * thumbnail file.
     *
     * Supported input formats: jpg/jpeg/jp2, png, gif, tif/tiff.
     *
     * @param PDODatabase $objDB          Database connection (used to verify the
     *                                    extension is a registered image type)
     * @param string      $path           Absolute path to the source image
     * @param int         $desired_height Target thumbnail height in pixels (default 150)
     * @return string                     Absolute path to the generated thumbnail
     * @throws Exception                  If the extension is unregistered, the image
     *                                    cannot be read, or the format is unsupported
     *                                    for output
     *
     */
    public static function makeThumbNailImage(PDODatabase $objDB, string $path, int $desired_height = 150): string {
        $path_parts = pathinfo($path);
        $path_parts['extension'] = strtolower($path_parts['extension']);

        // Confirm the extension is registered as an image type in file_type
        $exists = $objDB->preparedGetOne(
            "select id from file_type where name = ? and type = 'image'",
            [$path_parts['extension']]
        );
        if (!isset($exists) || !is_numeric($exists) || $exists < 1)
            throw new Exception("!!! Thumbnail Generation Module :: Image type not found :: " . $path . " :: " . $path_parts['extension']);

        // Build destination path: same directory, 'thumb_' prefix
        $dest = $path_parts['dirname'] . DIRECTORY_SEPARATOR . "thumb_" . $path_parts['basename'];

        // TIFF files are handled via Imagick as GD has no native TIFF support
        if (!strcmp($path_parts['extension'], "tif") || !strcmp($path_parts['extension'], "tiff")) {
            // Override $dest to use .jpg — writeImage() uses the file extension to determine
            // output format, so the path must reflect the intended format explicitly
            $dest = $path_parts['dirname'] . DIRECTORY_SEPARATOR . "thumb_" . $path_parts['filename'] . ".jpg";
            $imagick = new \Imagick($path);
            $imagick->setIteratorIndex(0);
            $imagick->thumbnailImage(0, $desired_height);
            $imagick->writeImage($dest);
            $imagick->destroy();
        } else {
            // Load source image with the appropriate GD factory
            if (!strcmp($path_parts['extension'], "png"))
                $source_image = imagecreatefrompng($path);
            elseif (!strcmp($path_parts['extension'], "gif"))
                $source_image = imagecreatefromgif($path);
            else
                $source_image = imagecreatefromjpeg($path);

            $width  = imagesx($source_image);
            $height = imagesy($source_image);

            // Calculate the proportional width for the desired height
            $desired_width = (int) floor($width * ($desired_height / $height));

            // Create a new true-colour canvas at the target dimensions
            $virtual_image = imagecreatetruecolor($desired_width, $desired_height);

            // Resample (higher quality than imagecopyresized)
            imagecopyresampled($virtual_image, $source_image, 0, 0, 0, 0, $desired_width, $desired_height, $width, $height);

            // Write the thumbnail to disk in the appropriate format
            if (!strcmp($path_parts['extension'], "png"))
                imagepng($virtual_image, $dest);
            elseif (!strcmp($path_parts['extension'], "gif"))
                imagegif($virtual_image, $dest);
            elseif (in_array($path_parts['extension'], ["jpg", "jpeg", "jp2"]))
                imagejpeg($virtual_image, $dest);
            else
                throw new Exception("!!! Thumbnail Generation Module :: Could not make thumbnail for image type :: " . $path . " :: " . $path_parts['extension']);
        }

        // Encode the thumbnail as a base64 data URI and overwrite the file
        // so callers can use it directly in <img src="..."> without a separate HTTP request
        $thumbnailData = base64_encode(file_get_contents($dest));
        $thumbnailData = 'data: ' . mime_content_type($dest) . ';base64,' . $thumbnailData;
        file_put_contents($dest, $thumbnailData);

        return $dest;
    }

    /**
     * Sanitises a filename by removing spaces and replacing commas with underscores.
     *
     * Strips directory components via basename() before sanitising, ensuring only
     * the filename itself is stored — never a path traversal string.
     *
     * @param string $basename Raw filename (may include directory separators)
     * @return string          Sanitised filename safe for filesystem storage
     */
    private static function getBaseName(string $basename): string {
        $baseName = str_replace(" ", "", basename($basename));
        return str_replace(",", "_", basename($baseName));
    }

    /**
     * Extracts and lowercases the file extension from a basename.
     *
     * @param string $baseName Filename with extension (directory components ignored)
     * @return string          Lowercase extension, e.g. 'pdf', 'xml', 'jpg'
     */
    public static function getFileExtensionFromBaseName(string $baseName): string {
        $parts = pathinfo($baseName);
        return strtolower($parts['extension']);
    }

    /**
     * Returns a list of filenames found in a directory, optionally filtered by type.
     *
     * Skips '.' and '..' entries. When $type is provided only files whose names
     * contain '.<type>' are included (uses strpos, so the match is not anchored
     * to the end of the filename).
     *
     * @param string      $dir  Absolute path to the directory to scan
     * @param string|null $type Optional extension to filter by (without leading dot)
     * @return array            Flat array of matching filename strings; empty if
     *                          $dir is not a directory
     */
    public static function getFileListFromDirectory(string $dir, string $type = null): array {
        $files = [];

        if (!is_dir($dir)) return $files;

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') continue;

            if (isset($type)) {
                // Include only files matching the requested extension
                if (strpos($item, "." . $type) !== false) array_push($files, $item);
            } else {
                array_push($files, $item);
            }
        }

        return $files;
    }
}
?>