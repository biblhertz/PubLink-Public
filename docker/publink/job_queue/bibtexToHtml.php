<?php
/**
 * Converts a BibTeX file to a formatted HTML bibliography using an external Python
 * script, registers the output file in the database, and marks the job as finished.
 *
 * This handler is executed as a scheduled job. It validates that the input file is
 * BibTeX, then invokes biblio2.py via shell with a CSL stylesheet and an XSLT
 * transform to produce an HTML output file. The result is registered in the `file`
 * table and linked to the job.
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to set output file ID and update status
 * @param  array   $params  Job parameters; must include:
 *                            - 'user_details_id' (int) ID of the user who owns the job
 *                            - 'file_id'         (int) ID of the BibTeX input file to process
 *
 * @throws Exception  If 'file_id' is not set in $params, or if the referenced file is
 *                    not a BibTeX file. Re-throws any other unexpected exception after
 *                    marking the job as FAILED and logging the error.
 *
 * @note  The output file path is derived from the input file's directory and base name —
 *        it is not configurable. The suffix "_out.html" is appended to the base name.
 *        If biblio2.py fails silently or writes to an unexpected path, the output file
 *        registration block is skipped without raising an error.
 *
 * @note  $job->updateStatus($cmd) is called before "FINISHED", temporarily setting the
 *        job status to the raw shell command string. This appears to be a debug artefact
 *        and is overwritten immediately by the "FINISHED" status update.
 *
 * @see   /var/www/job_queue/jats2indesign/biblio2.py
 * @see   /var/www/job_queue/jats2indesign/biblhertz.csl
 * @see   /var/www/job_queue/jats2indesign/htmlbiblio2xml.xslt
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

    // Only BibTeX files are supported as input; fail fast if the type is incorrect.
    if (!$file->isBibtex()) {
        throw new Exception("!! Task requires a BibTeX file as input; the provided file is a different type");
    }

    // Derive the output path from the input file's location and base name.
    // The output is written to the same directory as the source file.
    $path     = $file->getPath();
    $basename = $file->getFileNameWithoutExtension() . "_out.html";
    $newPath  = $file->getDirectory() . DIRECTORY_SEPARATOR . $basename;
    $logger->print("Input    :: " . $file->getName());
    $logger->print("Expected :: " . $newPath);

    // Fixed paths for the CSL citation stylesheet and XSLT transform used by the script.
    $xslt = "/var/www/job_queue/jats2indesign/htmlbiblio2xml.xslt";
    $csl  = "/var/www/job_queue/jats2indesign/biblhertz.csl";

    // Build and execute the shell command.
    // biblio2.py accepts: -i <input bibtex> -o <output html> -c <csl> -x <xslt>
    // Note: individual path arguments should ideally be wrapped with escapeshellarg()
    // to guard against filenames containing spaces or special characters.
    $cmd     = "python /var/www/job_queue/jats2indesign/biblio2.py -i $path -o $newPath -c $csl -x $xslt";
    $command = escapeshellcmd($cmd);
    $logger->print("Command  :: " . $cmd);
    $output  = shell_exec($command);

    // Register the output file in the database only if the script produced it.
    // If the script fails silently, this block is skipped without raising an error.
    if (file_exists($newPath)) {
        $vals = [
            'name'            => basename($newPath),
            'size'            => filesize($newPath),
            'type'            => filetype($newPath),
            'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
            'user_details_id' => $params['user_details_id'],
            'path'            => $newPath,
            'file_type_id'    => $objDB->preparedGetOne("SELECT id FROM file_type WHERE name = ?", ["html"]),
        ];

        $id = $objDB->insert("file", $vals);
        $logger->print("Written output file :: $newPath\n");
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