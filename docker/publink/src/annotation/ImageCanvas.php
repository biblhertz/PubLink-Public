<?php
namespace Biblhertz\Publink\annotation;

use Biblhertz\Publink\om\BHObject;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\PDODatabase;
use Biblhertz\Publink\annotation\ImageAnnotation;

/**
 * ImageCanvas
 *
 * Represents a single IIIF canvas within the PubLink annotation system.
 * Encapsulates the canvas URI, its parent manifest, and derived dimensions,
 * and provides methods for:
 *
 * - Generating IIIF Presentation API v3 AnnotationPage JSON for a canvas.
 * - Building full IIIF v3 Manifests that embed canvas annotations.
 * - Extracting canvas dimensions from a remote manifest (v2 and v3).
 * - Publishing and removing annotation manifests on a remote manifest server
 *   via cURL, and tracking publication records in the local database.
 *
 * Extends BHObject, which provides the $objDB database handle and generic
 * fetch/insert/update helpers.
 *
 * @package Biblhertz\Publink\annotation
 * @author  Chris Tomlinson
 * @since   March 2023
 */
class ImageCanvas extends BHObject
{

    /********************************************************************/
    /*  INSTANCE VARIABLES                                              */
    /********************************************************************/

    /** @var string URI of the IIIF canvas this object represents. */
    private string $canvas = "";

    /** @var string URL of the parent IIIF manifest for this canvas. */
    private string $manifest = "";

    /**
     * @var int Canvas height in pixels.
     *          Default is 1000; updated by extractCanvasSizes() when the manifest is fetched.
     */
    private int $height = 1000;

    /**
     * @var int Canvas width in pixels.
     *          Default is 2000; updated by extractCanvasSizes() when the manifest is fetched.
     */
    private int $width = 2000;


    /********************************************************************/
    /*  CONSTRUCTOR                                                     */
    /********************************************************************/

    /**
     * Construct an ImageCanvas instance with a database connection.
     *
     * Unlike ImageAnnotation, this constructor does not load a record from the
     * database. Canvas URI and manifest must be set explicitly via setCanvas()
     * and setManifest() before calling methods that depend on them.
     *
     * @param PDODatabase $objDB Active database connection.
     */
    public function __construct(PDODatabase $objDB)
    {
        $this->objDB = $objDB;
    }


    /********************************************************************/
    /*  GETTERS & SETTERS                                               */
    /********************************************************************/

    /**
     * Return the IIIF canvas URI.
     *
     * @return string
     */
    public function getCanvas(): string
    {
        return $this->canvas;
    }

    /**
     * Set the IIIF canvas URI.
     *
     * @param string $s Canvas URI.
     * @return void
     */
    public function setCanvas(string $s)
    {
        $this->canvas = $s;
    }

    /**
     * Return the parent IIIF manifest URL.
     *
     * @return string
     */
    public function getManifest(): string
    {
        return $this->manifest;
    }

    /**
     * Set the parent IIIF manifest URL.
     *
     * @param string $s Manifest URL.
     * @return void
     */
    public function setManifest(string $s)
    {
        $this->manifest = $s;
    }


    /********************************************************************/
    /*  PERMISSION CHECKS                                               */
    /********************************************************************/

    /**
     * Determine whether a user may edit this canvas object.
     *
     * Administrators may edit any canvas; regular users may only edit canvases
     * they own (matched via $this->uid).
     *
     * @param int $id user_details_id of the user to check.
     * @return bool True if the user has edit permission.
     */
    public function canEdit(int $id): bool
    {
        $user = new User($this->objDB, $id);
        if ($user->getUserGroup() == Config::$ADMINISTRATOR || $this->uid == $id) return true;
        return false;
    }

    /**
     * Determine whether a user may create canvas objects.
     *
     * Only administrators are permitted to create canvas records.
     *
     * @param int $id user_details_id of the user to check.
     * @return bool True if the user has create permission.
     */
    public function canCreate(int $id): bool
    {
        $user = new User($this->objDB, $id);
        if ($user->getUserGroup() == Config::$ADMINISTRATOR) return true;
        return false;
    }

    /**
     * Determine whether a user may delete this canvas object.
     *
     * Administrators may delete any canvas; regular users may only delete
     * canvases they own.
     *
     * @param int $id user_details_id of the user to check.
     * @return bool True if the user has delete permission.
     */
    public function canDelete(int $id): bool
    {
        $user = new User($this->objDB, $id);
        if ($user->getUserGroup() == Config::$ADMINISTRATOR || $this->uid == $id) return true;
        return false;
    }

    /**
     * Determine whether a user may view this canvas.
     *
     * All canvases are publicly viewable.
     *
     * @param int $id user_details_id of the user to check.
     * @return bool Always true.
     */
    public function canView(int $id): bool
    {
        return true;
    }


    /********************************************************************/
    /*  IIIF JSON METHODS                                               */
    /********************************************************************/

    /**
     * Fetch all annotations visible to a user on a given canvas.
     *
     * Returns owned annotations and those shared with the user via
     * `user_details_canvas_annotation` in a single query.
     *
     * @param string $uri Canvas URI.
     * @param int    $uid user_details_id of the requesting user.
     * @return \PDOStatement Result set with rows containing keys 'annotation' and 'id'.
     */
    private function getVisibleAnnotations(string $uri, int $uid): \PDOStatement
    {
        return $this->objDB->preparedSelect(
            "select annotation, id from image_annotation where
             canvas = ? and
             (user_details_id = ? or
             user_details_id in
             (select owner_id from user_details_canvas_annotation where user_details_id = ? and canvas = ?))",
            array($uri, $uid, $uid, $uri)
        );
    }

    /**
     * Build a IIIF Presentation API v3 AnnotationPage JSON string containing all
     * annotations visible to a user on this canvas.
     *
     * Fetches both annotations owned by the user and annotations shared with the
     * user via `user_details_canvas_annotation` in a single query, then
     * concatenates the raw stored annotation JSON blobs into the items array.
     *
     * The returned AnnotationPage `id` encodes the canvas URI and session ID so
     * that Mirador can use it as a self-referencing endpoint URL.
     *
     * @param array $params Resolved parameter array from getUserAndURIFromQueryString(),
     *                      containing keys: 'user' (display name), 'uri' (canvas URI),
     *                      'session' (ASCII session ID).
     * @return string IIIF v3 AnnotationPage JSON string.
     */
    public function getAnnotationPage(array $params): string
    {
        $uid       = User::userExists($params['user']);
        $uri       = $params['uri'];
        $sessionId = $params['session'];

        // Single query combining owned and shared annotations for this canvas.
        $annotations = $this->getVisibleAnnotations($uri, $uid);

        // Concatenate raw annotation JSON blobs into a comma-separated list.
        $str = "";
        foreach ($annotations as $ann) {
            $str .= $ann['annotation'] . ",";
        }

        // Strip the trailing comma before embedding in the JSON array.
        if (strlen($str)) $str = substr($str, 0, strlen($str) - 1);

        $serverURI = Config::$ANNOTATION_ENDPOINT . "/pages?uri=" . urlencode($uri) . "&user=" . urlencode($sessionId);
        $json = "{\"@context\":\"http://iiif.io/api/presentation/3/context.json\","
              . "\"id\":\"$serverURI\","
              . "\"label\":\"Annotation Page for Manifest created by Publink\","
              . "\"type\":\"AnnotationPage\","
              . "\"items\":[$str]}";

        return $json;
    }

    /**
     * Build an IIIF v3 Image body array for a painting annotation.
     * Includes the IIIF Image API service when a service URL is available.
     */
    private function buildImageBody(string $imageUrl, string $serviceId): array
    {
        $body = [
            'id'     => $imageUrl,
            'type'   => 'Image',
            'format' => 'image/jpeg',
            'height' => $this->height,
            'width'  => $this->width,
        ];
        if ($serviceId) {
            $body['service'] = [[
                'id'      => $serviceId,
                'type'    => 'ImageService3',
                'profile' => 'level2',
            ]];
        }
        return $body;
    }

    /**
     * Build a complete IIIF Presentation API v3 Manifest that embeds annotation
     * data for this canvas.
     *
     * The manifest contains a single canvas with:
     * - A painting annotation pointing to the full canvas image via the IIIF
     *   Image API (resolved from the parent manifest's service URL).
     * - An AnnotationPage in the canvas `annotations` array containing all
     *   selected annotations serialised via ImageAnnotation::getJSONAnnotationPage().
     *
     * Annotations can be filtered in two ways:
     * - Pass null for $annotationIds to include all visible annotations for the user.
     * - Pass an array of DB primary keys to include only specific annotations.
     *
     * Canvas dimensions are taken from $this->height and $this->width; call
     * extractCanvasSizes() beforehand if accurate dimensions are needed.
     *
     * @param array       $params        Resolved params array ('user', 'uri', 'session').
     * @param string      $title         Manifest and canvas label. Defaults to "Annotation Manifest".
     * @param string      $fileName      Filename used to construct the manifest's public URL.
     *                                   Defaults to "manifest.json"; auto-generated if empty.
     * @param mixed       $annotationIds null to include all visible annotations, or an array
     *                                   of image_annotation primary keys to include selectively.
     * @return string Pretty-printed IIIF v3 Manifest JSON string.
     */
    public function getAnnotationManifest(
        array $params,
        string $title = "",
        string $fileName = "manifest.json",
        mixed $annotationIds = null
    ): string {
        $uid = User::userExists($params['user']);
        $uri = $params['uri'];

        if (empty($fileName)) $fileName = uniqid() . "_manifest.json";
        if (empty($title))    $title    = "Annotation Manifest";

        $manifestId = Config::$PUBLICATION_ENDPOINT . $fileName;

        // Resolve the full image URL from the IIIF Image API service in the parent manifest.
        $images    = ImageAnnotation::getServiceUrls($this->manifest, $uri);
        $image     = "";
        $serviceId = "";
        if (isset($images[0])) {
            $serviceId = $images[0];
            $image     = $serviceId . "/full/full/0/default.jpg";
        }

        // Build the annotation items array — either all visible annotations or a filtered subset.
        $annArr = array();

        if (!is_array($annotationIds)) {
            // Fetch all annotations visible to this user on the canvas.
            $annotations = $this->getVisibleAnnotations($uri, $uid);
            while ($annotation = $annotations->fetch()) {
                $ann = new ImageAnnotation($this->objDB, $annotation['id']);
                array_push($annArr, $ann->getJSONAnnotationPage());
            }
        } else {
            // Include only the explicitly specified annotation IDs.
            foreach ($annotationIds as $aid) {
                $ann = new ImageAnnotation($this->objDB, $aid);
                array_push($annArr, $ann->getJSONAnnotationPage());
            }
        }

        // Build the AnnotationPage that will be embedded in the canvas `annotations` array.
        $json          = array();
        $json['id']    = "$manifestId/annotations";
        $json['type']  = "AnnotationPage";
        $json['items'] = $annArr;

        $manifest = [
            '@context' => [
                'http://www.w3.org/ns/anno.jsonld',
                'http://iiif.io/api/presentation/3/context.json'
            ],
            'id'    => $manifestId,
            'type'  => 'Manifest',
            'label' => ['en' => [$title]],
            'items' => [
                [
                    'id'     => $this->canvas,
                    'type'   => 'Canvas',
                    'partOf' => [
                        [
                            'id'   => $this->manifest,
                            'type' => "Manifest"
                        ]
                    ],
                    'label'  => ['en' => [$title]],
                    'height' => $this->height,
                    'width'  => $this->width,
                    'items'  => [
                        [
                            'id'    => $this->canvas . '/page/painting',
                            'type'  => 'AnnotationPage',
                            'items' => [
                                [
                                    'id'         => $this->canvas . '/annotation/image',
                                    'type'       => 'Annotation',
                                    'motivation' => 'painting',
                                    'body'       => $this->buildImageBody($image, $serviceId),
                                    'target' => $this->canvas
                                ]
                            ]
                        ]
                    ],
                    'annotations' => [$json]
                ]
            ]
        ];

        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Fetch the parent manifest and extract the dimensions of the current canvas.
     *
     * Supports both IIIF Presentation API v2 (sequences/canvases) and v3 (items).
     * Matches canvases by URI against $this->canvas and, when a match is found,
     * updates $this->height and $this->width with the extracted values.
     *
     * @return array Array of dimension records for matched canvases, each with keys:
     *               'id', 'label', 'width', 'height'. Typically contains at most one entry.
     */
    public function extractCanvasSizes(): array
    {
        $canvasSizes = [];
        $raw = file_get_contents(str_replace('https://', 'http://', $this->manifest));
        if ($raw === false) return $canvasSizes;
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) return $canvasSizes;

        // IIIF Presentation API v3 — items array of canvases.
        if (isset($manifest['items'])) {
            foreach ($manifest['items'] as $canvas) {
                $id = $canvas['id'] ?? $canvas['@id'] ?? 'unknown';
                if (!strcmp($this->canvas, $id))
                    $size = self::getCanvasSize($canvas);
                else
                    $size = null;
                if ($size) {
                    $canvasSizes[]  = $size;
                    $this->height   = $size['height'];
                    $this->width    = $size['width'];
                }
            }
        }
        // IIIF Presentation API v2 — sequences → canvases.
        elseif (isset($manifest['sequences'])) {
            foreach ($manifest['sequences'] as $sequence) {
                if (isset($sequence['canvases'])) {
                    foreach ($sequence['canvases'] as $canvas) {
                        $id = $canvas['id'] ?? $canvas['@id'] ?? 'unknown';
                        if (!strcmp($this->canvas, $id))
                            $size = self::getCanvasSize($canvas);
                        else
                            $size = null;
                        if ($size) {
                            $canvasSizes[]  = $size;
                            $this->height   = $size['height'];
                            $this->width    = $size['width'];
                        }
                    }
                }
            }
        }

        return $canvasSizes;
    }

    /**
     * Extract the id, label, width, and height from a single canvas object.
     *
     * Handles both IIIF v2 (`@id`, `@label`) and v3 (`id`, `label`) canvas
     * structures, and normalises multilingual label arrays to a single string.
     * Returns null if neither width nor height is present, so the caller can
     * safely skip canvases with missing dimension data.
     *
     * @param array $canvas Associative canvas object from a parsed IIIF manifest.
     * @return array|null Associative array with keys 'id', 'label', 'width', 'height',
     *                    or null if dimensions are absent.
     */
    private static function getCanvasSize(array $canvas): ?array
    {
        $result = [
            'id'     => $canvas['id'] ?? $canvas['@id'] ?? 'unknown',
            'label'  => null,
            'width'  => null,
            'height' => null
        ];

        // Normalise label — v3 uses a language-map array; v2 uses a plain string or @label.
        if (isset($canvas['label'])) {
            if (is_array($canvas['label'])) {
                $result['label'] = reset($canvas['label'])[0] ?? 'Untitled';
            } else {
                $result['label'] = $canvas['label'];
            }
        } elseif (isset($canvas['@label'])) {
            $result['label'] = $canvas['@label'];
        }

        $result['width']  = $canvas['width']  ?? null;
        $result['height'] = $canvas['height'] ?? null;

        return ($result['width'] && $result['height']) ? $result : null;
    }


    /********************************************************************/
    /*  PUBLICATION — LOCAL DATABASE                                    */
    /********************************************************************/

    /**
     * Record a published manifest URL in the local database.
     *
     * Inserts a row into `canvas_publication` linking the manifest URL,
     * the canvas URI, and the publishing user. This record is used by
     * getPublishedManifests() to track what has been published.
     *
     * @param string $url URL of the published manifest on the remote server.
     * @param int    $uid user_details_id of the publishing user.
     * @return void
     */
    public function publishCanvas(string $url, int $uid)
    {
        $vals = array();
        $vals['manifest']         = $url;
        $vals['canvas']           = $this->canvas;
        $vals['user_details_id']  = $uid;
        $this->objDB->insert("canvas_publication", $vals);
    }

    /**
     * Remove a published manifest record from the local database.
     *
     * Deletes the row from `canvas_publication` matching the manifest URL and
     * user. Does not contact the remote server — use removeManifest() to also
     * delete the file from the remote publication endpoint.
     *
     * @param string $url URL of the manifest to unpublish.
     * @param int    $uid user_details_id of the publishing user.
     * @return void
     */
    public function removeCanvas(string $url, int $uid)
    {
        $this->objDB->preparedStatement(
            "delete from canvas_publication where manifest = ? and user_details_id = ?",
            array($url, $uid)
        );
    }

    /**
     * Return the list of successfully reachable published manifests for this canvas and user.
     *
     * Fetches all `canvas_publication` records for the user and canvas, then
     * performs a live HTTP check on each manifest URL. Manifests that cannot be
     * fetched are assumed to have been removed from the remote server and are
     * deleted from the local database automatically.
     *
     * Note: HTTPS URLs are temporarily rewritten to HTTP for the reachability
     * check due to a known network/SSL configuration issue. The original HTTPS
     * URL is still stored and returned in the result array.
     *
     * @param int $uid user_details_id of the publishing user.
     * @return array Flat array of reachable manifest URL strings (HTTPS).
     */
    public function getPublishedManifests(int $uid): array
    {
        $manifests = $this->objDB->preparedSelect(
            "select id, manifest from canvas_publication 
             where user_details_id = ? and canvas = ?",
            array($uid, $this->canvas)
        );

        $manArr  = array();
        $context = stream_context_create([
            'http' => [
                'timeout'      => 30,
                'user_agent'   => 'Mozilla/5.0 (compatible; PHP)',
                'ignore_errors' => true
            ]
        ]);

        while ($manifest = $manifests->fetch()) {
            // Rewrite to HTTP as a workaround for SSL verification issues on this network.
            $url     = str_replace("https", "http", $manifest['manifest']);
            $content = file_get_contents($url, false, $context);

            if ($content) {
                $manArr[] = $manifest['manifest']; // Return the original HTTPS URL.
            } else {
                // Manifest is no longer reachable — clean up the stale DB record.
                $this->objDB->preparedStatement(
                    "delete from canvas_publication where id = ?",
                    array($manifest['id'])
                );
            }
        }

        return $manArr;
    }


    /********************************************************************/
    /*  PUBLICATION — REMOTE SERVER (cURL)                              */
    /********************************************************************/

    /**
     * Upload an annotation manifest to the remote publication server via cURL.
     *
     * Sends a POST request to Config::$PUBLICATION_PUBLISH_API with HTTP Basic
     * authentication (Config::$PUBLICATION_CREDENTIALS). The payload is a JSON
     * object containing the manifest content, a generated filename, and series/
     * volume identifiers required by the remote API.
     *
     * The manifest filename is auto-generated with uniqid() to avoid collisions.
     * The remote server is expected to respond with a JSON confirmation or error.
     *
     * @param string $manifest The IIIF manifest JSON string to publish.
     * @param string $apiKey   Annotation server API key, supplied by the caller for
     *                         this request. Not read from Config — the caller is
     *                         responsible for obtaining it fresh each time.
     * @return string|false Raw response string from the remote publication API, or false on failure.
     */
    public function publishManifest(string $manifest, string $apiKey): string|false
    {
        $name = uniqid() . ".json";

        // Use the HTTP base URL for the manifest id — FILE_STORE_URL is HTTP
        // internally (gateway handles HTTPS externally). manifests.php returns
        // the HTTPS version of the URL in its response.
        $manifestUrl = rtrim(Config::$PUBLICATION_ENDPOINT, '/') . '/' . $name;

        $data  = json_decode($manifest, true);
        $oldId = $data['id'] ?? '';
        $data['id'] = $manifestUrl;

        // Update annotation page IDs that referenced the old manifest id.
        foreach ($data['items'] ?? [] as &$canvas) {
            foreach ($canvas['annotations'] ?? [] as &$annPage) {
                if (($annPage['id'] ?? '') === $oldId . '/annotations') {
                    $annPage['id'] = $manifestUrl . '/annotations';
                }
            }
        }
        unset($canvas, $annPage);

        $json   = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $apiUrl = rtrim(Config::$MANIFEST_SERVER_URI, '/') . '/api/manifests';

        $curl = curl_init($apiUrl);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $output    = curl_exec($curl);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($curlError || $output === false) {
            return false;
        }

        return $output;
    }

    /**
     * Remove a published manifest from the remote server via cURL, and delete
     * the corresponding local database record.
     *
     * Calls removeCanvas() first to clean up the local `canvas_publication` entry,
     * then sends a POST request to Config::$PUBLICATION_REMOVE_API with the
     * manifest URL as the payload, using HTTP Basic authentication.
     *
     * @param string $url    URL of the manifest to remove from the remote server.
     * @param int    $uid    user_details_id of the owner, used by removeCanvas().
     * @param string $apiKey Annotation server API key, supplied by the caller for
     *                       this request. Not read from Config — the caller is
     *                       responsible for obtaining it fresh each time.
     * @return string|false Raw response string from the remote removal API, or false on failure.
     */
    public function removeManifest(string $url, int $uid, string $apiKey): string|false
    {
        // Remove the local DB record first so the canvas is no longer listed as published.
        $this->removeCanvas($url, $uid);

        $payload = json_encode(array('url' => $url));

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_HTTPHEADER,      ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded', 'X-API-Key: ' . $apiKey]);
        curl_setopt($curl, CURLOPT_URL,              Config::$PUBLICATION_REMOVE_API);
        curl_setopt($curl, CURLOPT_POST,             1);
        curl_setopt($curl, CURLOPT_HEADER,           0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER,   1);
        curl_setopt($curl, CURLOPT_POSTFIELDS,       http_build_query(array("data" => $payload)));
        curl_setopt($curl, CURLOPT_SSL_OPTIONS,      CURLSSLOPT_NATIVE_CA);

        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }
}
?>