<?php
/**
 * IIIF Manifest → Annotation Server publication handler.
 *
 * Submits a previously generated IIIF manifest JSON file to the annotation
 * server publication API. Called by the "Publish to Server" form on the
 * Generate IIIF Manifest tab (tab 7) of article.html.
 *
 * Request parameters (POST):
 *   file_id        int     File table ID of the generated manifest JSON.
 *   oid            int     Serialized object ID of the article (used for redirect).
 *   series         string  Publication series directory (e.g. "journals").
 *   volume         string  Publication volume sub-directory (e.g. "2024").
 *   manifest_name  string  Filename on the remote server (e.g. "article.json").
 *   api_key        string  Annotation server API key, entered by the user for this
 *                          request. Not read from config — must be supplied fresh
 *                          each time so the stored server key is never used directly.
 *
 * On success, redirects back to article.html at tab 7 with the published URL
 * embedded in the confirmation message.
 *
 * @package Biblhertz\Publink
 * @see     ImageCanvas::publishManifest()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\Config;

$page = new Bibliotheca_Content_Page();

try {

    // -------------------------------------------------------------------------
    // Validate article object ID (used only for the redirect)
    // -------------------------------------------------------------------------

    if (!isset($_POST['oid']) || !is_numeric($_POST['oid'])) {
        throw new Exception("Object ID not defined in handler");
    }

    $oid = (int) $_POST['oid'];

    // -------------------------------------------------------------------------
    // Validate manifest file and check per-file authorisation
    // -------------------------------------------------------------------------

    if (!isset($_POST['file_id']) || !is_numeric($_POST['file_id'])) {
        throw new Exception("File ID not defined in handler");
    }

    $file = new File($page->getObjDB(), (int) $_POST['file_id']);

    if (!$file->isJSON()) {
        throw new Exception("The supplied file is not a JSON file: " . $file->getName());
    }

    if (!$file->canExecute($page->getUser()->getID())) {
        throw new Exception(
            "User :: " . $page->getUser()->getName()
            . " does not have permission to publish this file: "
            . $file->getName()
        );
    }

    // -------------------------------------------------------------------------
    // Validate publication routing fields
    // -------------------------------------------------------------------------

    foreach (['series', 'volume', 'manifest_name'] as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Required publication field '$field' is missing or empty");
        }
    }

    $series       = trim($_POST['series']);
    $volume       = trim($_POST['volume']);
    $manifestName = trim($_POST['manifest_name']);

    // API key must be supplied by the user on each request — Publink has no
    // stored key of its own to authenticate publishing with.
    if (empty($_POST['api_key'])) {
        throw new Exception("API key is required to publish a manifest");
    }
    $apiKey = trim($_POST['api_key']);

    // -------------------------------------------------------------------------
    // Read the manifest JSON from disk
    // -------------------------------------------------------------------------

    $manifestJson = file_get_contents($file->getPath());
    if ($manifestJson === false) {
        throw new Exception("Could not read manifest file from disk: " . $file->getPath());
    }

    // -------------------------------------------------------------------------
    // POST to the annotation server publication API
    // -------------------------------------------------------------------------

    if (empty(Config::$PUBLICATION_PUBLISH_API)) {
        throw new Exception("Publication API is not configured on this server");
    }

    $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

    // Helper: send manifest JSON to the publication API and return the decoded response.
    $curlPublish = function(string $json, bool $ignoreMismatch, bool $ignoreOverwrite)
        use ($series, $volume, $manifestName, $apiKey): array
    {
        $payload = json_encode([
            'series'           => $series,
            'volume'           => $volume,
            'manifest_name'    => $manifestName,
            'manifest'         => $json,
            'ignore_mismatch'  => $ignoreMismatch,
            'ignore_overwrite' => $ignoreOverwrite,
        ]);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER,     ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded', 'X-API-Key: ' . $apiKey]);
        curl_setopt($curl, CURLOPT_URL,            Config::$PUBLICATION_PUBLISH_API);
        curl_setopt($curl, CURLOPT_POST,           1);
        curl_setopt($curl, CURLOPT_HEADER,         0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS,     http_build_query(['data' => $payload]));
        curl_setopt($curl, CURLOPT_SSL_OPTIONS,    CURLSSLOPT_NATIVE_CA);
        $response = curl_exec($curl);
        $err      = curl_error($curl);
        curl_close($curl);
        if ($response === false || !empty($err)) {
            throw new Exception("Publication API request failed: " . $err);
        }
        return json_decode($response, true) ?? [];
    };

    // Step 1 — publish with ignore_mismatch to get the real published URL back.
    $decoded      = $curlPublish($manifestJson, true, $overwrite);
    $publishedUrl = $decoded['url'] ?? $decoded['manifest'] ?? '';

    // Step 2 — if the server returned a URL and the manifest id doesn't already
    // match it, update the id and re-publish so IIIF viewers (e.g. Theseus) can
    // navigate multi-canvas manifests correctly.
    if ($publishedUrl) {
        $manifestData = json_decode($manifestJson, true);
        if (is_array($manifestData) && ($manifestData['id'] ?? '') !== $publishedUrl) {
            $manifestData['id'] = $publishedUrl;
            $manifestJson       = json_encode($manifestData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            // Persist the corrected manifest to disk.
            file_put_contents($file->getPath(), $manifestJson);

            // Re-publish with the corrected id (always overwrite the version we just uploaded).
            $decoded      = $curlPublish($manifestJson, false, true);
            $publishedUrl = $decoded['url'] ?? $decoded['manifest'] ?? $publishedUrl;
        }
    }

    // -------------------------------------------------------------------------
    // Parse the server response and build the redirect message
    // -------------------------------------------------------------------------

    if ($publishedUrl) {
        $page->getObjDB()->preparedStatement(
            "UPDATE file SET published_url = ? WHERE id = ?",
            [$publishedUrl, $file->getID()]
        );
        $safeUrl = htmlspecialchars($publishedUrl, ENT_QUOTES, 'UTF-8');
        $page->getUserSession()->flash_message = "Manifest published successfully.<br>"
            . "URL: <a href=\"{$safeUrl}\" target=\"_blank\">{$safeUrl}</a>";
    } else {
        // Prefer the first errorList detail over the generic top-level message.
        $errorDetail = $decoded['errorList'][0]['detail'] ?? null;
        $serverMsg   = $errorDetail ?? $decoded['message'] ?? null;
        $safeMsg     = htmlspecialchars(
            $serverMsg ?? json_encode($decoded),
            ENT_QUOTES, 'UTF-8'
        );
        $page->getUserSession()->flash_message =
            '<div class="alert alert-warning mb-0">'
            . '<strong><i class="fas fa-exclamation-triangle me-1"></i> Manifest not stored on remote server.</strong> '
            . $safeMsg
            . '</div>';
    }

    $tab = isset($_POST['tab']) && is_numeric($_POST['tab']) ? (int)$_POST['tab'] : 7;
    header("Location: ../article.html?oid=$oid&tab=$tab");
    exit;

} catch (Exception $e) {
    $page->handleException($e);
}
?>
