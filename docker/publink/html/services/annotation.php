<?php
/**
 * Annotation API Entry Point
 *
 * Front controller for the image annotation REST API service.
 * Handles incoming HTTP requests from Mirador IIIF viewer, validates
 * the requested endpoint against an allowlist, and dispatches to the
 * corresponding action method on {@see ImageAnnotationController}.
 *
 * Expected URI structure:
 *   /{base}/{version}/{prefix}/{endpoint}[/...]
 *   e.g. /api/v1/annotations/search
 *                              ^^^^^^
 *                              $uri[3] — the dispatched endpoint
 *
 * Endpoint resolution:
 *   The value at URI segment [3] must match an entry in
 *   {@see ImageAnnotationController::$allowedEndPoints}. The corresponding
 *   controller method is derived by appending 'Action' to the endpoint name
 *   (e.g. "search" → searchAction()).
 *
 * @package  Biblhertz\Publink
 * @see      ImageAnnotationController
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\annotation\ImageAnnotationController;
use Biblhertz\Publink\Config;

Config::setup();

// -------------------------------------------------------------------------
// Parse the request URI
// -------------------------------------------------------------------------

/** @var string $uri Raw request URI path, query string stripped */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/**
 * URI segments split on '/'.
 * Index 0 is always empty (leading slash); segment [3] is the endpoint name.
 *
 * @var string[] $uri
 */
$uri = explode('/', $uri);

error_log("API REQUEST :: $_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");
error_log("URI :: " . $uri[3]);

// -------------------------------------------------------------------------
// Validate the endpoint
// -------------------------------------------------------------------------

/**
 * Reject requests where the endpoint segment is missing or not in the
 * allowlist defined by ImageAnnotationController::$allowedEndPoints.
 * Returns HTTP 404 and halts execution for unrecognised endpoints.
 */
if (empty($uri[3]) || !in_array($uri[3], ImageAnnotationController::$allowedEndPoints)) {
    error_log("Sending not found message");
    header("HTTP/1.1 404 Not Found");
    exit;
}

// -------------------------------------------------------------------------
// Dispatch to controller
// -------------------------------------------------------------------------

/**
 * Instantiate the controller and initialise its database connection.
 * The action method name is built by appending 'Action' to the endpoint
 * segment, e.g. URI segment "search" → searchAction().
 */
$controller = new ImageAnnotationController();
$controller->setObjDB();

/** @var string $strMethodName Derived controller method name, e.g. "searchAction" */
$strMethodName = $uri[3] . 'Action';

error_log("Executing :: $strMethodName()");

$controller->{$strMethodName}();
exit;

?>