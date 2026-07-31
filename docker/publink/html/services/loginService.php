<<?php
/**
 * ORCID Login Callback Service
 *
 * OAuth 2.0 callback handler for ORCID authentication. This script is
 * registered as the redirect URI with the ORCID OAuth service and is called
 * by ORCID after the user authorises the application.
 *
 * Flow:
 *   1. ORCID redirects the user's browser to this script with a one-time
 *      authorisation `code` in the query string.
 *   2. {@see Login::orchidAuthenticate()} exchanges the code for an access
 *      token, retrieves the user's ORCID profile, and establishes a local
 *      session.
 *   3a. On success: a small JavaScript snippet closes the ORCID popup window
 *       and sends the message 'closed' to the opener window via
 *       `window.opener.messageFromChildWindow()`, returning control to the
 *       main application without a page reload.
 *   3b. On failure: the user is redirected to the application login page with
 *       `loginFail=true` and an `errorMessage` parameter for display.
 *
 * Window model:
 *   ORCID login is initiated in a child popup window. This script runs inside
 *   that popup; the JavaScript it emits on success signals the parent window
 *   and closes the popup, keeping the user's main session intact.
 *
 * Note: {@see Bibliotheca_Content_Page} is instantiated with argument `1` to
 * suppress the standard authentication redirect, since the user is not yet
 * logged in when this callback is received.
 *
 * Note: No Content-Type header is set on the success path — the response is
 * a minimal HTML script block rather than a regular service payload, and the
 * browser will interpret it correctly without an explicit header.
 *
 * @package Biblhertz\Publink
 * @see     Login::orchidAuthenticate()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\utilities\Login;

// Instantiate with auth-redirect suppressed (arg 1) — the user is not yet
// logged in at this point in the OAuth flow
$page = new Bibliotheca_Content_Page(1);

if (isset($_REQUEST['code'])) {

    // Exchange the ORCID authorisation code for a session
    $login = Login::orchidAuthenticate($_REQUEST['code'], $page);

    if ($login) {
        // Success: emit JS to notify the parent window and close this popup.
        // messageFromChildWindow('closed') is the agreed signal that login
        // completed; the parent window handles any subsequent UI update.
        echo "<script type='text/javascript'>
                window.onload = function() {
                    window.opener.messageFromChildWindow('closed');
                    window.close();
                }
            </script>";
    } else {
        // Failure: redirect to the login page with error details for display
        $msg = $page->getErrorMessage();
        header("Location: ../index.html?loginFail=true&&errorMessage=$msg");
    }
}

$page = null;
exit;
?>