<?php
namespace Biblhertz\Publink\annotation;

use Biblhertz\Publink\annotation\BaseController;
use Biblhertz\Publink\annotation\ImageAnnotation;
use Biblhertz\Publink\annotation\ImageCanvas;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\PDODatabase;

/**
 * ImageAnnotationController
 *
 * REST API controller for the Image Annotation service. Extends BaseController
 * and exposes five endpoints consumed by the Mirador viewer:
 *
 * | Endpoint    | Method | Description                                      |
 * |-------------|--------|--------------------------------------------------|
 * | /pages      | GET    | Return all annotations for a canvas as IIIF v3   |
 * | /create     | POST   | Persist a new annotation from Mirador JSON        |
 * | /delete     | POST   | Delete an annotation by UUID                      |
 * | /update     | POST   | Update an existing annotation by UUID             |
 * | /manifest   | GET    | Return the IIIF manifest for a canvas             |
 *
 * Each public *Action() method maps to one endpoint and follows the same
 * pattern: validate HTTP method → process → call sendResponse().
 *
 * The static $objDB handle must be initialised once per request via
 * setObjDB() before any action method is called.
 *
 * @package Biblhertz\Publink\annotation
 */
class ImageAnnotationController extends BaseController
{

    /********************************************************************/
    /*  INSTANCE VARIABLES                                              */
    /********************************************************************/

    /** @var array Parsed query string parameters for the current request. */
    private array $queryStringParams = array();

    /** @var string Response body text used when an error has occurred. */
    private string $responseText = "";

    /**
     * @var string HTTP status header string used in error responses.
     *             e.g. 'HTTP/1.1 500 Internal Server Error'
     */
    private string $responseHeader = "";

    /** @var bool True when an error has been set; causes sendResponse() to return an error payload. */
    private bool $error = false;


    /********************************************************************/
    /*  STATIC VARIABLES                                                */
    /********************************************************************/

    /** @var PDODatabase Shared database connection, lazily initialised by setObjDB(). */
    private static PDODatabase $objDB;

    /**
     * @var array Allowlist of valid API endpoint names.
     *            Only URIs matching one of these values will be routed to an action method.
     */
    public static array $allowedEndPoints = array("pages", "create", "delete", "update", "manifest");


    /********************************************************************/
    /*  INITIALISATION                                                  */
    /********************************************************************/

    /**
     * Initialise the shared database connection if it has not been set already.
     *
     * Should be called once at bootstrap before any action methods are dispatched.
     *
     * @return void
     */
    public static function setObjDB()
    {
        if (!isset(self::$objDB)) self::$objDB = new PDODatabase();
    }


    /********************************************************************/
    /*  PRIVATE UTILITY METHODS                                         */
    /********************************************************************/

    /**
     * Parse the query string and resolve the requesting user's display name and
     * canvas URI from the supplied parameters.
     *
     * Expects the following query string keys:
     * - `user`  — the ASCII PHP session ID used to look up the user in user_session.
     * - `uri`   — the IIIF canvas URI being queried.
     *
     * @return array Associative array with keys:
     *               - 'user'    (string|null) — resolved display name from user_details.
     *               - 'uri'     (string|null) — canvas URI.
     *               - 'session' (string)      — raw session ID from the query string.
     */
    private function getUserAndURIFromQueryString(): array
    {
        $this->queryStringParams = $this->getQueryStringParams();
        $user = $uri = $usersession = null;

        if (isset($this->queryStringParams['user']) && ($this->queryStringParams['user'])) {
            // The 'user' field carries the PHP session ID, not the user name directly.
            $usersession = $this->queryStringParams['user'];
            $user = self::$objDB->preparedGetOne(
                "select name from user_details where id = (select user_id from user_session where ascii_session_id = ?)",
                array($usersession)
            );
        }

        if (isset($this->queryStringParams['uri']) && ($this->queryStringParams['uri'])) {
            $uri = $this->queryStringParams['uri'];
        }

        return array("user" => $user, "uri" => $uri, "session" => $usersession);
    }

    /**
     * Populate the error state for an unexpected server-side exception.
     *
     * Sets the response text to the exception message plus a user-facing prompt,
     * sets the HTTP status to 500, and flags $this->error so sendResponse() will
     * return the error payload instead of normal data.
     *
     * @param \Throwable $e The caught Throwable instance.
     * @return void
     */
    private function setInternalErrorMessage(\Throwable $e)
    {
        $this->responseText   = $e->getMessage() . 'Something went wrong! Please contact support.';
        $this->responseHeader = 'HTTP/1.1 500 Internal Server Error';
        $this->error          = true;
    }

    /**
     * Populate the error state for an unsupported HTTP method.
     *
     * Sets the HTTP status to 422 Unprocessable Entity and flags $this->error
     * so sendResponse() returns the error payload.
     *
     * @return void
     */
    private function setMethodNotSupportedMessage()
    {
        $this->responseText   = 'Method not supported';
        $this->responseHeader = 'HTTP/1.1 422 Unprocessable Entity';
        $this->error          = true;
    }

    /**
     * Dispatch the final HTTP response.
     *
     * If no error has been flagged, sends $data with a 200 OK and
     * application/json content type. Otherwise sends a JSON error object
     * with the error status header set by the failing utility method.
     *
     * Delegates to BaseController::sendOutput(), which calls exit() after output.
     *
     * @param string $data JSON string to send on success.
     * @return void
     */
    private function sendResponse(string $data)
    {
        if (!$this->error) {
            $this->sendOutput(
                $data,
                array('Content-Type: application/json', 'HTTP/1.1 200 OK')
            );
        } else {
            $this->sendOutput(
                json_encode(array('error' => $this->responseText)),
                array('Content-Type: application/json', $this->responseHeader)
            );
        }
    }

    /**
     * Retrieve all annotations visible to a user for a specific canvas and
     * serialise them as a IIIF Presentation API v3 AnnotationPage JSON string.
     *
     * Two annotation sources are combined:
     * 1. Annotations owned directly by the user on this canvas.
     * 2. Annotations shared with the user by another owner via
     *    `user_details_canvas_annotation`.
     *
     * The items array in the returned JSON is a comma-joined list of raw
     * annotation JSON blobs as stored in the database.
     *
     * @param array $params Resolved parameter array from getUserAndURIFromQueryString(),
     *                      containing keys: 'user', 'uri', 'session'.
     * @return string IIIF v3 AnnotationPage JSON string.
     */
    private function getPagesHeader(array $params): string
    {
        $uid       = User::userExists($params['user']);
        $uri       = $params['uri'];
        $sessionId = $params['session'];

        // Fetch annotations owned by this user on the requested canvas.
        $annotations = self::$objDB->preparedSelect(
            "select annotation from image_annotation where user_details_id = ? and canvas = ?",
            array($uid, $uri)
        );

        $str = "";
        foreach ($annotations as $ann) {
            $str .= $ann['annotation'] . ",";
        }

        // Append annotations shared with this user by other owners on the same canvas.
        $annotations = self::$objDB->preparedSelect(
            "select annotation from image_annotation where 
             canvas = ? and 
             user_details_id in 
             (select owner_id from user_details_canvas_annotation where user_details_id = ? and canvas = ?)",
            array($uri, $uid, $uri)
        );

        foreach ($annotations as $ann) {
            $str .= $ann['annotation'] . ",";
        }

        // Strip the trailing comma before embedding in the JSON array.
        if (strlen($str)) $str = substr($str, 0, strlen($str) - 1);

        $serverURI = Config::$ANNOTATION_ENDPOINT . "/pages";
        return "{\"@context\":\"http://iiif.io/api/presentation/3/context.json\","
             . "\"id\":\"".$serverURI."?uri=".urlencode($uri)."&user=".urlencode($sessionId)."\","
             . "\"type\":\"AnnotationPage\","
             . "\"items\":[$str]}";
    }


    /********************************************************************/
    /*  ENDPOINTS                                                       */
    /********************************************************************/

    /**
     * Handle GET /pages
     *
     * Returns a IIIF v3 AnnotationPage containing all annotations visible to the
     * requesting user on the specified canvas. Delegates to ImageCanvas to build
     * the response.
     *
     * Query string parameters:
     * - `user` — PHP session ID of the requesting user.
     * - `uri`  — IIIF canvas URI to retrieve annotations for.
     *
     * Supported methods: GET
     * Success response: 200 OK — IIIF AnnotationPage JSON.
     * Error responses:  422 if method is not GET; 500 on exception.
     *
     * @return void Output is sent directly via sendResponse().
     */
    public function pagesAction()
    {
        $requestMethod = $_SERVER["REQUEST_METHOD"];

        $responseData = "";

        if (strtoupper($requestMethod) == 'GET') {
            try {
                $params       = $this->getUserAndURIFromQueryString();
                $canvas       = new ImageCanvas(self::$objDB);
                $responseData = $canvas->getAnnotationPage($params);
            } catch (\Throwable $e) {
                $this->setInternalErrorMessage($e);
            }
        } else {
            $this->setMethodNotSupportedMessage();
        }

        $this->sendResponse($responseData);
    }


    /**
     * Handle POST /create
     *
     * Receives a Mirador annotation JSON payload from the request body, creates
     * a new ImageAnnotation record, and persists it to the database. The edited
     * flag is set to 1 on creation to signal downstream sync services.
     *
     * Request body: Raw Mirador annotation JSON (see ImageAnnotation::createFromJSON).
     *
     * Supported methods: POST
     * Success response: 200 OK — empty body.
     * Error responses:  422 if method is not POST; 500 on exception.
     *
     * @return void Output is sent directly via sendResponse().
     */
    public function createAction()
    {
        $requestMethod = $_SERVER["REQUEST_METHOD"];

        $responseData = "";

        if (strtoupper($requestMethod) == 'POST') {
            try {
                $json       = file_get_contents("php://input");
                $annotation = new ImageAnnotation(self::$objDB, 0);
                $annotation->createFromJSON($json);
                $annotation->setEdited(1);
                $annotation->updateImageAnnotation();
                //error_log("JSON RECEIVED :: $json");
                $responseData = "";
            } catch (\Throwable $e) {
                $this->setInternalErrorMessage($e);
            }
        } else {
            $this->setMethodNotSupportedMessage();
        }

        $this->sendResponse($responseData);
    }


    /**
     * Handle POST /delete
     *
     * Receives a JSON payload identifying the annotation to delete by UUID and
     * session creator. Resolves the user ID from the session, looks up the
     * annotation DB primary key, then calls deleteImageAnnotation() which also
     * cleans up canvas-sharing records and signals downstream sync.
     *
     * The delete is skipped silently if either the user ID or the annotation ID
     * cannot be resolved, preventing unauthorised or phantom deletions.
     *
     * Request body JSON keys:
     * - `uuid`    — Mirador annotation UUID.
     * - `creator` — ASCII session ID of the requesting user.
     *
     * Supported methods: POST
     * Success response: 200 OK — empty body.
     * Error responses:  422 if method is not POST; 500 on exception.
     *
     * @return void Output is sent directly via sendResponse().
     */
    public function deleteAction()
    {
        $requestMethod = $_SERVER["REQUEST_METHOD"];

        $responseData = "";

        if (strtoupper($requestMethod) == 'POST') {
            try {
                $json = file_get_contents("php://input");
                $arr  = json_decode($json, true);

                // Resolve user ID from the session identifier in the payload.
                $uid = self::$objDB->preparedGetOne(
                    "select id from user_details where id = (select user_id from user_session where ascii_session_id = ?)",
                    array($arr['creator'])
                );

                // Look up the annotation's DB primary key by Mirador UUID and owner.
                $aid = self::$objDB->preparedGetOne(
                    "select id from image_annotation where annotation_id = ? and user_details_id = ?",
                    array($arr['uuid'], $uid)
                );

                // Guard: only proceed if both IDs were successfully resolved.
                if (isset($uid) && $uid > 0 && isset($aid) && $aid > 0) {
                    $annotation = new ImageAnnotation(self::$objDB, $aid);
                    $annotation->deleteImageAnnotation($uid);
                }

                $responseData = "";
            } catch (\Throwable $e) {
                $this->setInternalErrorMessage($e);
            }
        } else {
            $this->setMethodNotSupportedMessage();
        }

        $this->sendResponse($responseData);
    }


    /**
     * Handle POST /update
     *
     * Receives a Mirador annotation JSON payload and updates the matching
     * database record. The annotation is located by its Mirador UUID and owner,
     * then repopulated from the incoming JSON and re-saved with edited=1 to
     * flag the change for downstream sync.
     *
     * Request body: Raw Mirador annotation JSON (see ImageAnnotation::createFromJSON).
     * JSON keys used directly: annotation.creator (session ID), annotation.uuid.
     *
     * Supported methods: POST
     * Success response: 200 OK — empty body.
     * Error responses:  422 if method is not POST; 500 on exception.
     *
     * @return void Output is sent directly via sendResponse().
     */
    public function updateAction()
    {
        $requestMethod = $_SERVER["REQUEST_METHOD"];

        $responseData = "";

        if (strtoupper($requestMethod) == 'POST') {
            try {
                $json       = file_get_contents("php://input");
                $annotation = new ImageAnnotation(self::$objDB, 0);
                $arr        = json_decode($json, true);

                // Resolve user ID from the session identifier in the payload.
                $uid = self::$objDB->preparedGetOne(
                    "select id from user_details where id = (select user_id from user_session where ascii_session_id = ?)",
                    array($arr['annotation']['creator'])
                );

                // Find the existing annotation's DB primary key by Mirador UUID and owner.
                $annId = $arr['annotation']['uuid'];
                $aid   = self::$objDB->preparedGetOne(
                    "select id from image_annotation where annotation_id = ? and user_details_id = ?",
                    array($annId, $uid)
                );

                // Guard: only proceed if both IDs were successfully resolved.
                if (isset($uid) && $uid > 0 && isset($aid) && $aid > 0) {
                    // Populate from JSON, assign the existing DB ID so updateImageAnnotation()
                    // performs an UPDATE rather than an INSERT.
                    $annotation->createFromJSON($json);
                    $annotation->setID($aid);
                    $annotation->setEdited(1);
                    $annotation->updateImageAnnotation();
                }
            } catch (\Throwable $e) {
                $this->setInternalErrorMessage($e);
            }
        } else {
            $this->setMethodNotSupportedMessage();
        }

        $this->sendResponse($responseData);
    }


    /**
     * Handle GET /manifest
     *
     * Returns the IIIF manifest for the canvas specified in the `uri` query
     * parameter, with annotation page references injected by ImageCanvas.
     *
     *
     * Query string parameters:
     * - `user` — PHP session ID of the requesting user.
     * - `uri`  — IIIF canvas URI whose manifest should be returned.
     *
     * Supported methods: GET
     * Success response: 200 OK — IIIF Manifest JSON.
     * Error responses:  422 if method is not GET; 500 on exception.
     *
     * @return void Output is sent directly via sendResponse().
     */
    public function manifestAction()
    {
        $requestMethod = $_SERVER["REQUEST_METHOD"];

        $responseData = "";

        if (strtoupper($requestMethod) == 'GET') {
            try {
                $params = $this->getUserAndURIFromQueryString();
                $canvas = new ImageCanvas(self::$objDB);
                $canvas->setCanvas($params['uri']);
                $responseData = $canvas->getManifest();
            } catch (\Throwable $e) {
                $this->setInternalErrorMessage($e);
            }
        } else {
            $this->setMethodNotSupportedMessage();
        }

        $this->sendResponse($responseData);
    }
}