<?php
/**
 * Job Completion Check Service
 *
 * Polls for background jobs that have recently completed for the current user.
 * A "newly completed" job is identified by the status value 'ARCHIVED NEW' in
 * the `job_log` table, which is set by the job runner when a job finishes
 * (successfully or with an error).
 *
 * Workflow:
 *   1. Query `job_log` for any 'ARCHIVED NEW' job belonging to the current user.
 *   2. If found, build a human-readable status string via {@see JobPresentation}.
 *   3. If the job carried an error message, override the status string with a
 *      failure description.
 *   4. Mark the job as fully acknowledged by updating its status to 'ARCHIVED',
 *      preventing it from being returned on the next poll.
 *   5. Return the status string as an HTML fragment for page injection.
 *      Returns an empty string if no newly completed job is found.
 *
 * Job status lifecycle (relevant states):
 *   ... → ARCHIVED NEW  (job runner signals completion)
 *       → ARCHIVED       (this service acknowledges and consumes the notification)
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: Human-readable job status string, or empty string if no new job found.
 *
 * @package Biblhertz\Publink
 * @see     Job
 * @see     JobPresentation::getJobStatusString()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\Job;
use Biblhertz\Publink\om\presentation\JobPresentation;

// -------------------------------------------------------------------------
// Poll for a newly completed job
// -------------------------------------------------------------------------

$page = new Bibliotheca_Content_Page();

/**
 * Fetch the first 'ARCHIVED NEW' job for the current user.
 * Only one job is processed per request; subsequent polls will pick up
 * any remaining newly completed jobs.
 *
 * @var PDOStatement $job
 */
$job = $page->getObjDB()->preparedSelect(
    "SELECT * FROM job_log WHERE user_details_id = ? AND status = 'ARCHIVED NEW'",
    array($page->getUser()->getID())
);

if ($page->getObjDB()->numRows()) {

    // -------------------------------------------------------------------------
    // Build the status string
    // -------------------------------------------------------------------------

    /** @var array $job Associative array of the job_log row */
    $job = $job->fetch();

    $jobObj          = Job::makeNewWithSQLRecord($page->getObjDB(), $job);
    $jobPresentation = new JobPresentation($jobObj);

    /** @var string $status Human-readable job outcome for display */
    $status = $jobPresentation->getJobStatusString();

    // If the job recorded an error, override the status with the failure detail
    if (isset($job['error_message']) && strlen($job['error_message'])) {
        $status = "Job Failed :: " . $job['error_message'];
    }

    // -------------------------------------------------------------------------
    // Acknowledge the job: transition status ARCHIVED NEW → ARCHIVED
    // -------------------------------------------------------------------------

    $page->getObjDB()->update(
        "job_log",
        array('status' => "ARCHIVED"),
        "id=" . $job['id']
    );

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    header('Content-Type: text/html; charset=UTF-8');
    echo $status;

} else {
    // No newly completed job found; return empty response so the caller
    // can distinguish "nothing to report" from an actual status message
    echo "";
}

$page = null;
exit;
?>