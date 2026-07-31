<?php
/**
 * Converts a serialized article object to JATS XML format and registers the output
 * file in the database, then marks the job as finished.
 *
 * This handler is executed as a scheduled job. It loads a serialized article and an
 * existing JATS XML file (used as a template or source), runs the conversion via
 * {@see OMToJATSArticleAdapter}, and stores metadata about the generated file in the
 * `file` table.
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to set output file ID and update status
 * @param  array   $params  Job parameters; must include:
 *                            - 'object_id'       (int) ID of the serialized article object to convert
 *                            - 'user_details_id' (int) ID of the user who owns the job
 *                            - 'file_id'         (int) ID of the source JATS XML file (used as input/template)
 *
 * @throws Exception  Re-throws any unexpected exception after marking the job as FAILED
 *                    and logging the error message
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Article\adapters\OMToJATSArticleAdapter;
use Biblhertz\Article\om\Article;
use Biblhertz\Publink\utilities\Logger;

try {
    //$logger = new Logger();

    // Load the serialized article object and deserialize it into an Article instance.
    $object  = new SerializedObject($objDB, $params['object_id']);
    $article = unserialize($object->getObject());

    // Load the owning user and the source JATS XML file referenced by the job parameters.
    $user = new User($objDB, $params['user_details_id']);
    $file = new File($objDB, $params['file_id']);

    // Build a unique output path in the user's file store directory.
    // The source filename (without extension) is retained as a prefix for traceability;
    // uniqid() adds a microsecond-based suffix to avoid collisions between concurrent jobs.
    $outputFilePath = $user->getMyFileStoreDirectoryPath()
        . DIRECTORY_SEPARATOR
        . $file->getFileNameWithoutExtension() . "_" . uniqid() . "_JATS_new.xml";

    // Configure and run the JATS export adapter.
    // setJATSXMLPath() provides the source/template JATS file;
    // setOutputFilePath() defines where the converted output is written.
    $omToJATS = new OMToJATSArticleAdapter();
    $omToJATS->setLogger($logger);
    $omToJATS->setArticle($article);
    $omToJATS->setJATSXMLPath($file->getPath());
    $omToJATS->setOutputFilePath($outputFilePath);
    $omToJATS->setSerializedObject($object);
    $omToJATS->exportJATSArticle();

    $logger->print("Source :: " . $file->getName());
    $logger->print("Output :: " . $outputFilePath);

    // Build the metadata record for the generated file.
    $vals = [
        'name'            => basename($outputFilePath),
        'size'            => filesize($outputFilePath),
        'type'            => "text/xml",
        'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
        'user_details_id' => $params['user_details_id'],
        'path'            => $outputFilePath,
    ];

    // Look up the file type ID for JATS XML by its registered name in the file_type table.
    $typeResult = $objDB->preparedSelect(
        "SELECT id FROM file_type WHERE name = ?",
        ["JATS xml"]
    );
    $type = $typeResult->fetch();

    if (!$type) {
        // The 'JATS xml' type is not registered in the system — the file record will lack
        // a type ID. Non-fatal, but should be investigated; the XML file has already been written.
        $logger->print("!!! File type 'JATS xml' is not recognised by the system :: " . $outputFilePath);
    } else {
        $vals['file_type_id'] = $type['id'];
    }

    // Insert the file record, link it to the job, and mark the job complete.
    $id = $objDB->insert("file", $vals);
    $job->setOutputFileID($id);
    $job->updateStatus("FINISHED");
    $logger->writeOutUserLogFile("OMToJATS", $user);

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in OMToJATS job :: " . $e->getMessage());

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>