<?php
/**
 * Exports a serialized Article object to both a refreshed JATS XML file and an
 * OJS-native XML package, registers both output files in the database, and marks
 * the job as finished.
 *
 * This handler is executed as a scheduled job. It performs two sequential exports:
 *
 *   1. JATS XML refresh — re-exports the article's JATS XML via {@see OMToJATSArticleAdapter},
 *      using the existing galley JATS file as a template. This ensures the JATS file reflects
 *      any edits made to the article object model since it was last exported.
 *
 *   2. OJS XML export — generates an OJS-native XML package from the article OM via
 *      {@see OMToOJSArticleAdapter}, suitable for import into an OJS installation.
 *
 * Both output files are registered in the `file` table. Only the OJS XML file ID is
 * linked to the job as the primary output.
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to set output file ID and update status
 * @param  array   $params  Job parameters; must include:
 *                            - 'object_id'       (int) ID of the serialized article object to export
 *                            - 'user_details_id' (int) ID of the user who owns the job
 *
 * @throws Exception  If the article object has no associated JATS XML galley file. Re-throws
 *                    any other unexpected exception after marking the job as FAILED and
 *                    logging the error.
 *
 * @note  $newPath is referenced in both error_log() calls for unrecognised file types, but
 *        is never defined in this handler. These messages will produce an undefined variable
 *        notice. They should reference $jatsoutputFilePath and $outputFileName respectively.
 *
 * @note  The $type variable is reused for both the JATS and OJS file type lookups. The second
 *        lookup overwrites the first, which is fine here since they are sequential, but using
 *        distinct variable names (e.g. $jatsType and $ojsType) would be clearer.
 *
 * @note  The $vals array is also reused and rebuilt for each output file. This works correctly
 *        but distinct variable names would improve readability.
 *
 * @see   Biblhertz\Article\adapters\OMToJATSArticleAdapter
 * @see   Biblhertz\Article\adapters\OMToOJSArticleAdapter
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Article\adapters\OMToOJSArticleAdapter;
use Biblhertz\Article\om\Article;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Article\adapters\OMToJATSArticleAdapter;

try {
    $object  = new SerializedObject($objDB, $params['object_id']);
    $article = unserialize($object->getObject());
    $user    = new User($objDB, $params['user_details_id']);
    $logger->print("Object   :: " . $object->getName() . " (id: " . $params['object_id'] . ")");
    $logger->print("User     :: " . $user->getName());

    // -------------------------------------------------------------------------
    // Step 1: Refresh the JATS XML file
    // -------------------------------------------------------------------------

    // Retrieve the JATS XML galley file associated with this article.
    // This is used as the template/source for the re-export; it must be set
    // on the article object before this job is dispatched.
    $jats = $article->getJATSXMLFile();
    if (!$jats) {
        throw new Exception("No JATS XML file was set on the article as a galley file");
    }

    // Build a unique output path for the refreshed JATS file in the user's file store.
    $jfname             = pathinfo($jats->getGalleyFilePath(), PATHINFO_FILENAME);
    $jatsoutputFilePath = $user->getMyFileStoreDirectoryPath()
                        . DIRECTORY_SEPARATOR
                        . $jfname . "_" . uniqid() . "_JATS.xml";

    // Export the article OM to JATS XML, using the existing galley file as a template.
    // setSerializedObject() allows the adapter to update the serialized object reference
    // if the export modifies the article state.
    $omToJATS = new OMToJATSArticleAdapter();
    $omToJATS->setLogger($logger);
    $omToJATS->setArticle($article);
    $omToJATS->setJATSXMLPath($jats->getGalleyFilePath());
    $omToJATS->setOutputFilePath($jatsoutputFilePath);
    $omToJATS->setSerializedObject($object);
    $omToJATS->exportJATSArticle();

    // Register the refreshed JATS XML file in the database.
    // Note: the error_log() call below references $newPath which is undefined here;
    // this should be $jatsoutputFilePath.
    $jatsVals = [
        'name'            => basename($jatsoutputFilePath),
        'size'            => filesize($jatsoutputFilePath),
        'type'            => "text/xml",
        'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
        'user_details_id' => $params['user_details_id'],
        'path'            => $jatsoutputFilePath,
    ];

    $jatsTypeResult = $objDB->preparedSelect(
        "SELECT id, type FROM file_type WHERE name = ?",
        ["JATS xml"]
    );
    $jatsType = $jatsTypeResult->fetch();

    if (!$jatsType) {
        $logger->print("!!! File type 'JATS xml' is not registered in file_type table :: $jatsoutputFilePath");
    } else {
        $jatsVals['file_type_id'] = $jatsType['id'];
    }

    $objDB->insert("file", $jatsVals);
    $logger->print("JATS out :: " . $jatsoutputFilePath);

    // -------------------------------------------------------------------------
    // Step 2: Generate the OJS-native XML export
    // -------------------------------------------------------------------------

    // Build the OJS XML output path, named after the serialized object for traceability.
    $outputFileName = $user->getMyFileStoreDirectoryPath()
                    . DIRECTORY_SEPARATOR
                    . $object->getName() . "_OJS.xml";

    $omToOJS = new OMToOJSArticleAdapter($article, $outputFileName);
    $omToOJS->generateXml();

    $logger->print("OJS out  :: " . $outputFileName);

    // Register the OJS XML file in the database.
    // Note: the error_log() call below references $newPath which is undefined here;
    // this should be $outputFileName.
    $ojsVals = [
        'name'            => basename($outputFileName),
        'size'            => filesize($outputFileName),
        'type'            => "text/xml",
        'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
        'user_details_id' => $params['user_details_id'],
        'path'            => $outputFileName,
    ];

    $ojsTypeResult = $objDB->preparedSelect(
        "SELECT id, type FROM file_type WHERE name = ?",
        ["OJS xml"]
    );
    $ojsType = $ojsTypeResult->fetch();

    if (!$ojsType) {
        $logger->print("!!! File type 'OJS xml' is not registered in file_type table :: $outputFileName");
    } else {
        $ojsVals['file_type_id'] = $ojsType['id'];
    }

    $id = $objDB->insert("file", $ojsVals);
    $logger->print("Registered both output files in database");

    // Link the OJS XML file as the primary job output and mark the job complete.
    $job->setOutputFileID($id);
    $job->updateStatus("FINISHED");
    $logger->writeOutUserLogFile("OMToOJS", $user);

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in OMToOJS job :: " . $e->getMessage());

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>