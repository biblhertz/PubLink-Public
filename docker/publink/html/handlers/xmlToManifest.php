<?php
/**
 * JATS XML → IIIF Manifest dispatch handler.
 *
 * Submitted by the Generate IIIF Manifest form on article.html (tab 7).
 * The JATS XML file is identified via a hidden `jats_file_id` field embedded
 * in the form by ArticlePresentation::getManifestPanel(). Manifest metadata
 * is supplied as explicit form fields rather than a JSON file.
 *
 * Request parameters (POST):
 *   task_id           int     ID of the "XML to IIIF Manifest" task.
 *   oid               int     Serialized object ID of the article (used for redirect).
 *   jats_file_id      int     File table ID of the source JATS XML file.
 *   manifest_id       string  Canonical IIIF manifest URL.
 *   base_canvas       string  Base URL for canvas IDs within the manifest.
 *   label_it          string  Italian manifest label.
 *   label_en          string  English manifest label.
 *   rights            string  Rights/licence URL.
 *   required_stmt_it  string  Italian attribution statement.
 *   required_stmt_en  string  English attribution statement.
 *   fetch_delay       float   (optional) Seconds between info.json fetches.
 *   fallback_width    int     (optional) Canvas width fallback.
 *   fallback_height   int     (optional) Canvas height fallback.
 *
 * On success, redirects back to article.html at tab 7 with a confirmation message.
 *
 * @package Biblhertz\Publink
 * @see     Job::putInQueue()
 * @see     ArticlePresentation::getManifestPanel()
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

    $task = new Task($page->getObjDB(), (int) $_POST['task_id']);

    if (!$task->canExecute($page->getUser())) {
        throw new Exception(
            "User :: " . $page->getUser()->getName() . " does not have the right to execute this task"
        );
    }

    // -------------------------------------------------------------------------
    // Validate article object ID (used only for the redirect)
    // -------------------------------------------------------------------------

    if (!isset($_POST['oid']) || !is_numeric($_POST['oid'])) {
        throw new Exception("Object ID not defined in handler");
    }

    $oid = (int) $_POST['oid'];

    // -------------------------------------------------------------------------
    // Validate JATS XML file and check per-file authorisation
    // -------------------------------------------------------------------------

    if (!isset($_POST['jats_file_id']) || !is_numeric($_POST['jats_file_id'])) {
        throw new Exception("JATS file ID not defined in handler");
    }

    $file = new File($page->getObjDB(), (int) $_POST['jats_file_id']);

    if (!$file->isJATS()) {
        throw new Exception("The supplied file is not a JATS XML file: " . $file->getName());
    }

    if (!$file->canExecute($page->getUser()->getID())) {
        throw new Exception(
            "User :: " . $page->getUser()->getName()
            . " does not have the right to execute this task on the file :: "
            . $file->getName()
        );
    }

    // -------------------------------------------------------------------------
    // Validate required manifest config fields
    // -------------------------------------------------------------------------

    $required = ['manifest_id', 'base_canvas', 'label_it', 'label_en',
                 'rights', 'required_stmt_it', 'required_stmt_en'];

    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Required manifest config field '$field' is missing or empty");
        }
    }

    if (isset(Config::$SCHEDULER_DEBUG)) {
        error_log("xmlToManifest :: oid=$oid file=" . $file->getName());
    }

    // -------------------------------------------------------------------------
    // Create and enqueue the job (two-phase save)
    // -------------------------------------------------------------------------

    $job = new Job($page->getObjDB());
    $job->setTask($task);
    $job->setUser($page->getUser());

    $jobID = $job->saveJob();

    $parameters = [
        "script"           => "xml2manifest.php",
        "file_id"          => $file->getID(),
        "user_details_id"  => $page->getUser()->getID(),
        "task_id"          => $task->getID(),
        "job_id"           => $jobID,
        "manifest_id"      => $_POST['manifest_id'],
        "base_canvas"      => $_POST['base_canvas'],
        "label_it"         => $_POST['label_it'],
        "label_en"         => $_POST['label_en'],
        "rights"           => $_POST['rights'],
        "required_stmt_it" => $_POST['required_stmt_it'],
        "required_stmt_en" => $_POST['required_stmt_en'],
        "force_http_hosts" => !empty($_POST['force_http_hosts'])
            ? array_values(array_filter(array_map('trim', explode(',', $_POST['force_http_hosts']))))
            : [],
    ];

    foreach (['fetch_delay', 'fallback_width', 'fallback_height'] as $key) {
        if (!empty($_POST[$key])) $parameters[$key] = $_POST[$key];
    }

    $job->setParameters($parameters);
    $jobID = $job->saveJob();

    $job->putInQueue();

    // -------------------------------------------------------------------------
    // Redirect back to the article page, reopening the manifest tab
    // -------------------------------------------------------------------------

    $page->getUserSession()->flash_message = (new JobPresentation($job))->getSubmitMessage();
    $tab = isset($_POST['tab']) && is_numeric($_POST['tab']) ? (int)$_POST['tab'] : 7;
    header("Location: ../article.html?oid=$oid&tab=$tab");
    exit;

} catch (Exception $e) {
    $page->handleException($e);
}
?>
