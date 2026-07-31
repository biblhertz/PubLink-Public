<?php
/**
 * Session Check Service
 *
 * Checks whether the current user is actively logged in by querying the
 * `user_session` table using the native PHP session identifier.
 *
 * The result is the raw `logged_in` column value from `user_session`, intended
 * for use by polling clients (e.g. a JavaScript interval) to detect session
 * expiry and trigger a logout or redirect on the front end.
 *
 * Note: {@see Bibliotheca_Content_Page} is instantiated with argument `1`,
 * which suppresses the usual authentication redirect, allowing this service
 * to respond even when the session has expired rather than bouncing to a
 * login page.
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: The `logged_in` column value (typically 1 for active, 0 for expired).
 *
 * @package Biblhertz\Publink
 * @see     Bibliotheca_Content_Page
 * @see     UserSession::getNativeSessionIdentifier()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;

// Instantiate with logged in suppressed (arg 1) 
$page = new Bibliotheca_Content_Page(1);

// Look up the logged_in flag for the current session identifier
$logged = $page->getObjDB()->preparedGetOne(
    "SELECT logged_in FROM user_session WHERE id = ?",
    array($page->getUserSession()->getNativeSessionIdentifier())
);

header('Content-Type: text/html; charset=UTF-8');
echo $logged;

$page = null;
exit;
?>