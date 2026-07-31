<?php
/**
 * JATS to InDesign Handler
 *
 * Validates and enqueues a background job to convert a JATS XML file to
 * InDesign format via the `jatsToIndesign.php` worker script. Performs
 * authorisation checks at task and file level before creating the job.
 *
 * This is a single-file variant of the file-based task handler pattern,
 * identical in structure to the BibTeX to HTML and image conversion handlers.
 * It represents the export leg of the JATS workflow: files imported via the
 * JATS upload service can be converted here for InDesign-based layout.
 *
 * Request parameters (POST):
 *   task_id             int   ID of the task to execute. Must be numeric.
 *   {task_codename}     int   ID of the JATS file to convert. The field name
 *                             is derived from {@see Task::getCodeName()}.
 *
 * Authorisation checks:
 *   1. Task-level:  {@see Task::canExecute()} — user must have permission to
 *                   run this task.
 *   2. File-level:  {@see File::canExecute()} — user must have permission to
 *                   act on the selected file.
 *
 * Job lifecycle:
 *   Follows the two-phase save pattern: the job is saved once to obtain a job
 *   ID, then saved again after the parameters (which include the job ID) have
 *   been set.
 *
 * Worker script:
 *   The enqueued job runs `jatsToIndesign.php` with parameters:
 *     script           string   Worker script name
 *     file_id          int      JATS source file ID
 *     user_details_id  int      Executing user's ID
 *     task_id          int      Task ID
 *     job_id           int      Job ID (for status reporting)
 *
 * Redirect:
 *   On success, redirects to `../myTasks.html` with `task_id` and `message`
 *   as query parameters.
 *
 * Debug logging:
 *   When {@see Config::$SCHEDULER_DEBUG} is set, POST data, the task code name,
 *   and the resolved file ID and path are written to the error log.
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
    // Resolve file ID and check per-file authorisation
    // -------------------------------------------------------------------------

    $fileId = $_POST[$task->getCodeName()];

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Post message received :: " . serialize($_POST));
        error_log("task->getCodeName() :: " . $task->getCodeName());
        error_log("File id :: " . $fileId);
    }

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
        "script"          => "jatsToIndesign.php",
        "file_id"         => $file->getID(),
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

    $jobPresentation = new JobPresentation($job);
    $message         = $jobPresentation->getSubmitMessage();
    header("Location: ../myTasks.html?task_id=" . $task->getID() . "&&message=$message");

} catch (Exception $e) {
    $page->handleException($e);
}

?>