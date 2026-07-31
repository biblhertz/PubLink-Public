<?php
/**
 * Converts a JATS XML file to an InDesign-compatible XML format using an external
 * Python script and XSLT stylesheet, registers the output file in the database,
 * and marks the job as finished.
 *
 * This handler is executed as a scheduled job. It validates that the input file is
 * JATS XML, then invokes article2indesign.py via shell with a fixed XSLT transform
 * to produce an InDesign XML output file. The result is registered in the `file`
 * table and linked to the job.
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to set output file ID and update status
 * @param  array   $params  Job parameters; must include:
 *                            - 'user_details_id' (int) ID of the user who owns the job
 *                            - 'file_id'         (int) ID of the JATS XML input file to process
 *
 * @throws Exception  If 'file_id' is not set in $params, or if the referenced file is
 *                    not a JATS XML file. Re-throws any other unexpected exception after
 *                    marking the job as FAILED and logging the error.
 *
 * @note  The output file path is derived from the input file's directory and base name;
 *        the suffix "_indesign.xml" is appended. If article2indesign.py fails silently
 *        or writes to an unexpected path, the output file registration block is skipped
 *        without raising an error.
 *
 * @note  $job->updateStatus($cmd) is called before "FINISHED", temporarily setting the
 *        job status to the raw shell command string. This appears to be a debug artefact
 *        shared across several job handlers and is overwritten immediately by "FINISHED".
 *
 * @note  Individual path arguments passed to the shell command are not wrapped with
 *        escapeshellarg(). While paths sourced from the database are generally safe,
 *        wrapping $path and $xslt with escapeshellarg() is recommended defensive practice.
 *
 * @see   /var/www/job_queue/jats2indesign/article2indesign.py
 * @see   /var/www/job_queue/jats2indesign/jats2idml.xslt
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\om\User;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Publink\pages\htmlPage;

try {
    //$logger = new Logger();
    $user   = new User($objDB, $params['user_details_id']);

    // Validate that a file ID has been provided before attempting to load it.
    if (!isset($params['file_id'])) {
        throw new Exception("!! File is not set");
    }

    $file = new File($objDB, $params['file_id']);

    // Only JATS XML files are supported as input; fail fast if the type is incorrect.
    if (!$file->isJATS()) {
        throw new Exception("!! Task requires a JATS XML file as input; the provided file is a different type");
    }

    // Build and execute the shell command.
    // article2indesign.py accepts: <jats_xml_path> <xslt_path>
    // The script is expected to write its output to <source_dir>/<source_basename>_indesign.xml.
    // Note: $path and $xslt should ideally be wrapped with escapeshellarg() to guard
    // against paths containing spaces or special characters.
    $path    = $file->getPath();
    $xslt    = "/var/www/job_queue/jats2indesign/jats2idml.xslt";
    $cmd     = "python /var/www/job_queue/jats2indesign/article2indesign.py $path $xslt";
    $command = escapeshellcmd($cmd);

    $logger->print("Input    :: " . $file->getName());
    $logger->print("Command  :: " . $cmd);
    $output  = shell_exec($command);

    // Derive the expected output path from the source JATS file's location and base name.
    $basename = $file->getFileNameWithoutExtension() . "_indesign.xml";
    $newPath  = $file->getDirectory() . DIRECTORY_SEPARATOR . $basename;
    $logger->print("Expected :: " . $newPath);

    // Register the output file in the database only if the script produced it.
    // If the script fails silently or writes elsewhere, this block is skipped without error.
    if (file_exists($newPath)) {
        $vals = [
            'name'            => basename($newPath),
            'size'            => filesize($newPath),
            'type'            => filetype($newPath),
            'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
            'user_details_id' => $params['user_details_id'],
            'path'            => $newPath,
            'file_type_id'    => $objDB->preparedGetOne("SELECT id FROM file_type WHERE name = ?", ["xml"]),
        ];

        $id = $objDB->insert("file", $vals);
        $logger->print("Written output file :: $newPath");
        $job->setOutputFileID($id);
    }

    if ($output) {
        $logger->print("Script output :: " . $output);
    }

    $job->updateStatus("FINISHED");
    $logger->writeOutUserLogFile("jatsToIndesign", $user);

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in jatsToIndesign job :: " . $e->getMessage());

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>