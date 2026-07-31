<?php
/**
 * Task Handler Service
 *
 * Validates and enqueues a background job for a user-selected task against one
 * or more serialized objects. On success, redirects back to the referring page
 * with a confirmation message. On failure, delegates to the page's exception
 * handler.
 *
 * This handler is the form submission endpoint for task execution in the UI.
 * It performs authorisation checks at two levels — task and object — before
 * creating the job, to ensure neither the task nor any of the target objects
 * can be actioned by an unauthorised user.
 *
 * Request parameters (POST):
 *   task_id              int       ID of the task to execute. Must be numeric.
 *   {task_codename}      int       Object ID to act on (single object mode).
 *   {task_codename}_cb   int[]     Object IDs to act on (multi-object / checkbox
 *                                  mode). Takes precedence over single object mode
 *                                  when present.
 *
 * The object ID field name is dynamic, derived from the task's code name via
 * {@see Task::getCodeName()}. This allows different task forms to use
 * task-specific field names without this handler needing to know them.
 *
 * Authorisation checks:
 *   1. Task-level: {@see Task::canExecute()} — the user must have permission
 *      to run this task at all.
 *   2. Object-level: {@see SerializedObject::canExecute()} — the user must
 *      have permission to run a task on each individual selected object.
 *
 * Job lifecycle:
 *   The job is saved twice — once immediately to obtain a `job_id` for use
 *   in the parameters payload, then again after the parameters (including the
 *   job ID itself) have been set. This two-phase save is necessary because the
 *   job ID is required as a parameter for the worker script.
 *
 * Worker script:
 *   The enqueued job runs `articleToDataCite.php` with parameters:
 *     script           string   Worker script name
 *     objects          string   Serialized array of object IDs
 *     user_details_id  int      Executing user's ID
 *     task_id          int      Task ID
 *     job_id           int      Job ID (for status reporting)
 *
 * Redirect:
 *   On success, redirects to the HTTP referrer with `oid`, `task_id`, and
 *   `message` appended as query parameters. The separator ("?" or "&&") is
 *   chosen based on whether the referrer URL already contains a query string.
 *
 * Debug logging:
 *   When {@see Config::$SCHEDULER_DEBUG} is set, POST data, the task code name,
 *   and resolved object IDs are written to the error log.
 *
 * Output:
 *   Success: HTTP redirect to referrer with status message.
 *   Failure: Delegated to {@see Bibliotheca_Content_Page::handleException()}.
 *
 * @package Biblhertz\Publink
 * @see     Task::canExecute()
 * @see     SerializedObject::canExecute()
 * @see     Job::putInQueue()
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
    // Resolve target object IDs
    // Checkbox mode ({codename}_cb[]) takes precedence over single-object mode
    // -------------------------------------------------------------------------

    $objects = array();

    if (isset($_POST[$task->getCodeName() . "_cb"])) {
        // Multi-object: checkboxes submitted as an array
        foreach ($_POST[$task->getCodeName() . "_cb"] as $oid) {
            $objects[] = $oid;
        }
    } else {
        // Single-object: direct field value
        $objects[] = $_POST[$task->getCodeName()];
    }

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Post message received :: " . serialize($_POST));
        error_log("Codename :: " . $task->getCodeName());
        error_log("Object ids :: " . serialize($objects));
    }

    // -------------------------------------------------------------------------
    // Validate each object and check per-object authorisation
    // -------------------------------------------------------------------------

    foreach ($objects as $objectId) {

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
    }

    // -------------------------------------------------------------------------
    // Create and enqueue the job
    //
    // Two-phase save: first save assigns the job ID, which is then included in
    // the parameters payload before saving again with the full parameter set.
    // -------------------------------------------------------------------------

    $job = new Job($page->getObjDB());
    $job->setTask($task);
    $job->setUser($page->getUser());

    // Phase 1: save to obtain the job ID
    $jobID = $job->saveJob();

    // Build parameters including the job ID for the worker script
    $parameters = array(
        "script"          => "articleToDataCite.php",
        "objects"         => serialize($objects),
        "user_details_id" => $page->getUser()->getID(),
        "task_id"         => $task->getID(),
        "job_id"          => $jobID
    );

    // Phase 2: save again with the complete parameter set
    $job->setParameters($parameters);
    $jobID = $job->saveJob();

    // Place the job on the scheduler queue for execution
    $job->putInQueue();

    // -------------------------------------------------------------------------
    // Redirect back to the referring page with a confirmation message
    // -------------------------------------------------------------------------

    $present = new JobPresentation($job);
    $message = $present->getSubmitMessage();
    $refURL  = $_SERVER['HTTP_REFERER'];

    // Append query parameters with the correct separator
    $char = strpos($refURL, "?") ? "&&" : "?";

    header("Location: $refURL" . $char . "oid=" . $object->getID() . "&&task_id=" . $task->getID() . "&&message=$message");

} catch (Exception $e) {
    $page->handleException($e);
}

?>