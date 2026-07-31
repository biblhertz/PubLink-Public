<?php
/**
 * IIIF Manifest Publish Proxy
 *
 * Validates the PubLink session, then forwards the raw manifest JSON to the
 * Simple Manifest Server's /api/manifests endpoint. Server-to-server HTTP is
 * fine; this proxy exists solely to avoid mixed content when PubLink is
 * served over HTTPS.
 *
 * The Bearer token is taken from the caller's own `Authorization` header and
 * passed straight through. Publink holds no shared manifest-server API key of
 * its own — the caller must supply their own key (e.g. typed into
 * iiif_generator.html's Publish Settings modal) on every request.
 *
 * Request
 *   POST /api/manifests  (routed here by nginx)
 *   Authorization: Bearer <api key>
 *   Content-Type: application/json
 *   Body: IIIF Presentation 3 manifest JSON
 *
 * Response
 *   200 { "url": "https://annotation.biblhertz.it/iiif_manifests/…/manifest.json" }
 *   400 { "error": "description" }
 *   401 { "error": "Authentication required" }
 *   502 { "error": "…" }
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\Config;

header('Content-Type: application/json');

// Auth — suppress redirect so we can return a JSON error instead
$page = new Bibliotheca_Content_Page(1);
$loggedIn = $page->getObjDB()->preparedGetOne(
    "SELECT logged_in FROM user_session WHERE id = ?",
    [$page->getUserSession()->getNativeSessionIdentifier()]
);
if (!$loggedIn) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!Config::$MANIFEST_SERVER_INTEGRATION) {
    http_response_code(503);
    echo json_encode(['error' => 'Manifest server integration is not configured']);
    exit;
}

// API key must be supplied by the caller in the Authorization header — Publink
// has no stored manifest-server key of its own to fall back on.
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m) || $m[1] === '') {
    http_response_code(401);
    echo json_encode(['error' => 'API key is required (Authorization: Bearer <key>)']);
    exit;
}
$apiKey = $m[1];

$body = file_get_contents('php://input');
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty request body']);
    exit;
}

$manifest = json_decode($body, true);
if (!$manifest || !isset($manifest['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid manifest JSON or missing id field']);
    exit;
}

// Forward raw body to the manifest server's /api/manifests endpoint
$apiUrl = rtrim(Config::$MANIFEST_SERVER_URI, '/') . '/api/manifests';

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Could not reach manifest server: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode ?: 502);
    echo json_encode(['error' => 'Manifest server error', 'detail' => $response]);
    exit;
}

$decoded = json_decode($response, true);
if (!empty($decoded['error'])) {
    http_response_code(422);
    echo json_encode(['error' => $decoded['error']]);
    exit;
}

echo json_encode(['url' => $decoded['url']]);
exit;
