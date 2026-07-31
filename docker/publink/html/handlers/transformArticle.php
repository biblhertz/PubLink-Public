<?php
/**
 * Transform Article Handler
 *
 * Validates and enqueues a background job to transform a serialized Article
 * object via the `transformArticle.php` worker script. Performs authorisation
 * checks at task and object level before creating the job.
 *
 * This is a clean single-object handler following the standard pattern, without
 * the article locking or pre-flight state checks present in the reference
 * checker handlers. It is the object-based counterpart to the single-file
 * handlers, redirecting to the referrer with the object ID appended.
 *
 * Request parameters (POST):
 *   task_id             int   ID of the task to execute. Must be numeric.
 *   {task_codename}     int   ID of the serialized Article object to transform.
 *                             Field name derived from {@see Task::getCodeName()}.
 *
 * Authorisation checks:
 *   1. Task-level:   {@see Task::canExecute()} — user must have permission to
 *                    run this task.
 *   2. Object-level: {@see SerializedObject::canExecute()} — user must have
 *                    permission to act on the selected object.
 *
 * Job lifecycle:
 *   Follows the two-phase save pattern: the job is saved once to obtain a job
 *   ID, then saved again after the parameters (which include the job ID) have
 *   been set.
 *
 * Worker script:
 *   The enqueued job runs `transformArticle.php` with parameters:
 *     script           string   Worker script name
 *     object_id        int      Serialized Article object ID
 *     user_details_id  int      Executing user's ID
 *     task_id          int      Task ID
 *     job_id           int      Job ID (for status reporting)
 *
 * Redirect:
 *   On success, redirects to the HTTP referrer with `oid`, `task_id`, and
 *   `message` appended as query parameters.
 *
 * Debug logging:
 *   When {@see Config::$SCHEDULER_DEBUG} is set, POST data, the task code name,
 *   and the resolved object ID and name are written to the error log.
 *   Note: the debug log line reads "File id ::" for the object ID — a
 *   copy-paste label from a file-based handler, harmless but mildly misleading.
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
    // Validate object and check per-object authorisation
    // -------------------------------------------------------------------------

    $objectId = $_POST[$task->getCodeName()];

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Post message received :: " . serialize($_POST));
        error_log("Codename :: " . $task->getCodeName());
        error_log("File id :: " . $objectId); // Note: label should be "Object id"
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
    // Create and enqueue the job (two-phase save)
    // -------------------------------------------------------------------------

    $job = new Job($page->getObjDB());
    $job->setTask($task);
    $job->setUser($page->getUser());

    // Phase 1: save to obtain the job ID
    $jobID = $job->saveJob();

    // Build parameters including the job ID for the worker script
    $parameters = array(
        "script"          => "transformArticle.php",
        "object_id"       => $object->getID(),
        "user_details_id" => $page->getUser()->getID(),
        "task_id"         => $task->getID(),
        "job_id"          => $jobID
    );

    // Phase 2: save again with the complete parameter set
    $job->setParameters($parameters);
    $jobID = $job->saveJob();

    $job->putInQueue();

    // -------------------------------------------------------------------------
    // Redirect back to the referring page with a confirmation message
    // -------------------------------------------------------------------------

    $present = new JobPresentation($job);
    $message = $present->getSubmitMessage();
    $refURL  = $_SERVER['HTTP_REFERER'];
    $char    = strpos($refURL, "?") ? "&&" : "?";

    header("Location: $refURL" . $char . "oid=" . $object->getID() . "&&task_id=" . $task->getID() . "&&message=$message");

} catch (Exception $e) {
    $page->handleException($e);
}

?>