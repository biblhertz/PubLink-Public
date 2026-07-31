<?php
/**
 * Reference Checker Handler (Legacy)
 *
 * Validates and enqueues a background job to run an automated reference check
 * on a serialized Article object via the `referenceChecker.php` worker script.
 * Locks the article against editing before enqueuing to prevent concurrent
 * modifications during the check.
 *
 * This is the earlier version of the reference checker handler. It differs
 * from the current version ({@see newReferenceChecker handler}) in two ways:
 *   - Worker script: `referenceChecker.php` (vs. `newReferenceChecker.php`)
 *   - Redirect: returns to HTTP referrer (vs. hardcoded `uploadJATS.html`)
 *
 * The object ID field uses the task code name as its field name (consistent
 * with other object-based handlers), unlike the newer handler which uses the
 * fixed field name "oid".
 *
 * Request parameters (POST):
 *   task_id             int   ID of the task to execute. Must be numeric.
 *   {task_codename}     int   ID of the serialized Article object to check.
 *                             Field name derived from {@see Task::getCodeName()}.
 *
 * Authorisation checks:
 *   1. Task-level:   {@see Task::canExecute()} — user must have permission to
 *                    run this task.
 *   2. Object-level: {@see SerializedObject::canExecute()} — user must have
 *                    permission to act on the selected object.
 *
 * Pre-flight state check:
 *   If the article's reference check flag is already set, the handler throws
 *   an exception to prevent a duplicate job. As with the newer handler, the
 *   `header()` and `exit` following the `throw` are unreachable dead code.
 *
 * Article locking:
 *   Before enqueuing, `referenceCheck` and `readOnly` are set to `true` and
 *   persisted. These flags are cleared by the reference check status service
 *   when the job completes.
 *
 * Job lifecycle:
 *   Follows the two-phase save pattern with an intermediate status update,
 *   identical to the newer reference checker handler.
 *
 * Worker script:
 *   The enqueued job runs `referenceChecker.php` with parameters:
 *     script           string   Worker script name
 *     object_id        int      Serialized Article object ID
 *     user_details_id  int      Executing user's ID
 *     task_id          int      Task ID
 *     job_id           int      Job ID (for status reporting)
 *
 * Redirect:
 *   On success, redirects to the HTTP referrer with `task_id` and `message`
 *   appended as query parameters.
 *
 * Debug logging:
 *   When {@see Config::$SCHEDULER_DEBUG} is set, POST data, the task code name,
 *   and the resolved object ID and name are written to the error log.
 *
 * Note: `use Biblhertz\Publink\om\File` is imported but unused — safe to remove.
 *
 * Output:
 *   Success: HTTP redirect to referrer with status message.
 *   Failure: Delegated to {@see Bibliotheca_Content_Page::handleException()}.
 *
 * @package Biblhertz\Publink
 * @see     Task::canExecute()
 * @see     SerializedObject::canExecute()
 * @see     Job::putInQueue()
 * @see     Job::updateStatus()
 * @see     JobPresentation::getSubmitMessage()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\om\File;                  // unused
use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\om\Job;
use Biblhertz\Publink\om\presentation\JobPresentation;
use Biblhertz\Publink\Config;

$page = new Bibliotheca_Content_Page();

try {

    // Capture referrer for redirect on success
    $refURL = $_SERVER['HTTP_REFERER'];
    $char   = strpos($refURL, "?") ? "&&" : "?";

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
    // Validate object and check per-object authorisation
    // -------------------------------------------------------------------------

    $objectId = $_POST[$task->getCodeName()];

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Post message received :: " . serialize($_POST));
        error_log("Codename :: " . $task->getCodeName());
        error_log("Object id :: " . $objectId);
    }

    if (!isset($objectId) || !is_numeric($objectId)) {
        throw new Exception("Object ID not defined in handler");
    }

    $object = new SerializedObject($page->getObjDB(), $objectId);

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Selected Object:: " . $object->getName());
    }

    if (!$object->canExecute($page->getUser()->getID())) {
        throw new Exception(
            "User :: " . $page->getUser()->getName()
            . " does not have the right to execute this task on the object selected :: "
            . $object->getName() . " with ID " . $object->getID()
        );
    }

    // -------------------------------------------------------------------------
    // Pre-flight: reject if a reference check is already in progress
    // -------------------------------------------------------------------------

    $article = unserialize($object->getObject());

    if ($article->getReferenceCheck()) {
        // Note: header() and exit below are unreachable — throw halts execution
        throw new Exception("Reference Check has already been carried out on the object :: " . $object->getName());
        header("Location: $refURL" . $char . "&&task_id=" . $task->getID() . "&&message=Reference Check has already been carried out");
        exit;
    }

    // -------------------------------------------------------------------------
    // Lock the article against editing for the duration of the check
    // Flags are cleared by the reference check status service on completion
    // -------------------------------------------------------------------------

    $article->setReferenceCheck(true);
    $article->setReadOnly(true);
    $object->updateObject($article);

    // -------------------------------------------------------------------------
    // Create and enqueue the job (two-phase save with intermediate status update)
    // -------------------------------------------------------------------------

    $job = new Job($page->getObjDB());
    $job->setTask($task);
    $job->setUser($page->getUser());

    // Phase 1: save to obtain the job ID
    $jobID = $job->saveJob();

    $parameters = array(
        "script"          => "referenceChecker.php",
        "object_id"       => $objectId,
        "user_details_id" => $page->getUser()->getID(),
        "task_id"         => $task->getID(),
        "job_id"          => $jobID
    );

    $job->setParameters($parameters);

    // Set a user-facing waiting message visible in the job queue immediately
    $job->updateStatus("Job in Queue :: waiting to start : item cannot be edited ......");

    // Phase 2: save again with the complete parameter set and status
    $jobID = $job->saveJob();

    $job->putInQueue();

    // -------------------------------------------------------------------------
    // Redirect back to the referring page with a confirmation message
    // -------------------------------------------------------------------------

    $present = new JobPresentation($job);
    $message = $present->getSubmitMessage();
    header("Location: $refURL" . $char . "&&task_id=" . $task->getID() . "&&message=$message");

} catch (Exception $e) {
    $page->handleException($e);
}

?>