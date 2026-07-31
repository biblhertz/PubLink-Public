<?php
/**
 * Beanstalkd job queue worker process.
 *
 * This script is intended to run as a long-lived background process. It connects to a
 * Beanstalkd queue server, watches the 'general_tube', and continuously polls for jobs.
 * When a job is dequeued, it:
 *   1. Decodes the JSON job payload and extracts parameters
 *   2. Opens a fresh database connection for the job
 *   3. Includes and executes the PHP script specified in the job payload
 *   4. Archives the completed job and writes the log file
 *   5. Removes the job from the queue
 *
 * On per-job exceptions, the worker logs the error, archives the failure against the
 * job record, deletes the job from the queue, and continues watching for the next job.
 * Fatal setup errors (e.g. Beanstalkd unavailable) are logged and cause the process to exit.
 *
 * Job payload format (JSON):
 * {
 *   "parameters": {
 *     "job_id": 123,
 *     "script": "relative/path/to/handler.php",
 *     ... (any additional params passed through to the included script as $params)
 *   }
 * }
 *
 * @note  The 'reference_tube' watch is commented out. Re-enable and add a second worker
 *        process if reference enrichment jobs need to be processed separately.
 *
 * @note  On exception, the worker issues a DELETE against the job table before archiving.
 *        This removes the raw job row from the database, which may cause data loss if
 *        archiveError() depends on it. Verify the intended order of operations.
 *
 * @note  Scripts are executed via include(), meaning they share the worker's variable
 *        scope. The included handler scripts receive $objDB, $job, $params, and $logger
 *        as implicit context rather than through defined function signatures.
 *
 * @note  If $params['script'] is not set or the file does not exist, the job is silently
 *        archived as complete with no work performed. Consider adding an explicit error
 *        for this case.
 *
 * @see   Biblhertz\Publink\om\Job
 * @see   Biblhertz\Publink\utilities\Logger
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\utilities\PDODatabase;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Publink\om\Job;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\Config;
use Pheanstalk\Pheanstalk;
use DateTime;

// Bootstrap configuration and start the queue worker.
// Any exception here is fatal — the process cannot start without a valid config.
Config::setup();
queueWatch();


/**
 * Connects to the Beanstalkd server and enters an infinite loop to process queued jobs.
 *
 * Opens a Beanstalkd connection, watches 'general_tube', and blocks on reserve() waiting
 * for work. Each reserved job is decoded, its handler script included, and the job archived
 * before being deleted from the queue.
 *
 * Per-job exceptions are caught inside the loop: the failed job is removed from the queue,
 * the error is archived against the job record, and the loop continues with the next job.
 * Fatal exceptions (e.g. loss of Beanstalkd connection) propagate out of the function.
 *
 * @return void  Does not return under normal operation; exits only on fatal error.
 */
function queueWatch(): void
{
   
    $pheanstalk = Pheanstalk::create('beanstalkd');

    // Watch the general job tube.
    // Uncomment the reference_tube line to also process reference enrichment jobs.
    $pheanstalk->watch('general_tube');
    // $pheanstalk->watch('reference_tube');

    // Poll indefinitely for jobs.
    while (true) {
        // Initialise per-job variables so the catch block can safely check isset()
        // even if an exception is thrown before they are assigned.
        $objDB = null;
        $job   = null;
        $wjob  = null;
        $jobId = null;

        try {
            // reserve() blocks until a job is available and locks it against other workers.
            // If it returns falsy (no job available), loop and poll again.
            if (!$wjob = $pheanstalk->reserve()) {
                continue;
            }

            // Decode the JSON job payload and extract parameters.
            $logger     = new Logger();
            $data    = $wjob->getData();
            $dataArr = json_decode($data, true);
            $params  = $dataArr['parameters'];
            $jobId   = $params['job_id'];

            // Open a fresh database connection per job to avoid stale connection issues
            // in a long-running process.
            $objDB = new PDODatabase();
            $job   = new Job($objDB, $jobId);

            // If the DB row is gone or the job was cancelled before the worker
            // picked it up, discard the Beanstalkd entry and archive the record.
            if ($job->getName() === '' || $job->isCancelled()) {
                $logger->print("Job $jobId cancelled before execution — discarding.");
                $pheanstalk->delete($wjob);
                if ($job->getName() !== '') {
                    // Unlock the serialized object so the article can be edited again.
                    unlockJobObject($objDB, $job, $params, $logger);
                    $job->generateLogFileName();
                    try {
                        $job->archiveError("Cancelled by administrator");
                    } catch (\Throwable $archiveEx) {
                        $logger->print("Failed to archive cancelled job: " . $archiveEx->getMessage());
                    }
                    $logger->writeOutLogFile($job->getLogFileName());
                } else {
                    $logger->writeOutLogFile(Config::$JOB_LOG_DIR . DIRECTORY_SEPARATOR . 'cancelled_' . $jobId . '.log');
                }
                $job = null; $objDB = null; $wjob = null;
                continue;
            }

            $logger->print("Task   :: " . $job->getTask()->getName() . "  [job_id: " . $jobId . "]");
            $logger->print("User   :: " . $job->getUser()->getName());
            $logger->print("Script :: " . ($params['script'] ?? 'none'));
            $logger->print("Start  :: " . date('Y-m-d H:i:s'));
            $logger->println();

            if (isset(Config::$SCHEDULER_DEBUG)) {
                debug($data, $logger);
            }

            // Validate and include the handler script specified in the job payload.
            // The script executes in this scope and has implicit access to $objDB, $job,
            // $params, and $logger. If the script key is missing or the file does not
            // exist, the job is archived as complete with no work performed.
            if (isset($params['script']) &&
                file_exists(Config::$JOB_QUEUE_DIR . DIRECTORY_SEPARATOR . $params['script'])) {

                $script = Config::$JOB_QUEUE_DIR . DIRECTORY_SEPARATOR . $params['script'];

                if (isset(Config::$SCHEDULER_DEBUG)) {
                    $logger->print("Including Script :: " . $script);
                }

                include($script);
            }

            // Archive the completed job record and flush the log to disk.
            $job->generateLogFileName();
            $job->archiveJob();
            $logger->print("Finish :: " . date('Y-m-d H:i:s'));
            $logger->print("Output :: " . ($job->getOutputFileID() > 0 ? "file_id " . $job->getOutputFileID() : "none"));
            $logger->println();
            $logger->writeOutLogFile($job->getLogFileName());

            // Build the metadata record for the generated file.
            $jvals = [
                'name'            => basename($job->getLogFileName()),
                'size'            => filesize($job->getLogFileName()),
                'type'            => "text/xml",
                'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
                //assign log file to a super user in the system
                'user_details_id' => $objDB->getOne("select id from user_details where user_group_id =(select id from user_group where name = 'Super User')"), 
                'path'            => $job->getLogFileName(),
            ];

            // Resolve the file type ID by matching the output file's extension against the file_type table.
            $typeResult = $objDB->preparedSelect(
                    "SELECT id, type FROM file_type WHERE name = ?",
                    [File::getFileExtensionFromBaseName($jvals['name'])]
                );
                $jtype = $typeResult->fetch();

            if (!$jtype) {
                // The file extension is not registered in the system — the file record will lack a type ID.
                // This is non-fatal but should be investigated; the XML file has already been written.
                $logger->print("!!! Generated file type is not recognised by the system :: " . $outputFilePath);
            } else {
                $jvals['file_type_id'] = $jtype['id'];
            }

            // Insert the file record and link it to the job.
            $objDB->insert("file", $jvals);

            // Release references to close the DB connection before the next job.
            $objDB = null;
            $job   = null;

            // Remove the completed job from the Beanstalkd queue.
            $pheanstalk->delete($wjob);

        } catch (\Throwable $e) {
            // Log the error with full stack trace.
            error($e, $logger);

            // Remove the failed job from the Beanstalkd queue so it does not re-run.
            if ($wjob !== null) {
                $pheanstalk->delete($wjob);
                $logger->print("Job deleted from queue.");
            }

            // Attempt to delete the raw job row from the database and archive the error.
            // Note: the DELETE runs before archiveError() — if archiveError() depends on
            // the job row existing, this ordering may cause data loss. Review if needed.
            if ($objDB !== null && $jobId !== null) {
                $objDB->preparedSelect("DELETE FROM job WHERE id = ?", [$jobId]);
                $logger->print("Job deleted from database.");
            }

            if ($job !== null) {
                $job->generateLogFileName();
                try {
                    $job->archiveError($e->getMessage());
                } catch (Exception $archiveEx) {
                    $logger->print("Failed to archive job error: " . $archiveEx->getMessage());
                }
                $logger->writeOutLogFile($job->getLogFileName());
            } else {
                $logger->print("Unable to archive job error: Job object was not initialised.");
                $dt = new DateTime('now');
                $fallbackPath = Config::$JOB_LOG_DIR . DIRECTORY_SEPARATOR
                    . 'error_' . ($jobId ?? 'unknown') . '_' . $dt->format('d-m-Y_H_i_s') . '.log';
                $logger->writeOutLogFile($fallbackPath);
            }

            // Release references and continue watching for the next job.
            $job   = null;
            $objDB = null;
            continue;
        }
    }
}


/**
 * Logs a job queue error with full stack trace.
 *
 * @param  Exception  $e       The caught exception
 * @param  Logger     $logger  Logger instance to write to
 * @return void
 */
function error(Exception $e, Logger $logger): void
{
    $logger->println();
    $logger->print("Job Queue Error :: " . $e->getMessage());
    $logger->print($e->getTraceAsString());
    $logger->println();
}


/**
 * Logs the raw job payload and extracted script parameter for debug inspection.
 *
 * Only called when Config::$SCHEDULER_DEBUG is set.
 *
 * @param  string  $data    Raw JSON job payload string from the queue
 * @param  Logger  $logger  Logger instance to write to
 * @return void
 */
function debug(string $data, Logger $logger): void
{
    $dataArr = json_decode($data, true);
    $params  = $dataArr['parameters'];

    $logger->println();
    $logger->print("Worker received payload :: " . $data);
    $logger->print("Script :: "                  . $params['script']);
    $logger->println();
}


/**
 * Clears the readOnly and referenceCheck flags on a serialized article when its
 * job is cancelled before or during execution.
 *
 * Only acts on Reference Checker jobs — other job types do not lock the article.
 * Errors are logged but do not propagate; a failed unlock must not crash the worker.
 *
 * @param  Job    $job     The cancelled Job instance.
 * @param  array  $params  Decoded job parameters (must include 'object_id').
 * @param  Logger $logger  Worker logger for progress output.
 */
function unlockJobObject(PDODatabase $objDB, Job $job, array $params, Logger $logger): void
{
    if (stripos($job->getTask()->getName(), 'Reference Checker') === false) return;

    $oid = isset($params['object_id']) ? (int) $params['object_id'] : 0;
    if ($oid <= 0) return;

    try {
        $obj     = new SerializedObject($objDB, $oid);
        $article = unserialize($obj->getObject());
        if (is_object($article) && method_exists($article, 'setReferenceCheck')) {
            $article->setReferenceCheck(false);
            $article->setReadOnly(false);
            $obj->updateObject($article);
            $logger->print("Unlocked serialized object $oid for editing.");
        }
    } catch (\Throwable $e) {
        $logger->print("Warning: could not unlock object $oid: " . $e->getMessage());
    }
}
?>