<?php
/**
 * PDF Image Extract Handler
 *
 * Validates and enqueues a background job to extract images from a PDF file
 * via the `pdfImageExtract.php` worker script. Performs authorisation checks
 * at task level before creating the job.
 *
 * Request parameters (POST):
 *   task_id             int   ID of the task to execute. Must be numeric.
 *   {task_codename}     int   File ID of the PDF to process. The field name is
 *                             derived from {@see Task::getCodeName()}.
 *
 * Job lifecycle:
 *   Follows the two-phase save pattern: the job is saved once to obtain a job
 *   ID, then saved again after the parameters (which include the job ID) have
 *   been set.
 *
 * Worker script:
 *   The enqueued job runs `pdfImageExtract.php` with parameters:
 *     script           string   Worker script name
 *     file_id          int      PDF source file ID
 *     user_details_id  int      Executing user's ID
 *     task_id          int      Task ID
 *     job_id           int      Job ID (for status reporting)
 *     output_type      string   Extraction mode; hardcoded to "all"
 *
 * Redirect:
 *   On success, redirects to `../myTasks.html` with `task_id` and `message`
 *   as query parameters.
 *
 * Debug logging:
 *   When {@see Config::$SCHEDULER_DEBUG} is set, POST data, the task code name,
 *   and the resolved field value are written to the error log.
 *
 * *** BUGS — this handler has two bugs that will cause runtime failures: ***
 *
 *   1. Variable name mismatch: the POST field is read into `$doi` (a
 *      misleading name for a file ID), but the parameters array references
 *      `$file->getID()` — `$file` is never instantiated, causing a fatal
 *      "undefined variable" error. The fix is to instantiate `$file`:
 *        $file = new File($page->getObjDB(), $doi);
 *      or rename `$doi` to `$fileId` and use it directly in the parameters.
 *
 *   2. Incorrect validation: `is_numeric($doi)` is the right check, but the
 *      exception message says "File ID not defined" while the variable is
 *      named `$doi` — the field appears to carry a file ID, not a DOI.
 *      The variable should be renamed to `$fileId` for clarity.
 *
 * Output:
 *   Success: HTTP redirect to myTasks.html with status message.
 *   Failure: Delegated to {@see Bibliotheca_Content_Page::handleException()}.
 *
 * @package Biblhertz\Publink
 * @see     Task::canExecute()
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
    // Resolve file ID
    // Note: variable is named $doi but carries a file ID — should be $fileId
    // -------------------------------------------------------------------------

    $id = $_POST[$task->getCodeName()];

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Post message received :: " . serialize($_POST));
        error_log("task->getCodeName() :: " . $task->getCodeName());
        error_log("DOI :: " . $id);
    }

    if (!isset($id) || !is_numeric($id)) {
        throw new Exception("File ID not defined in handler");
    }

    // BUG: $file is never instantiated — $doi holds the file ID but File is
    // never constructed. The parameters array below will fatal on $file->getID().
    // Fix: add the following line here:
    // $file = new File($page->getObjDB(), $doi);

    // -------------------------------------------------------------------------
    // Create and enqueue the job (two-phase save)
    // -------------------------------------------------------------------------

    $job = new Job($page->getObjDB());
    $job->setTask($task);
    $job->setUser($page->getUser());

    // Phase 1: save to obtain the job ID
    $jobID = $job->saveJob();

    // Build parameters including the job ID for the worker script
    // Note: $file->getID() will fatal until the bug above is fixed
    $parameters = array(
        "script"          => "pdfImageExtract.php",
        "file_id"         => $file->getID(),        // BUG: $file undefined
        "user_details_id" => $page->getUser()->getID(),
        "task_id"         => $task->getID(),
        "job_id"          => $jobID,
        "output_type"     => "all"                  // Extract all image types
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