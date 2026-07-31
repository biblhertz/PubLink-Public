<?php
/**
 * Mirador User Check Service
 *
 * Validates a session token sent by the Mirador IIIF viewer and returns the
 * corresponding session identifier and annotation endpoint URL. This is part
 * of the Mirador authentication handshake — Mirador presents a token and this
 * service exchanges it for the credentials needed to make authenticated
 * annotation API calls.
 *
 * Flow:
 *   1. Mirador sends a `token` parameter (the ASCII session ID) in the request.
 *   2. The token is looked up in `user_session` to resolve the owning user ID.
 *   3. If valid, the most recent ASCII session ID for that user is returned
 *      along with the annotation endpoint URL from {@see Config::$ANNOTATION_ENDPOINT}.
 *   4. If the token is absent or resolves to no valid user, a `user = -1`
 *      response is returned to signal authentication failure to Mirador.
 *
 * Token mechanism:
 *   The service originally used a dedicated `annotation_token` table with
 *   single-use tokens (see commented-out code). The current implementation
 *   uses `user_session.ascii_session_id` directly as the token, which avoids
 *   the token lifecycle overhead but ties Mirador auth directly to the PHP
 *   session. The `deleteToken()` function and related comments are retained
 *   for reference during this transitional state.
 *
 * Output:
 *   Content-Type: application/json
 *   Body (success): {"user": "<ascii_session_id>", "endpoint": "<annotation_endpoint>"}
 *   Body (failure): {"user": "-1"}
 *
 * @package Biblhertz\Publink
 * @see     Config::$ANNOTATION_ENDPOINT
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\om\User;

$page = new Bibliotheca_Content_Page(1);

error_log("Executing User Check Service from Mirador");

// -------------------------------------------------------------------------
// Validate token presence
// -------------------------------------------------------------------------

if (!isset($_REQUEST['token'])) {
    sendNotFound();
    exit;
}

// -------------------------------------------------------------------------
// Resolve token to a user ID via user_session
// -------------------------------------------------------------------------

// Previously resolved via annotation_token table (single-use tokens):
// $uid = $page->getObjDB()->preparedGetOne(
//     "SELECT user_details_id FROM annotation_token WHERE token = ?",
//     array($_REQUEST['token'])
// );

$uid = $page->getObjDB()->preparedGetOne(
    "SELECT user_id FROM user_session WHERE ascii_session_id = ?",
    array($_REQUEST['token'])
);

if (!isset($uid) || !is_numeric($uid) || $uid <= 0) {
    sendNotFound();
    exit;
}

// -------------------------------------------------------------------------
// Return session credentials to Mirador
// -------------------------------------------------------------------------

error_log("Sending token back");

// Retrieve the most recent session ID for this user (in case of multiple sessions)
$session = $page->getObjDB()->preparedGetOne(
    "SELECT ascii_session_id FROM user_session WHERE user_id = ? ORDER BY id DESC",
    array($uid)
);

error_log("Got Session ID for Mirador Client :: $session");

$json = json_encode(array(
    'user'     => "$session",
    'endpoint' => Config::$ANNOTATION_ENDPOINT
));

error_log("Sending JSON :: $json");

header('Content-Type: application/json');
echo $json;
exit;


// -------------------------------------------------------------------------
// Helper functions
// -------------------------------------------------------------------------

/**
 * Send an authentication failure response to Mirador.
 *
 * Returns a JSON payload with `user = -1`, the sentinel value Mirador
 * uses to detect that authentication did not succeed.
 *
 * @return void
 */
function sendNotFound()
{
    error_log("Token not found !!");
    header('Content-Type: application/json');
    echo json_encode(array('user' => "-1"));
}

/**
 * Delete a single-use annotation token after it has been consumed.
 *
 * Used with the legacy `annotation_token` table approach. Currently unused
 * since the service switched to `user_session.ascii_session_id` as the token.
 * Retained pending final removal of the old token mechanism.
 *
 * @return void
 */
function deleteToken()
{
    global $page;
    $page->getObjDB()->preparedSelect(
        "DELETE FROM annotation_token WHERE token = ?",
        array($_REQUEST['token'])
    );
}

?>