<?php
/**
 * File Upload Service
 *
 * Accepts an uploaded file, stores it via {@see File::saveAttachedFile()}, and
 * optionally converts it to a serialized object model depending on the request
 * parameters. Three modes are supported:
 *
 *   ?jats  (present)
 *     Validates the uploaded file as a JATS XML document, converts it to an
 *     article object model via {@see JATSToOMAdapter}, and persists it as a
 *     serialized `Article` record in `serialized_object`. Returns the new
 *     object ID (`oid`) in the response for immediate use by the caller.
 *
 *   ?bib   (present)
 *     Validates the uploaded file as a BibTeX document, converts it to a
 *     reference collection via {@see BibtexToReferenceCollectionAdapter}, and
 *     persists it as a serialized `Reference Collection` record in
 *     `serialized_object`. Returns the new object ID (`oid`) in the response.
 *
 *   (neither present)
 *     Stores the file as-is with no further processing and returns a generic
 *     upload confirmation.
 *
 * Object model serialization:
 *   Converted objects are serialized and base64-encoded before storage. The
 *   `serialized_object` record name is derived from the filename with a unique
 *   suffix (`_ARTICLE_{uniqid}` or `_REFS_{uniqid}`) to avoid collisions.
 *
 * Logging:
 *   JATS and BibTeX imports write a per-user log file via {@see Logger} for
 *   post-import diagnostics. On exception the log is written before the error
 *   response is returned.
 *
 * Response status codes:
 *   0  Success
 *   1  Outer exception (file save failed or unexpected error)
 *   4  Inner exception during conversion, or wrong file type for JATS
 *   5  Wrong file type for BibTeX
 *
 * Output:
 *   Content-Type: application/json; charset=UTF-8
 *   Body: JSON object with keys:
 *     status  int     Status code (see above)
 *     msg     string  Human-readable result message
 *     oid     int     (JATS/BibTeX success only) Serialized object ID
 *
 * Note: the final `$response['status'] = 1 / "No file was received"` block
 * after the closing `catch` is unreachable — the outer `try` always exits
 * or throws before reaching it.
 *
 * Note: `$message` referenced in `$logger->print(print_r($message, true))`
 * in the JATS success branch is undefined; this will emit a PHP notice.
 *
 * @package Biblhertz\Publink
 * @see     File::saveAttachedFile()
 * @see     JATSToOMAdapter
 * @see     BibtexToReferenceCollectionAdapter
 * @see     Logger
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\File;
use Biblhertz\Article\adapters\JATSToOMAdapter;
use Biblhertz\Article\adapters\BibtexToReferenceCollectionAdapter;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Publink\Config;
use DateTime;

function uploadSystemLogPath(string $prefix): string {
    $dt = new DateTime('now');
    return Config::$JOB_LOG_DIR . DIRECTORY_SEPARATOR
        . $prefix . '_' . $dt->format('d-m-Y_H_i_s') . '_' . uniqid() . '.log';
}

try {

    $page     = new Bibliotheca_Content_Page();
    $fid      = File::saveAttachedFile($page->getObjDB(), "file", $page->getUser());
    $response = array();

    // -------------------------------------------------------------------------
    // JATS mode: convert uploaded XML to a serialized Article object
    // -------------------------------------------------------------------------

    if (isset($_REQUEST['jats'])) {

        $file = new File($page->getObjDB(), $fid);

        if ($file->isJATS()) {
            try {
                $logger  = new Logger();
                $adapter = new JATSToOMAdapter();
                $adapter->setLogger($logger);
                $adapter->setInputDir($page->getUser()->getMyFileStoreDirectoryPath());
                $adapter->setJATSXMLPath($file->getPath());
                $adapter->setJATSXMLID($file->getID());
                $adapter->generateObjectModel();

                $article           = $adapter->getArticle();
                $serializedArticle = base64_encode(serialize($article));

                // Persist the article as a serialized object record
                $vals                    = array();
                $vals['name']            = $file->getFileNameWithoutExtension() . "_ARTICLE_" . uniqid();
                $vals['object']          = $serializedArticle;
                $vals['type']            = 'Article';
                $vals['user_details_id'] = $page->getUser()->getID();
                $vals['timestamp']       = date('Y-m-d H:i:s');
                $vals['file_id']         = $file->getID();
                $oid = $page->getObjDB()->insert("serialized_object", $vals);

                $logger->print("Written JATS Import File as Serialized Object in database");

                $name               = $file->getName();
                $response['status'] = 0;
                $response['msg']    = "JATS File Uploaded as $name";
                $response['oid']    = $oid;

                header('Content-Type: application/json');
                echo json_encode($response, true);

                $logger->writeOutUserLogFile("jats_import", $page->getUser());
                $logger->writeOutLogFile(uploadSystemLogPath("jats_import"));
                exit;

            } catch (Exception $e) {
                $logger->print("Exception Encountered :: " . $e->getMessage());
                $logger->writeOutUserLogFile("jats_import", $page->getUser());
                $logger->writeOutLogFile(uploadSystemLogPath("jats_import_error"));
                $response['status'] = 4;
                $response['msg']    = "Error Exception Encountered :: see log file for more details";
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($response, true);
                exit;
            }

        } else {
            // Uploaded file failed JATS validation
            $response['status'] = 4;
            $response['msg']    = "File Selected was not a JATS file";
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response, true);
            $page = null;
            exit;
        }

    // -------------------------------------------------------------------------
    // BibTeX mode: convert uploaded .bib file to a serialized Reference Collection
    // -------------------------------------------------------------------------

    } elseif (isset($_REQUEST['bib'])) {

        $file = new File($page->getObjDB(), $fid);

        if ($file->isBibTex()) {
            try {
                $logger  = new Logger();
                $adapter = new BibtexToReferenceCollectionAdapter();
                $adapter->setLogger($logger);
                $adapter->setBibFile($file);
                $adapter->generateObjectModel();

                $collection        = $adapter->getReferenceCollection();
                $serializedArticle = base64_encode(serialize($collection));

                // Persist the reference collection as a serialized object record
                $vals                    = array();
                $vals['name']            = $file->getFileNameWithoutExtension() . "_REFS_" . uniqid();
                $vals['object']          = $serializedArticle;
                $vals['type']            = 'Reference Collection';
                $vals['user_details_id'] = $page->getUser()->getID();
                $vals['timestamp']       = date('Y-m-d H:i:s');
                $vals['file_id']         = $file->getID();
                $oid = $page->getObjDB()->insert("serialized_object", $vals);

                $logger->print("Written Bib Import File as Serialized Object in database");

                $response['status'] = 0;
                $response['msg']    = "BibTex File Uploaded";
                $response['oid']    = $oid;

                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($response, true);
                $logger->writeOutUserLogFile("bibtex_import", $page->getUser());
                $logger->writeOutLogFile(uploadSystemLogPath("bibtex_import"));
                exit;

            } catch (Exception $e) {
                $logger->print("Exception Encountered :: " . $e->getMessage());
                $logger->writeOutUserLogFile("bibtex_import", $page->getUser());
                $logger->writeOutLogFile(uploadSystemLogPath("bibtex_import_error"));
                $response['status'] = 4;
                $response['msg']    = "Error Exception Encountered :: see log file for more details";
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($response, true);
                exit;
            }

        } else {
            // Uploaded file failed BibTeX validation
            $response['status'] = 5;
            $response['msg']    = "File Selected was not a Bibtex file";
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response, true);
            $page = null;
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Generic mode: file stored as-is, no conversion
    // -------------------------------------------------------------------------

    $response['status'] = 0;
    $response['msg']    = "File Uploaded";
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, true);
    $page = null;
    exit;

} catch (Exception $e) {
    // Outer exception handler: covers file save failure and any unexpected errors
    $response['status'] = 1;
    $response['msg']    = $e->getMessage();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, true);
    exit;
}

// Note: the block below is unreachable — the try/catch above always exits.
// It can be safely removed.
$response['status'] = 1;
$response['msg']    = "No file was received";
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, true);

?>