<?php
/**
 * Extracts all embedded images from a PDF file using the pdfimages command-line tool,
 * moves the extracted files into the user's permanent file store, registers each in the
 * database with thumbnails for image types, and marks the job as finished.
 *
 * This handler is executed as a scheduled job. It creates a unique temporary working
 * directory, runs pdfimages against the source PDF, then moves all output files into the
 * user's file store — prefixing each with the source PDF's base name for traceability.
 * Thumbnails are generated for any output file whose registered type is "image".
 * The temporary directory is removed after all files have been moved.
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to report progress and update status
 * @param  array   $params  Job parameters; must include:
 *                            - 'file_id'         (int)    ID of the source PDF file
 *                            - 'user_details_id' (int)    ID of the user who owns the job
 *                          Optional:
 *                            - 'output_type'     (string) If set to "jp2", instructs pdfimages
 *                                                         to output JPEG 2000 files (-jp2 flag);
 *                                                         otherwise all formats are extracted (-all)
 *
 * @throws Exception  If the temporary working directory cannot be removed after processing.
 *                    Re-throws any other unexpected exception after marking the job as FAILED
 *                    and logging the error.
 *
 * @note  The $type variable is reused for both the pdfimages flag string ("-all" / "-jp2")
 *        and the file_type DB result inside the foreach loop. The DB result overwrites the
 *        flag string on the first iteration. This works but is fragile — consider using
 *        distinct variable names (e.g. $extractFlag and $fileType) to avoid confusion.
 *
 * @note  $job->setOutputFileID() is not called in this handler. If the job system expects
 *        an output file ID to be set, the last inserted file ID should be passed to it,
 *        as done in other handlers.
 *
 * @note  $logger->writeOutUserLogFile() is not called on completion. Other handlers write
 *        a user-facing log entry at the end; consider adding this for consistency.
 *
 * @note  If pdfimages fails silently (exits without output and without writing files),
 *        the foreach loop is skipped, the temp directory is removed, and the job is marked
 *        FINISHED with no files registered. Consider checking $files is non-empty before
 *        proceeding.
 *
 * @see   pdfimages(1) man page for flag reference
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Publink\utilities\Logger;

try {
    //logger passed through from wrapper

    $file = new File($objDB, $params['file_id']);
    $path = $file->getPath();
    $user = new User($objDB, $params['user_details_id']);

    // Create a unique temporary working directory in the user's file store.
    // pdfimages writes all extracted images here before they are moved to permanent storage.
    $myPath = $user->getMyFileStoreDirectory() . uniqid() . DIRECTORY_SEPARATOR;
    mkdir($myPath);
    $logger->print("Source   :: " . $file->getName());
    $logger->print("User     :: " . $user->getName());
    $logger->print("Work dir :: " . $myPath);

    // Determine the pdfimages output format flag.
    // -jp2  : extract images as JPEG 2000 (lossy, smaller)
    // -all  : extract images in all native formats (default)
    // Note: $extractFlag is used here; the inner loop uses $fileType to avoid
    // the variable collision present in the original code.
    $extractFlag = "-all";
    if (isset($params['output_type']) && !strcmp($params['output_type'], "jp2")) {
        $extractFlag = "-jp2";
    }

    // Build and run the pdfimages command.
    // Syntax: pdfimages <flag> <input_pdf> <output_prefix>
    // pdfimages appends a sequential suffix and extension to the output prefix automatically.
    // Note: $path and $myPath are not individually shell-escaped; wrap with escapeshellarg()
    // if filenames may contain spaces or special characters.
    $cmd = "pdfimages $extractFlag $path $myPath";
    $logger->print("Extract  :: " . $extractFlag . " mode");
    $logger->print("Command  :: " . $cmd);
    $job->updateStatus($cmd);
    $out = system($cmd);

    // Enumerate all files written to the temporary directory by pdfimages.
    $files = File::getFileListFromDirectory($myPath);
    $logger->print("Extracted :: " . count($files) . " file(s)");

    // Retain the source PDF's base name to prefix each extracted image filename,
    // keeping the output files traceable back to their source document.
    $pathParts = pathinfo($path);

    foreach ($files as $file) {
        $tmpPath = $myPath . DIRECTORY_SEPARATOR . $file;

        // Prefix the extracted filename with the source PDF's base name and move it
        // to the user's permanent file store, using getUniqueFileName() to avoid collisions.
        $newPath = $user->getUniqueFileName(
            $user->getMyFileStoreDirectory() . DIRECTORY_SEPARATOR . $pathParts['filename'] . $file
        );
        rename($tmpPath, $newPath);
        $logger->print("Moved    :: " . basename($tmpPath) . " -> " . $newPath);

        // Build the metadata record for this extracted file.
        $vals = [
            'name'            => basename($newPath),
            'size'            => filesize($newPath),
            'type'            => filetype($newPath),
            'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
            'user_details_id' => $params['user_details_id'],
            'path'            => $newPath,
        ];

        // Resolve the file type ID by matching the file extension against the file_type table.
        $typeResult = $objDB->preparedSelect(
            "SELECT id, type FROM file_type WHERE name = ?",
            [File::getFileExtensionFromBaseName($vals['name'])]
        );
        $fileType = $typeResult->fetch();

        if (!$fileType) {
            $logger->print("!!! File type not registered in file_type table :: $newPath");
        } else {
            $vals['file_type_id'] = $fileType['id'];

            // Generate a thumbnail if the file type is classified as an image.
            if (!strcmp($fileType['type'], "image")) {
                $vals['thumbnail_path'] = File::makeThumbNailImage($objDB, $newPath);
            }
        }

        $id = $objDB->insert("file", $vals);
    }

    // Remove the now-empty temporary working directory.
    // Failure to remove is treated as a hard error to prevent orphaned directories.
    $job->updateStatus("Removing :: " . $myPath);
    $removed = File::deleteDirectory($myPath);
    $logger->print("Removed  :: " . $myPath);

    if (!$removed) {
        throw new Exception("!!! There was a problem removing the temporary directory $myPath in pdfImageExtract.php");
    }

    $logger->writeOutUserLogFile("pdfImageExtract", $user);
    $job->updateStatus("FINISHED");

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in pdfImageExtract job :: " . $e->getMessage());

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>