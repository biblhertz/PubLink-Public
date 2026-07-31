<?php
/**
 * BibTeX/JATS Reference Import Handler
 *
 * Validates and enqueues a background job to process one or more uploaded
 * BibTeX or JATS files for reference extraction via the `bibjatsReferences.php`
 * worker script. Performs authorisation checks at task and file level before
 * creating the job.
 *
 * This is a file-based multi-select variant of the task handler pattern.
 * Unlike the object-based handlers, the target entities here are File records
 * rather than SerializedObjects — the worker operates directly on the uploaded
 * source files.
 *
 * Request parameters (POST):
 *   task_id                int    ID of the task to execute. Must be numeric.
 *   {task_codename}_cb     int[]  File IDs to process, submitted as a checkbox
 *                                 array. The field name prefix is derived from
 *                                 {@see Task::getCodeName()}.
 *
 * Authorisation checks:
 *   1. Task-level:  {@see Task::canExecute()} — user must have permission to
 *                   run this task.
 *   2. File-level:  {@see File::canExecute()} — user must have permission to
 *                   act on each selected file.
 *
 * Job lifecycle:
 *   Follows the two-phase save pattern: the job is saved once to obtain a job
 *   ID, then saved again after the parameters (including the job ID) have been
 *   set. See the task handler service for full details.
 *
 * Worker script:
 *   The enqueued job runs `bibjatsReferences.php` with parameters:
 *     script           string   Worker script name
 *     files            string   Serialized array of file IDs
 *     user_details_id  int      Executing user's ID
 *     task_id          int      Task ID
 *     job_id           int      Job ID (for status reporting)
 *
 * Redirect:
 *   On success, redirects to `../myTasks.html` (rather than the HTTP referrer
 *   as in other handlers) with `task_id` and `message` as query parameters.
 *   Note: no `oid` parameter is appended since this handler operates on files
 *   rather than serialized objects.
 *
 * Debug logging:
 *   When {@see Config::$SCHEDULER_DEBUG} is set, POST data, the task code name,
 *   and the count of selected files are written to the error log.
 *
 * Note: unlike the multi-object handler, this handler does not guard against
 * a missing or empty `{codename}_cb` array — if the checkbox field is absent
 * from the POST data, the `foreach` will throw a warning. A guard with
 * `isset($_POST[$task->getCodeName() . "_cb"])` before the loop would be safer.
 *
 * Output:
 *   Success: HTTP redirect to myTasks.html with status message.
 *   Failure: Delegated to {@see Bibliotheca_Content_Page::handleException()}.
 *
 * @package Biblhertz\Publink
 * @see     Task::canExecute()
 * @see     File::canExecute()
 * @see     Job::putInQueue()
 * @see     JobPresentation::getSubmitMessage()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\om\Job;
use Biblhertz\Publink\om\presentation\JobPresentation;
use Biblhertz\Publink\Config;

$page = new Bibliotheca_Content_Page();

try {

    // -------------------------------------------------------------------------
    // Validate task and check user authorisation
    // -------------------------------------------------------------------------

    if (!isset($_POST['task_id']) || !is_numeric($_POST['task_id'])) {
        throw new Exception("Task ID not defined in handler");
    }

    $task = new Task($page->getObjDB(), $_POST['task_id']);

    if (!$task->canExecute($page->getUser())) {
        throw new Exception(
            "User :: " . $page->getUser()->getName() . " does not have the right to execute this task"
        );
    }

    // -------------------------------------------------------------------------
    // Resolve selected file IDs from checkbox array
    // -------------------------------------------------------------------------

    $files = array();
    foreach ($_POST[$task->getCodeName() . "_cb"] as $oid) {
        $files[] = $oid;
    }

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Post message received :: " . serialize($_POST));
        error_log("Codename :: " . $task->getCodeName());
        error_log("# Files :: " . count($files));
    }

    // -------------------------------------------------------------------------
    // Validate each file and check per-file authorisation
    // -------------------------------------------------------------------------

    foreach ($files as $fileId) {

        if (!isset($fileId) || !is_numeric($fileId)) {
            throw new Exception("File ID not defined in handler");
        }

        $file = new File($page->getObjDB(), $fileId);

        if (isset(Config::$SCHEDULER_DEBUG)) {
            error_log("Selected File:: " . $file->getPath());
        }

        if (!$file->canExecute($page->getUser()->getID())) {
            throw new Exception(
                "User :: " . $page->getUser()->getName()
                . " does not have the right to execute this task on the file selected :: "
                . $file->getName() . " with ID " . $file->getID()
            );
        }
    }

    // -------------------------------------------------------------------------
    // Create and enqueue the job (two-phase save)
    // -------------------------------------------------------------------------

    $job = new Job($page->getObjDB());
    $job->setTask($task);
    $job->setUser($page->getUser());

    // Phase 1: save to obtain the job ID
    $jobID = $job->saveJob();

    // Build parameters including the job ID for the worker script
    $parameters = array(
        "script"          => "bibjatsReferences.php",
        "files"           => serialize($files),
        "user_details_id" => $page->getUser()->getID(),
        "task_id"         => $task->getID(),
        "job_id"          => $jobID
    );

    // Phase 2: save again with the complete parameter set
    $job->setParameters($parameters);
    $jobID = $job->saveJob();

    $job->putInQueue();

    // -------------------------------------------------------------------------
    // Redirect to the tasks page with a confirmation message
    // -------------------------------------------------------------------------

    $present = new JobPresentation($job);
    $message = $present->getSubmitMessage();
    header("Location: ../myTasks.html?task_id=" . $task->getID() . "&&message=$message");

} catch (Exception $e) {
    $page->handleException($e);
}

?>