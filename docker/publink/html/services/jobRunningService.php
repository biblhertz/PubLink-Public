<?php
/**
 * Job Running Check Service
 *
 * Returns a JSON object indicating whether a job for the given task is
 * currently queued or running for the authenticated user.
 *
 * A job is considered active while its row exists in the `job` table.
 * Once the worker archives it (moving it to `job_log`), the row is gone
 * and this service returns {"running": false}.
 *
 * Request parameters (GET):
 *   task_id  int  ID of the task to check.
 *   file_id  int  (optional) JATS file ID to scope the check to a specific article.
 *
 * Response:
 *   Content-Type: application/json
 *   {"running": true}   — job row exists; task is still queued or running.
 *   {"running": false}  — no active job row; task has completed or never started.
 *
 * @package Biblhertz\Publink
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;

$page = new Bibliotheca_Content_Page();

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$taskId = isset($_GET['task_id']) && is_numeric($_GET['task_id']) ? (int) $_GET['task_id'] : 0;
$fileId = isset($_GET['file_id']) && is_numeric($_GET['file_id']) ? (int) $_GET['file_id'] : 0;

if (!$taskId) {
    echo json_encode(['running' => false]);
    exit;
}

if ($fileId) {
    $row = $page->getObjDB()->preparedSelect(
        "SELECT id FROM job WHERE task_id = ? AND user_details_id = ? AND parameters LIKE ? LIMIT 1",
        [$taskId, $page->getUser()->getID(), '%"file_id":' . $fileId . '%']
    )->fetch();
} else {
    $row = $page->getObjDB()->preparedSelect(
        "SELECT id FROM job WHERE task_id = ? AND user_details_id = ? LIMIT 1",
        [$taskId, $page->getUser()->getID()]
    )->fetch();
}

echo json_encode(['running' => (bool) $row]);
exit;
?>
