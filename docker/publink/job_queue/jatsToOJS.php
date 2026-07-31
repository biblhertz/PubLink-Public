<?php
/**
 * Converts a JATS XML file to an OJS-native XML article package, moves all generated
 * output files into the user's permanent file store, registers each in the database,
 * and marks the job as finished.
 *
 * This handler is executed as a scheduled job. It:
 *   1. Copies the source JATS XML into a unique temporary working directory
 *   2. Validates the file against the JATS XML schema
 *   3. Converts the JATS XML to a PubLink Article object model via {@see JATSToOMAdapter}
 *   4. Exports an OJS-native XML file from the Article OM via {@see OMToOJSArticleAdapter}
 *   5. Moves all files produced in the working directory to the user's file store,
 *      generating thumbnails for any image-type outputs
 *   6. Registers each output file in the `file` table and links the last one to the job
 *   7. Removes the temporary working directory
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to set output file ID and update status
 * @param  array   $params  Job parameters; must include:
 *                            - 'file_id'         (int)    ID of the source JATS XML file
 *                            - 'user_details_id' (int)    ID of the user who owns the job
 *                            - 'ojs_user'        (string) OJS username to embed in the export
 *                          Optional:
 *                            - 'cover_file_id'   (int)    File ID of a cover image to attach
 *                            - 'galley_files'    (int[])  Array of file IDs for galley attachments
 *
 * @throws Exception  If JATS validation fails, or if the temporary working directory
 *                    cannot be removed after processing. Re-throws any other unexpected
 *                    exception after marking the job as FAILED and logging the error.
 *
 * @note  Unlike createArticle.php, this handler passes $movedfilepath (the copied file)
 *        to setJATSXMLPath() rather than the original $path. This is consistent with
 *        setInputDir($myPath) and is the correct approach for self-contained temp directory
 *        processing.
 *
 * @note  The file type lookup inside the output file loop always queries for "OJS xml"
 *        regardless of the actual file extension. If the working directory contains image
 *        files (e.g. figures copied from the source), their type will be misidentified.
 *        Consider using File::getFileExtensionFromBaseName() per file, as done in other handlers.
 *
 * @note  $job->setOutputFileID() is called on every file in the loop, so only the last
 *        file's ID is retained as the job's output. If the primary output is the OJS XML
 *        file, consider setting the output ID specifically for that file rather than the
 *        last file processed.
 *
 * @note  The error message in the directory removal exception references pdfImageExtract.php,
 *        which appears to be a copy-paste artefact from another handler.
 *
 * @see   Biblhertz\Article\adapters\JATSToOMAdapter
 * @see   Biblhertz\Article\adapters\OMToOJSArticleAdapter
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Article\adapters\JATSToOMAdapter;
use Biblhertz\Article\adapters\JATSXMLValidator;
use Biblhertz\Article\adapters\OMToOJSArticleAdapter;
use Biblhertz\Article\om\Article;
use Biblhertz\Publink\utilities\Logger;

try {
    //$logger = new Logger();

    $file = new File($objDB, $params['file_id']);
    $path = $file->getPath();
    $user = new User($objDB, $params['user_details_id']);

    // Create a unique temporary working directory in the user's file store.
    // All intermediate and output files are written here during processing.
    $myPath        = $user->getMyFileStoreDirectory() . uniqid() . DIRECTORY_SEPARATOR;
    $fname         = pathinfo($path, PATHINFO_FILENAME) . "." . pathinfo($path, PATHINFO_EXTENSION);
    mkdir($myPath);
    $movedfilepath = $myPath . $fname;
    copy($path, $movedfilepath);

    $logger->print("Source   :: " . $file->getName());
    $logger->print("User     :: " . $user->getName());
    $logger->print("Work dir :: " . $myPath);

    // Validate the copied JATS XML file against the JATS schema before conversion.
    // Throws if invalid; the temporary directory is not cleaned up on failure.
    $validator = new JATSXMLValidator();
    $validator->setLogger($logger);
    $valid = $validator->validateJATSXML($movedfilepath);

    if (!$valid) {
        throw new Exception(
            "article.php :: Document is not valid JATS XML; it has failed validation against the JATS schema"
        );
    }

    // Configure the JATS-to-OM adapter.
    // setJATSXMLPath() uses the copied file (unlike createArticle.php which uses the original),
    // keeping all file references self-contained within the temporary working directory.
    $jatstoOM = new JATSToOMAdapter();
    $jatstoOM->setLogger($logger);
    $jatstoOM->setInputDir($myPath);
    $jatstoOM->setJATSXMLPath($movedfilepath);
    $jatstoOM->setOJSUser($params['ojs_user']);

    // Attach optional cover image and galley files if provided.
    if (isset($params['cover_file_id'])) {
        $jatstoOM->setCoverImageFile(new File($objDB, $params['cover_file_id']));
    }

    if (isset($params['galley_files'])) {
        foreach ($params['galley_files'] as $fid) {
            $jatstoOM->addGalleyFile(new File($objDB, $fid));
        }
    }

    // Run the JATS-to-OM conversion and retrieve the Article object.
    $jatstoOM->generateObjectModel();
    $article = $jatstoOM->getArticle();

    // Define the output path for the OJS-native XML export.
    $nativeUri = $myPath . DIRECTORY_SEPARATOR
               . pathinfo($path, PATHINFO_FILENAME) . "_ojs_article.xml";

    // Generate the OJS XML from the Article OM.
    $output = new OMToOJSArticleAdapter($article, $nativeUri);
    $output->setLogger($logger);
    $output->generateXML();

    $job->updateStatus("Generated OJS XML File");

    // Remove the copied source JATS file; only the OJS output and any associated assets
    // (e.g. figures) produced by the adapter should remain in the working directory.
    unlink($movedfilepath);

    // Enumerate all files remaining in the working directory for registration.
    $files = File::getFileListFromDirectory($myPath);

    $logger->print("Output files :: " . count($files));

    // Move each output file from the temporary directory to the user's permanent file store,
    // using getUniqueFileName() to avoid collisions with existing files.
    foreach ($files as $file) {
        $tmpPath = $myPath . DIRECTORY_SEPARATOR . $file;
        $newPath = $user->getUniqueFileName($user->getMyFileStoreDirectory() . DIRECTORY_SEPARATOR . $file);
        rename($tmpPath, $newPath);
        $logger->print("Moved :: " . basename($tmpPath) . " -> " . $newPath);

        // Build the metadata record for this output file.
        $vals = [
            'name'            => basename($newPath),
            'size'            => filesize($newPath),
            'type'            => filetype($newPath),
            'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
            'user_details_id' => $params['user_details_id'],
            'path'            => $newPath,
        ];

        // Note: the type lookup always uses "OJS xml" regardless of the actual file extension.
        // If the working directory may contain non-XML files (e.g. images), consider resolving
        // the type by extension per file, as done in other handlers.
        $typeResult = $objDB->preparedSelect(
            "SELECT id, type FROM file_type WHERE name = ?",
            ["OJS xml"]
        );
        $type = $typeResult->fetch();

        if (!$type) {
            $logger->print("!!! Generated file type 'OJS xml' is not recognised by the system :: $newPath");
        } else {
            $vals['file_type_id'] = $type['id'];

            // Generate a thumbnail if the file type is classified as an image.
            if (!strcmp($type['type'], "image")) {
                $vals['thumbnail_path'] = File::makeThumbNailImage($objDB, $newPath);
            }
        }

        $id = $objDB->insert("file", $vals);
        $job->updateStatus("Old File :: " . $tmpPath . "\nNew File :: " . $newPath);

        // Note: setOutputFileID() is called per iteration, so only the last file's ID
        // is retained. If the OJS XML file specifically should be the job output, set
        // the ID conditionally rather than on every file.
        $job->setOutputFileID($id);
    }

    // Remove the now-empty temporary working directory.
    // Failure to remove is treated as a hard error to prevent orphaned directories.
    $job->updateStatus("Removing :: " . $myPath);
    $removed = File::deleteDirectory($myPath);

    $logger->print("Removed  :: " . $myPath);

    if (!$removed) {
        // Note: the reference to pdfImageExtract.php below is a copy-paste artefact
        // and should be updated to reflect this handler's actual filename.
        throw new Exception(
            "!!! There was a problem removing the temporary directory $myPath in article.php"
        );
    }

    $job->updateStatus("FINISHED");
    $logger->writeOutUserLogFile("Article", $user);

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in jatsToOJS job :: " . $e->getMessage());

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>