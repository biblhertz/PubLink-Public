<?php
/**
 * Generates a combined DataCite XML file from a collection of serialized article objects,
 * registers the output file in the database, and marks the job as finished.
 *
 * This handler is intended to be executed as a scheduled job. It iterates over a set of
 * serialized article objects, converts each one to DataCite XML format using
 * {@see OMToDataCiteAdapter}, concatenates the results into a single file, and stores
 * metadata about that file in the `file` table.
 *
 * @param  object  $objDB    Database connection/query object
 * @param  object  $job      The scheduled job instance; used to set output file ID and update status
 * @param  array   $objects  Array of serialized object identifiers to process
 * @param  array   $params   Job parameters; must include:
 *                             - 'user_details_id' (int) The ID of the user who owns the job
 * @param  object  $logger   Logger instance with print() and writeOutUserLogFile() methods
 *
 * @throws Exception  Re-throws any unexpected exception after marking the job as FAILED
 *                    and logging the error message
 *
 * @note   Known limitation: if each call to {@see OMToDataCiteAdapter::generateXML()} emits
 *         a full XML document (including declaration and root element), the concatenated
 *         output will not be valid XML. Ensure generateXML() produces only a fragment, or
 *         that the adapter is configured to support multi-record aggregation.
 *
 * @note   $outputFilePath is reused on every loop iteration as a temporary write target.
 *         The per-article XML is read back immediately after generation and appended to
 *         $fileContents. The final concatenated string is then written back to the same path.
 */
try {
    // Build a unique file path for the combined DataCite XML output in the job's file store directory.
    // uniqid() provides a microsecond-based prefix to avoid collisions between concurrent jobs.
    $outputFilePath = $job->getMyFileStoreDirectoryPath() . DIRECTORY_SEPARATOR . uniqid() . "_DataCite.xml";

    // Accumulator for the XML output of each article.
    // See note above regarding XML validity when concatenating multiple documents.
    $fileContents = "";

    // Look up the user once, outside the loop — $params['user_details_id'] is constant
    // across all iterations and constructing User inside the loop is unnecessary overhead.
    $user = new User($objDB, $params['user_details_id']);

    foreach ($objects as $obj) {
        // Deserialize the stored article object from the database.
        $object  = new SerializedObject($objDB, $obj);
        $article = unserialize($object->getObject());

        // Build the DataCite adapter for this article.
        // Passes the article's references and the shared output file path.
        $omToDC = new OMToDataCiteAdapter($article->getReferences(), $outputFilePath);
        $omToDC->setArticle($article);
        $omToDC->setLogger($logger);

        // Write this article's DataCite XML to $outputFilePath.
        $omToDC->generateXML();

        // Read the just-written XML back and append it to the combined output.
        $fileContents .= file_get_contents($outputFilePath);

        $logger->print("Processed :: serialized_object_id " . $obj . " -> " . $outputFilePath);
    }

    // Overwrite the temporary file with the full concatenated XML for all articles.
    file_put_contents($outputFilePath, $fileContents);

    // Build the metadata record for the generated file.
    $vals = [
        'name'            => basename($outputFilePath),
        'size'            => filesize($outputFilePath),
        'type'            => "text/xml",
        'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
        'user_details_id' => $params['user_details_id'],
        'path'            => $outputFilePath,
    ];

    // Resolve the file type ID by matching the output file's extension against the file_type table.
    $typeResult = $objDB->preparedSelect(
        "SELECT id, type FROM file_type WHERE name = ?",
        [File::getFileExtensionFromBaseName($vals['name'])]
    );
    $type = $typeResult->fetch();

    if (!$type) {
        // The file extension is not registered in the system — the file record will lack a type ID.
        // This is non-fatal but should be investigated; the XML file has already been written.
        $logger->print("!!! Generated file type is not recognised by the system :: " . $outputFilePath);
    } else {
        $vals['file_type_id'] = $type['id'];
    }

    // Insert the file record and link it to the job.
    $id = $objDB->insert("file", $vals);
    $job->setOutputFileID($id);

    // Write the user-facing log entry and mark the job complete.
    $logger->writeOutUserLogFile("OMToDataCite", $user);
    $job->updateStatus("FINISHED");

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in OMToDataCite job :: " . $e->getMessage());

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>