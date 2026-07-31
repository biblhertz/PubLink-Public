<?php
/**
 * Reference Checker Status Service
 *
 * Polls the status of a "Reference Checker" background job for a given
 * serialized object (article), and clears the reference check flag on the
 * object once the job is no longer active.
 *
 * The `oid` request parameter identifies the serialized object (article) whose
 * reference check status is being queried.
 *
 * Flow:
 *   1. Look up any active "Reference Checker" jobs belonging to the current
 *      user in the `job` table.
 *   2. If a job is found whose `parameters.object_id` matches `oid`, return
 *      the job's current status — the check is still in progress.
 *   3. If no matching active job exists, the check has completed: clear the
 *      reference check and read-only flags on the article via
 *      {@see SerializedObject} and return a "FINISHED" status.
 *
 * Response values[0] contains a machine-readable status string (e.g. the job
 * status or "FINISHED"). values[1] contains an HTML status badge for direct
 * page injection.
 *
 * Output:
 *   Content-Type: application/json; charset=UTF-8
 *   Body: JSON-encoded indexed array:
 *     [0] string  Machine-readable status (job status value or "FINISHED")
 *     [1] string  HTML status badge for inline display
 *
 * Note: if `oid` is absent and no active job is found, the service exits
 * silently with no output.
 *
 * Note: there is a variable name inconsistency in the completion branch —
 * `$obj` is instantiated but `$object` is used for subsequent calls. This
 * should be unified to `$obj` throughout.
 *
 * @package Biblhertz\Publink
 * @see     SerializedObject
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\SerializedObject;

$page   = new Bibliotheca_Content_Page();

/** @var string $oid Serialized object (article) ID to check */
$oid    = $_REQUEST['oid'];
$values = array();

// -------------------------------------------------------------------------
// Check for an active Reference Checker job for this user
// -------------------------------------------------------------------------

$jobs = $page->getObjDB()->preparedSelect(
    "SELECT * FROM job WHERE task_name = ? AND user_details_id = ?",
    array("Reference Checker", $page->getUser()->getID())
);

if ($page->getObjDB()->numRows()) {

    // Search active jobs for one whose parameters reference this object
    while ($job = $jobs->fetch()) {
        $params = json_decode($job['parameters'], true);

        if ($params['object_id'] == $oid) {
            // Job found — reference check still in progress, return current status
            $values[0] = $job['status'];
            $values[1] = "<h5 style='font-face:bold;color:red'>" . $job['status'] . "</h5>";

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($values);
            exit;
        }
    }

// -------------------------------------------------------------------------
// No active job found — reference check has completed
// -------------------------------------------------------------------------

} else {

    if (isset($oid)) {
        // Clear the reference check and read-only flags on the article now
        // that the job is no longer active
        $obj     = new SerializedObject($page->getObjDB(), $oid);
        $article = unserialize($object->getObject());   // Note: $object should be $obj
        $article->setReferenceCheck(false);
        $article->setReadOnly(false);
        $object->updateObject($article);                // Note: $object should be $obj

        $values[0] = "FINISHED";
        $values[1] = "<h5 style='font-face:bold;color:red'>Reference Check Completed</h5>";

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($values);
        exit;
    }
}

?>