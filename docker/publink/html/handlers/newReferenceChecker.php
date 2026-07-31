<?php
/**
 * Reference Checker Handler
 *
 * Validates and enqueues a background job to run an automated reference check
 * on a serialized Article object via the `newReferenceChecker.php` worker
 * script. Locks the article against editing before enqueuing to prevent
 * concurrent modifications during the check.
 *
 * This is a single-object handler operating on a SerializedObject rather than
 * a File, with the additional step of mutating the article's state before
 * the job is queued — distinguishing it from the other object-based handlers.
 *
 * Request parameters (POST):
 *   task_id   int   ID of the task to execute. Must be numeric.
 *   oid       int   ID of the serialized Article object to check. Unlike other
 *                   handlers, this field uses the fixed name "oid" rather than
 *                   a task code name derived field.
 *
 * Authorisation checks:
 *   1. Task-level:   {@see Task::canExecute()} — user must have permission to
 *                    run this task.
 *   2. Object-level: {@see SerializedObject::canExecute()} — user must have
 *                    permission to act on the selected object.
 *
 * Pre-flight state check:
 *   If the article's reference check flag is already set (a check has been
 *   previously initiated), the handler throws an exception to prevent duplicate
 *   jobs. Note: the redirect and exit after the throw are unreachable dead code
 *   — `throw` halts execution immediately and the catch block handles the
 *   response.
 *
 * Article locking:
 *   Before enqueuing, the article's `referenceCheck` and `readOnly` flags are
 *   both set to `true` and the object is persisted. This prevents the article
 *   from being edited in the UI while the reference checker is running. The
 *   reference check status service ({@see checkEdits.php}) clears these flags
 *   when the job completes.
 *
 * Job status:
 *   Unlike other handlers, this one calls {@see Job::updateStatus()} with a
 *   user-facing waiting message before the final save, so the job queue
 *   displays a meaningful status string immediately on submission.
 *
 * Job lifecycle:
 *   Follows the two-phase save pattern, with an additional status update
 *   between the two saves.
 *
 * Worker script:
 *   The enqueued job runs `newReferenceChecker.php` with parameters:
 *     script           string   Worker script name
 *     object_id        int      Serialized Article object ID
 *     user_details_id  int      Executing user's ID
 *     task_id          int      Task ID
 *     job_id           int      Job ID (for status reporting)
 *
 * Redirect:
 *   On success, redirects to `../uploadJATS.html` with `task_id` and `message`.
 *   Note: the referrer-based redirect is present as commented-out code —
 *   the current target is hardcoded to `uploadJATS.html`.
 *
 * Debug logging:
 *   When {@see Config::$SCHEDULER_DEBUG} is set, POST data, the task code name,
 *   and the resolved object ID and name are written to the error log.
 *
 * Note: `$refURL` and `$char` are computed at the top of the try block before
 * the task validation, and are referenced in the (unreachable) duplicate-check
 * redirect. If the referrer-based redirect is re-enabled, these variables are
 * ready to use.
 *
 * Output:
 *   Success: HTTP redirect to uploadJATS.html with status message.
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
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\om\Job;
use Biblhertz\Publink\om\presentation\JobPresentation;
use Biblhertz\Publink\Config;

$page = new Bibliotheca_Content_Page();

try {

    // Capture referrer early for potential redirect use (see note in docblock)
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
    // Note: uses fixed "oid" field name rather than task code name
    // -------------------------------------------------------------------------

    $objectId = $_POST['oid'];

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
        // Note: the header() and exit below this throw are unreachable —
        // throw halts execution and the catch handles the response
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
        "script"          => "newReferenceChecker.php",
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
    // Redirect to the JATS upload page with a confirmation message
    // Note: referrer-based redirect is available but currently commented out
    // header("Location: $refURL".$char."&&task_id=".$task->getID()."&&message=$message");
    // -------------------------------------------------------------------------

    $present = new JobPresentation($job);
    $message = $present->getSubmitMessage();
    header("Location: ../uploadJATS.html?task_id=" . $task->getID() . "&&message=$message");

} catch (Exception $e) {
    $page->handleException($e);
}

?>