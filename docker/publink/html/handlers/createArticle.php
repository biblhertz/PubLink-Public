<?php
/**
 * Create Article Handler
 *
 * Validates and enqueues a background job to create an OJS article from one
 * or more uploaded files via the `createArticle.php` worker script. Performs
 * authorisation checks at task and file level before creating the job.
 *
 * This is a multi-file checkbox variant of the file-based task handler pattern,
 * structurally identical to the BibTeX/JATS reference import handler but
 * targeting OJS article creation. An optional `ojs_user` parameter allows the
 * submitting user's OJS identity to be passed to the worker.
 *
 * Request parameters (POST):
 *   task_id               int     ID of the task to execute. Must be numeric.
 *   {task_codename}_cb    int[]   File IDs to process, submitted as a checkbox
 *                                 array. The field name prefix is derived from
 *                                 {@see Task::getCodeName()}.
 *   ojs_user              string  (optional) OJS username to associate with the
 *                                 created article. Passed through to the worker
 *                                 if present.
 *
 * Authorisation checks:
 *   1. Task-level:  {@see Task::canExecute()} — user must have permission to
 *                   run this task.
 *   2. File-level:  {@see File::canExecute()} — user must have permission to
 *                   act on each selected file.
 *
 * Job lifecycle:
 *   Follows the two-phase save pattern: the job is saved once to obtain a job
 *   ID, then saved again after the parameters (which include the job ID) have
 *   been set.
 *
 * Worker script:
 *   The enqueued job runs `createArticle.php` with parameters:
 *     script           string   Worker script name
 *     files            string   Serialized array of file IDs
 *     user_details_id  int      Executing user's ID
 *     task_id          int      Task ID
 *     job_id           int      Job ID (for status reporting)
 *     ojs_user         string   (optional) OJS username, if supplied in POST
 *
 * Redirect:
 *   On success, redirects to `../myTasks.html` with `task_id` and `message`
 *   as query parameters.
 *
 * Debug logging:
 *   When {@see Config::$SCHEDULER_DEBUG} is set, POST data, the task code name,
 *   file count, and each selected file path are written to the error log.
 *
 * Note: cover file and galley file parameters (`cover_file`, `create_article_cb`)
 * are present as commented-out code, suggesting a planned or partially
 * implemented extension for attaching cover images and galley files to the
 * created article. These can be re-enabled by uncommenting the relevant blocks
 * and passing them through in the parameters array.
 *
 * Note: as with the BibTeX/JATS handler, there is no guard against a missing
 * `{codename}_cb` POST field — if absent, the `foreach` will emit a warning.
 * Adding `isset($_POST[$task->getCodeName() . "_cb"])` before the loop would
 * be safer.
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

    // Planned extension: cover image and galley file support (currently unused)
    // if (isset($_POST['cover_file'])) $coverFileId = $_POST['cover_file'];
    // if (isset($_POST['create_article_cb'])) $galleyFiles = $_POST['create_article_cb'];

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Post message received :: " . serialize($_POST));
        error_log("Codename :: " . $task->getCodeName());
        error_log("# Files :: " . count($files));
        if (isset($coverFileId)) error_log("File id :: " . $coverFileId);
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
        "script"          => "createArticle.php",
        "files"           => serialize($files),
        "user_details_id" => $page->getUser()->getID(),
        "task_id"         => $task->getID(),
        "job_id"          => $jobID
    );

    // Planned extension: uncomment to pass cover and galley files to worker
    // if (isset($coverFileId)) $parameters["cover_file"] = $coverFileId;
    // if (isset($galleyFiles)) $parameters["galley_files"] = $galleyFiles;

    // Pass OJS username through to the worker if provided
    if (isset($_POST['ojs_user'])) {
        $parameters["ojs_user"] = $_POST['ojs_user'];
    }

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