<?php
/**
 * Merges BibTeX references into a JATS XML file using an external Python script,
 * registers the updated output file in the database, and marks the job as finished.
 *
 * This handler is executed as a scheduled job. It expects exactly two input files —
 * one JATS XML and one BibTeX — identified by file IDs serialized into $params['files'].
 * The Python script finalxml.py is invoked via shell to perform the actual merge;
 * the updated JATS file is expected to be written to the same directory as the source
 * JATS file, with the suffix "_updated.xml".
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to set output file ID and update status
 * @param  array   $params  Job parameters; must include:
 *                            - 'user_details_id' (int)    ID of the user who owns the job
 *                            - 'files'           (string) Serialized array of exactly two file IDs;
 *                                                         one must be a JATS XML file and one a BibTeX file
 *
 * @throws Exception  If the file count is not exactly two, or if the two files cannot be
 *                    identified as one JATS and one BibTeX. Re-throws any other unexpected
 *                    exception after marking the job as FAILED and logging the error.
 *
 * @note  The output file path is derived from the JATS source file's directory and base name —
 *        it is not a configurable parameter. If finalxml.py fails silently or writes to an
 *        unexpected path, the output file registration block will be skipped without error.
 *
 * @note  $job->updateStatus($cmd) is called before "FINISHED", which will temporarily set
 *        the job status to the raw shell command string. This appears to be a debug artefact.
 *
 * @see   /var/www/job_queue/jats_bibtex/finalxml.py
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

    // Deserialize the list of input file IDs provided by the job dispatcher.
    $files = unserialize($params['files']);

    // Exactly two files are required: one JATS XML and one BibTeX.
    // Fail fast before any further processing if this precondition is not met.
    if (count($files) !== 2) {
        throw new Exception("!! Input file count is not equal to two (requires ONE JATS and ONE BibTeX)");
    }

    $file1 = new File($objDB, $files[0]);
    $file2 = new File($objDB, $files[1]);

    // Determine which file is JATS and which is BibTeX by type detection.
    // Order is not assumed — either file may be either type.
    $jatsFile   = null;
    $bibtexFile = null;

    if ($file1->isJATS())   $jatsFile   = $file1;
    if ($file2->isJATS())   $jatsFile   = $file2;
    if ($file1->isBibTex()) $bibtexFile = $file1;
    if ($file2->isBibTex()) $bibtexFile = $file2;

    if ($bibtexFile === null || $jatsFile === null) {
        throw new Exception("!! Task requires ONE JATS file and ONE BibTeX file as input; these have not been detected");
    }

    $logger->print("JATS     :: " . $jatsFile->getName());
    $logger->print("BibTeX   :: " . $bibtexFile->getName());

    // Invoke the Python script to merge BibTeX references into the JATS XML.
    // Arguments: <bibtex_path> <jats_path>
    // The script is expected to write its output to <jats_directory>/<jats_basename>_updated.xml.
    $path1   = $bibtexFile->getPath();
    $path2   = $jatsFile->getPath();
    $cmd     = "python /var/www/job_queue/jats_bibtex/finalxml.py $path1 $path2";
    $command = escapeshellcmd($cmd);
    $output  = shell_exec($command);

    // Derive the expected output path from the source JATS file's location and name.
    $basename = $jatsFile->getFileNameWithoutExtension() . "_updated.xml";
    $newPath  = $jatsFile->getDirectory() . DIRECTORY_SEPARATOR . $basename;
    $logger->print("Command  :: " . $cmd);
    $logger->print("Expected :: " . $newPath);

    // Register the output file in the database only if the script actually produced it.
    // If the script fails silently, this block is skipped — no error is raised in that case.
    if (file_exists($newPath)) {
        $vals = [
            'name'            => basename($newPath),
            'size'            => filesize($newPath),
            'type'            => filetype($newPath),
            'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
            'user_details_id' => $params['user_details_id'],
            'path'            => $newPath,
            'file_type_id'    => $objDB->preparedGetOne("SELECT id FROM file_type WHERE name = ?", ["JATS xml"]),
        ];

        $id = $objDB->insert("file", $vals);
        $logger->print("Written output file :: $newPath");
        $job->setOutputFileID($id);
    }

    if ($output) {
        $logger->print("Script output :: " . $output);
    }

    $job->updateStatus("FINISHED");
    $logger->writeOutUserLogFile("bibjatsReferences", $user);

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in bibjatsReferences job :: " . $e->getMessage());

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>