<?php
/**
 * Converts an image file to JPEG 2000 (JP2) format using Imagick, copies the source
 * file's thumbnail alongside the converted image, registers the output in the database,
 * and marks the job as finished.
 *
 * The output JP2 file is written to the same directory as the source file, with the
 * same base name but a .jp2 extension. The existing thumbnail is also copied to that
 * directory with a "_jp2thumb" suffix so it remains associated with the converted file.
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to set output file ID and update status
 * @param  array   $params  Job parameters; must include:
 *                            - 'file_id'         (int) ID of the source image file to convert
 *                            - 'user_details_id' (int) ID of the user who owns the job
 *
 * @throws Exception  Re-throws any unexpected exception (including Imagick errors) after
 *                    marking the job as FAILED and logging the error message.
 *
 * @note  JP2 quality/compression level is currently hardcoded to Imagick defaults.
 *        A commented-out line shows how to set a specific quality via
 *        setOption('jp2:quality', 40); adjust and re-enable as needed.
 *
 * @note  The thumbnail copy uses the source file's directory rather than a configurable
 *        output path. If the source file's thumbnail does not exist, copy() will emit a
 *        warning and return false — this is not currently checked.
 *
 * @note  $user is loaded but only used implicitly via $params['user_details_id'] in $vals.
 *        If user-specific output paths or logging are added later, $user is already available.
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
    $file = new File($objDB, $params['file_id']);
    $path = $file->getPath();

    $user = new User($objDB, $params['user_details_id']);

    // Derive the JP2 output path from the source file's directory and base name.
    $fname = pathinfo($path, PATHINFO_FILENAME) . ".jp2";
    $fpath = pathinfo($path, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR . $fname;

    // Convert the source image to JPEG 2000 format using Imagick.
    // COMPRESSION_JPEG2000 is set explicitly; quality can be tuned via
    // setOption('jp2:quality', 40) if a specific compression level is required.
    $img = new Imagick($path);
    $img->setImageFormat("jp2");
    $img->setImageCompression(Imagick::COMPRESSION_JPEG2000);
    // $img->setOption('jp2:quality', 40);
    $img->writeImage($fpath);
    $logger->print("Source   :: " . $file->getName());
    $logger->print("Output   :: " . $fpath);

    // Copy the source file's thumbnail to the same directory as the JP2 output,
    // appending "_jp2thumb" to the base name so it remains associated with the
    // converted file. If no thumbnail exists, copy() will silently fail — consider
    // adding a file_exists() check if thumbnail presence cannot be guaranteed.
    $thumb     = $file->getThumbnailPath();
    $thumbname = pathinfo($path, PATHINFO_DIRNAME)
               . DIRECTORY_SEPARATOR
               . pathinfo($path, PATHINFO_FILENAME)
               . "_jp2thumb"
               . pathinfo($thumb, PATHINFO_EXTENSION);
    copy($thumb, $thumbname);
    $logger->print("Thumbnail :: " . $thumbname);

    // Build the metadata record for the generated JP2 file.
    $vals = [
        'name'            => basename($fpath),
        'size'            => filesize($fpath),
        'type'            => filetype($fpath),
        'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
        'user_details_id' => $params['user_details_id'],
        'path'            => $fpath,
    ];

    // Resolve the file type ID by matching the JP2 extension against the file_type table.
    // If the type is registered as an image type, also store the thumbnail path.
    $typeResult = $objDB->preparedSelect(
        "SELECT id, type FROM file_type WHERE name = ?",
        [File::getFileExtensionFromBaseName($vals['name'])]
    );
    $type = $typeResult->fetch();

    if (!$type) {
        $logger->print("!!! File type 'jp2' is not registered in file_type table :: $fpath");
    } else {
        $vals['file_type_id'] = $type['id'];

        // Only set the thumbnail path if the file type is classified as an image.
        if (!strcmp($type['type'], "image")) {
            $vals['thumbnail_path'] = $thumbname;
        }
    }

    // Insert the file record, link it to the job, and mark the job complete.
    $id = $objDB->insert("file", $vals);
    $job->setOutputFileID($id);
    $logger->writeOutUserLogFile("imageToJP2", $user);
    $job->updateStatus("FINISHED");

} catch (Exception $e) {
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in imageToJP2 job :: " . $e->getMessage());

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>