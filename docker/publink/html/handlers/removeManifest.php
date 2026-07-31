<?php
/**
 * IIIF Manifest → Annotation Server removal handler.
 *
 * Removes a previously published IIIF manifest from the annotation server,
 * then clears the published_url from the file record in the local database.
 * Called by the "Delete from server" form on the Generate IIIF Manifest tab
 * of article.html.
 *
 * Request parameters (POST):
 *   file_id        int     File table ID of the manifest JSON.
 *   oid            int     Serialized object ID of the article (used for redirect).
 *   published_url  string  URL of the manifest on the annotation server.
 *   tab            int     Tab index to return to after the action.
 *   api_key        string  Annotation server API key, entered by the user for this
 *                          request. Not read from config — must be supplied fresh
 *                          each time so the stored server key is never used directly.
 *
 * @package Biblhertz\Publink
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\Config;

$page = new Bibliotheca_Content_Page();

try {

    if (!isset($_POST['oid']) || !is_numeric($_POST['oid'])) {
        throw new Exception("Object ID not defined in handler");
    }
    $oid = (int) $_POST['oid'];

    if (!isset($_POST['file_id']) || !is_numeric($_POST['file_id'])) {
        throw new Exception("File ID not defined in handler");
    }
    $file = new File($page->getObjDB(), (int) $_POST['file_id']);

    if (!$file->canExecute($page->getUser()->getID())) {
        throw new Exception(
            "User :: " . $page->getUser()->getName()
            . " does not have permission to remove this manifest: "
            . $file->getName()
        );
    }

    $publishedUrl = trim($_POST['published_url'] ?? '');
    if (empty($publishedUrl)) {
        throw new Exception("Published URL is missing or empty");
    }

    // API key must be supplied by the user on each request — Publink has no
    // stored key of its own to authenticate removal with.
    if (empty($_POST['api_key'])) {
        throw new Exception("API key is required to remove a manifest");
    }
    $apiKey = trim($_POST['api_key']);

    if (empty(Config::$PUBLICATION_REMOVE_API)) {
        throw new Exception("Publication API is not configured on this server");
    }

    // POST to the annotation server remove API.
    $payload = json_encode(['url' => $publishedUrl]);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_HTTPHEADER,     ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded', 'X-API-Key: ' . $apiKey]);
    curl_setopt($curl, CURLOPT_URL,            Config::$PUBLICATION_REMOVE_API);
    curl_setopt($curl, CURLOPT_POST,           1);
    curl_setopt($curl, CURLOPT_HEADER,         0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS,     http_build_query(['data' => $payload]));
    curl_setopt($curl, CURLOPT_SSL_OPTIONS,    CURLSSLOPT_NATIVE_CA);
    $response = curl_exec($curl);
    $err      = curl_error($curl);
    curl_close($curl);

    if ($response === false || !empty($err)) {
        throw new Exception("Remove API request failed: " . $err);
    }

    $decoded = json_decode($response, true) ?? [];

    // Clear the published URL from the local file record whether the remote
    // deletion succeeded or not (the manifest may already have been deleted
    // manually on the server; we still want the local state to reflect "unpublished").
    $page->getObjDB()->preparedStatement(
        "UPDATE file SET published_url = NULL WHERE id = ?",
        [$file->getID()]
    );

    $success = $decoded['success'] ?? false;
    if ($success) {
        $page->getUserSession()->flash_message = "Manifest removed from the annotation server.";
    } else {
        $msg = htmlspecialchars($decoded['message'] ?? 'Unknown response', ENT_QUOTES, 'UTF-8');
        $page->getUserSession()->flash_message = "Server response: $msg. The manifest has been marked as unpublished locally.";
    }

    $tab = isset($_POST['tab']) && is_numeric($_POST['tab']) ? (int)$_POST['tab'] : 7;
    header("Location: ../article.html?oid=$oid&tab=$tab");
    exit;

} catch (Exception $e) {
    $page->handleException($e);
}
?>
