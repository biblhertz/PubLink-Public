<?php
/**
 * Job Queue Service
 *
 * Returns an HTML table of jobs from the current user's queue, filtered by
 * the requested job state. Intended for AJAX calls that refresh individual
 * queue panels in the UI without a full page reload.
 *
 * The `type` request parameter determines which job state is rendered.
 * The value is matched via substring so compound type strings are supported
 * (e.g. "waiting_jobs"). Exactly one table is returned per request — the
 * first matching state wins:
 *
 *   type contains "waiting"  → waiting jobs table  ({@see JobQueue::getWaitingJobsAsTable()})
 *   type contains "running"  → running jobs table  ({@see JobQueue::getRunningJobsAsTable()})
 *   type contains "logged"   → logged jobs table   ({@see JobQueue::getLoggedJobsAsTable()})
 *
 * If the queue holds no jobs of the requested state an empty response is
 * returned, allowing the caller to treat an empty body as "nothing to show".
 *
 * Exceptions are delegated to {@see Bibliotheca_Content_Page::handleException()}
 * for centralised error handling, including a missing or malformed `type`
 * parameter.
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML job table fragment, or empty string if no matching jobs exist.
 *
 * @package Biblhertz\Publink
 * @see     JobQueue
 * @see     JobQueue::getJobsForAUser()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\JobQueue;

$page = new Bibliotheca_Content_Page();

header('Content-Type: text/html; charset=UTF-8');

try {
    /** @var string $type Job state filter; expected to contain "waiting", "running", or "logged" */
    $type  = $_REQUEST['type'];
    $queue = new JobQueue($page->getObjDB());

    // Load all jobs for the current user into the queue object
    $queue->getJobsForAUser($page->getUser()->getID(), $page->getObjDB());

    // Return the table for the first matching job state
    if ($queue->hasWaitingJobs() && strpos($type, "waiting") > 0) {
        echo $queue->getWaitingJobsAsTable();
    } elseif ($queue->hasRunningJobs() && strpos($type, "running") > 0) {
        echo $queue->getRunningJobsAsTable();
    } elseif ($queue->hasLoggedJobs() && strpos($type, "logged") > 0) {
        echo $queue->getLoggedJobsAsTable();
    }

    $page = null;
    exit;

} catch (Exception $e) {
    $page->handleException($e->getMessage());
}

?>