<?php
/**
 * Admin Log and Job Queue Service
 *
 * Returns HTML fragments for the admin dashboard, providing views into system
 * logs and the global job queue. The `type` request parameter selects the
 * view returned:
 *
 *   type = "PHP"               → PHP error log tail ({@see phpLog()})
 *   type = "allJobs"           → All jobs currently in the queue
 *   type = "allCompletedJobs"  → All completed (archived) jobs
 *   type = "jobQueue"          → Job output log tail ({@see phpLog(true)})
 *
 * Unlike the user-scoped job queue service, this service operates on global
 * data and is intended for admin use only. Access control is assumed to be
 * handled upstream by {@see Bibliotheca_Content_Page}.
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML fragment for inline page injection, or an error message string
 *         if a log file cannot be found.
 *
 * @package Biblhertz\Publink
 * @see     JobQueue::getAllJobs()
 * @see     JobQueue::getAllCompletedJobs()
 * @see     JobQueuePresentation::getJobQueueAsTable()
 * @see     Config::$LOG_DIR
 * @see     Config::$JOB_LOG_DIR
 * @see     Config::$LOG_LINES_DISPLAY
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\JobQueue;
use Biblhertz\Publink\om\presentation\JobQueuePresentation;
use Biblhertz\Publink\om\presentation\FilePresentation;
use Biblhertz\Publink\Config;

$page = new Bibliotheca_Content_Page();

header('Content-Type: text/html; charset=UTF-8');

$type = $_REQUEST['type'];

if (!strcmp("PHP", $type)) {
    // Tail of the PHP error log
    echo phpLog();
} elseif (!strcmp("allJobs", $type)) {
    // All jobs currently in the global queue
    echo JobQueuePresentation::getJobQueueAsTable(JobQueue::getAllJobs($page->getObjDB()));
} elseif (!strcmp("allCompletedJobs", $type)) {
    // All completed (archived) jobs across all users
    echo JobQueuePresentation::getJobQueueAsTable(JobQueue::getAllCompletedJobs($page->getObjDB()));
} elseif (!strcmp("jobQueue", $type)) {
    // all joblog files
    $files=$page->getObjDB()->preparedSelect("
                    select file.id, file.file_type_id, file.thumbnail_path, file.user_details_id,
                    file.name, file.size, file.type, file.timestamp, file.path,
                    file_type.name as file_extension, file_type.thumbnail as icon
                    from file, file_type 
                    where 
                    path like ?
                    and file_type_id = file_type.id",
                    [Config::$JOB_LOG_DIR."%"]);
    echo FilePresentation::getFileListAsTable($files, $page->getObjDB());
}

$page = null;
exit;


/**
 * Read and render the tail of a log file as an HTML list.
 *
 * Reads the last {@see Config::$LOG_LINES_DISPLAY} lines from either the PHP
 * error log or the job output log, reverses them so the most recent entry
 * appears first, and wraps them in an unstyled Bootstrap `<ul>`.
 *
 * Log file paths:
 *   $job = false  → Config::$LOG_DIR/php_errors.log       (PHP error log)
 *   $job = true   → Config::$JOB_LOG_DIR/job_output_log.txt (job runner output)
 *
 * @param  bool   $job  False for the PHP error log (default), true for the
 *                      job output log.
 * @return string HTML `<ul>` list of the most recent log lines, most recent
 *                first; or an error message string if the log file is missing.
 */
function phpLog($job = false)
{
    $filename = $job
        ? Config::$JOB_LOG_DIR . DIRECTORY_SEPARATOR . 'job_output_log.txt'
        : Config::$LOG_DIR     . DIRECTORY_SEPARATOR . 'php_errors.log';

    if (file_exists($filename)) {
        $file = file($filename);
    }

    if (isset($file) && $file) {
        // Take the last N lines and reverse so newest appears at the top
        $lines = array_reverse(array_slice($file, -Config::$LOG_LINES_DISPLAY));

        $str = "<ul class=\"w-100 h-100 list-unstyled\">";
        foreach ($lines as $line) {
            $str .= "<li>$line</li>";
        }
        $str .= "</ul>";

        return $str;
    }

    return "$filename does not exist";
}


?>