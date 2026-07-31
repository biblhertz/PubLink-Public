<?php
/**
 * Article to JATS Export Handler
 *
 * Validates and enqueues a background job to export a serialized Article object
 * to JATS XML. This is a single-object variant of the task handler pattern,
 * specific to the `articleToJATS.php` worker script. It performs authorisation
 * checks at both task and object level before creating the job.
 *
 * Unlike the multi-object task handler, this handler also resolves and validates
 * the source file associated with the serialized object, as the JATS export
 * worker requires both the object ID and its originating file ID.
 *
 * Request parameters (POST):
 *   task_id            int   ID of the task to execute. Must be numeric.
 *   {task_codename}    int   ID of the serialized object to export. The field
 *                            name is derived from {@see Task::getCodeName()}.
 *
 * Authorisation checks:
 *   1. Task-level:   {@see Task::canExecute()} — user must have permission to
 *                    run this task.
 *   2. Object-level: {@see SerializedObject::canExecute()} — user must have
 *                    permission to act on the selected object.
 *   3. File-level:   Uses the same object-level check (see note below).
 *
 * File resolution:
 *   The file ID is looked up from `serialized_object.file_id` for the given
 *   object, linking the export back to the original uploaded source file. Both
 *   the object ID and file ID are passed to the worker script as parameters.
 *
 * Job lifecycle:
 *   The job is saved twice — first to obtain a job ID, then again after the
 *   parameters (which include the job ID) have been set. See the two-phase
 *   save pattern documented in the task handler service.
 *
 * Worker script:
 *   The enqueued job runs `articleToJATS.php` with parameters:
 *     script           string   Worker script name
 *     object_id        int      Serialized object ID
 *     file_id          int      Source file ID
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
 *   and resolved object and file names are written to the error log.
 *
 * Note: the file-level authorisation check at the bottom of the validation
 * block calls `$object->canExecute()` rather than `$file->canExecute()`,
 * and its error message references `$object` rather than `$file`. This appears
 * to be a copy-paste oversight from the object-level check — it should
 * reference `$file` and call the appropriate file-level permission method.
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
    // Resolve object ID and associated file ID
    // The field name for the object is dynamic, derived from the task code name
    // -------------------------------------------------------------------------

    $objectId = $_POST[$task->getCodeName()];

    // Look up the source file linked to this serialized object
    $fileId = $page->getObjDB()->preparedGetOne(
        "SELECT file_id FROM serialized_object WHERE id = ?",
        array($objectId)
    );

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Post message received :: " . serialize($_POST));
        error_log("Codename :: " . $task->getCodeName());
        error_log("Object id :: " . $objectId);
    }

    // -------------------------------------------------------------------------
    // Validate object and check per-object authorisation
    // -------------------------------------------------------------------------

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
    // Validate file and check per-file authorisation
    // -------------------------------------------------------------------------

    if (!isset($fileId) || !is_numeric($fileId)) {
        throw new Exception("File ID not defined in handler");
    }

    $file = new File($page->getObjDB(), $fileId);

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("Selected File:: " . $file->getName());
    }

    // Note: this check calls $object->canExecute() rather than a file-level
    // equivalent, and the error message references $object rather than $file.
    // This is likely a copy-paste oversight and should be reviewed.
    if (!$object->canExecute($page->getUser()->getID())) {
        throw new Exception(
            "User :: " . $page->getUser()->getName()
            . " does not have the right to execute this task on the file selected :: "
            . $object->getName() . " with ID " . $object->getID()
        );
    }

    // -------------------------------------------------------------------------
    // Create and enqueue the job (two-phase save — see class docblock)
    // -------------------------------------------------------------------------

    $job = new Job($page->getObjDB());
    $job->setTask($task);
    $job->setUser($page->getUser());

    // Phase 1: save to obtain the job ID
    $jobID = $job->saveJob();

    // Build parameters including the job ID for the worker script
    $parameters = array(
        "script"          => "articleToJATS.php",
        "object_id"       => $object->getID(),
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
    // Redirect back to the referring page with a confirmation message
    // -------------------------------------------------------------------------

    $present = new JobPresentation($job);
    $message = $present->getSubmitMessage();
    $refURL  = $_SERVER['HTTP_REFERER'];

    $char = strpos($refURL, "?") ? "&&" : "?";
    header("Location: $refURL" . $char . "oid=" . $object->getID() . "&&task_id=" . $task->getID() . "&&message=$message");

} catch (Exception $e) {
    $page->handleException($e);
}

?>