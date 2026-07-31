<?php
namespace Biblhertz\Publink\annotation;

use Biblhertz\Publink\om\BHObject;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\PDODatabase;
use PDOStatement;
use Biblhertz\Publink\annotation\SVGPathFixer;

/**
 * ImageAnnotation
 *
 * Represents a single IIIF image annotation stored in the PubLink system.
 * Annotations are authored by users in the Mirador viewer and persisted to
 * the `image_annotation` database table. This class handles the full lifecycle
 * of an annotation: creation from JSON, storage, retrieval, sharing, deletion,
 * and serialisation back to IIIF Presentation API v3 format.
 *
 * Extends BHObject, which provides generic DB fetch/insert/update helpers and
 * exposes $this->objDB (PDODatabase) and $this->tableName.
 *
 * @package Biblhertz\Publink\annotation
 * @author  Chris Tomlinson
 * @since   March 2023
 */
class ImageAnnotation extends BHObject
{

    /********************************************************************/
    /*  INSTANCE VARIABLES                                              */
    /********************************************************************/

    /** @var string Username of the annotation author. */
    private string $username = "";

    /** @var int Primary key of the author in the user_details table. */
    private int $uid = 0;

    /** @var string URI of the IIIF canvas this annotation targets. */
    private string $canvas = "";

    /** @var string Raw JSON string of the annotation as stored / received from Mirador. */
    private string $annotation = "";

    /** @var string UUID assigned to this annotation by Mirador. */
    private string $annotationId = "";

    /**
     * @var int Dirty flag set to 1 when Mirador has modified the annotation since
     *          last sync, allowing downstream services to detect and pull updates.
     */
    private int $edited = 0;

    /** @var string Plain-text body extracted from the annotation JSON. */
    private string $annotationText = "";

    /** @var string URI of the parent IIIF manifest. */
    private string $manifest = "";

    /** @var string IIIF fragment selector value (xywh= format) describing the targeted region. */
    private string $fragmentSelector;

    /** @var string Raw SVG path string from the annotation's SvgSelector, if present. */
    private string $svgSelector;

    /** @var string Base IIIF Image API root URL used to construct derivative image URLs. */
    private string $imageRoot = "";

    /** @var string IIIF Image API URL for a 200×200 thumbnail of the full canvas image. */
    private string $thumbnailURL = "";

    /** @var string IIIF Image API URL for an 80×80 small thumbnail of the full canvas image. */
    private string $smallThumbnailURL = "";

    /** @var string IIIF Image API URL cropped to the annotated fragment region at 200×200. */
    private string $fragmentURL = "";


    /********************************************************************/
    /*  CONSTRUCTOR                                                     */
    /********************************************************************/

    /**
     * Construct an ImageAnnotation instance.
     *
     * When $id > 0, the annotation record is fetched from the database and all
     * instance properties are populated, including derived image URLs.
     * When $id is 0 or negative, an empty shell is created ready to be populated
     * via createFromJSON() before being persisted with updateImageAnnotation().
     *
     * @param PDODatabase $objDB Active database connection.
     * @param int         $id    Primary key of the annotation to load, or 0 for a new record.
     */
    public function __construct(PDODatabase $objDB, int $id)
    {
        $this->tableName = "image_annotation";
        $this->objDB = $objDB;

        if (isset($id) && $id > 0) {
            $this->id = $id;
            $row = $this->fetchItem($id);
            if (isset($row)) {
                $this->uid = $row['user_details_id'];
                $this->username = $this->objDB->preparedGetOne(
                    "select name from user_details where id = ?",
                    array($row['user_details_id'])
                );
                $this->canvas          = $row['canvas'];
                $this->annotation      = $row['annotation'];
                $this->annotationId    = $row['annotation_id'];
                $this->manifest        = $row['manifest'];
                $this->fragmentSelector = $row['fragment_selector'];
                if (is_int($row['edited'])) $this->edited = $row['edited'];
                if (!empty($row['image_root'])) $this->imageRoot = $row['image_root'];
                $this->decodeAnnotation();
            }
        } else {
            $this->id = 0;
        }
    }


    /********************************************************************/
    /*  GETTERS & SETTERS                                               */
    /********************************************************************/

    /** @return string URI of the IIIF canvas this annotation targets. */
    public function getCanvas(): string { return $this->canvas; }

    /** @return string Raw annotation JSON string. */
    public function getAnnotation(): string { return $this->annotation; }

    /** @return string Mirador-assigned UUID for this annotation. */
    public function getAnnotationID(): string { return $this->annotationId; }

    /** @return string Plain-text body extracted from the annotation. */
    public function getAnnotationText(): string { return $this->annotationText; }

    /** @return string URI of the parent IIIF manifest. */
    public function getManifest(): string { return $this->manifest; }

    /** @return string IIIF fragment selector value (xywh= format). */
    public function getFragmentSelector(): string { return $this->fragmentSelector; }

    /** @return int Edited/dirty flag (1 = modified since last sync, 0 = clean). */
    public function getEdited(): int { return $this->edited; }

    /** @return int Primary key of the annotation author in user_details. */
    public function getUserID(): int { return $this->uid; }

    /** @return string IIIF Image API URL for a 200×200 canvas thumbnail. */
    public function getThumbnailURL(): string { return $this->thumbnailURL; }

    /** @return string IIIF Image API URL for an 80×80 canvas thumbnail. */
    public function getSmallThumbnailURL(): string { return $this->smallThumbnailURL; }

    /** @return string IIIF Image API URL cropped to the annotated fragment at 200×200. */
    public function getFragmentURL(): string { return $this->fragmentURL; }

    /** @param string $s URI of the target IIIF canvas. */
    public function setCanvas(string $s) { $this->canvas = $s; }

    /** @param string $s Raw annotation JSON string. */
    public function setAnnotation(string $s) { $this->annotation = $s; }

    /** @param string $s Mirador-assigned UUID for the annotation. */
    public function setAnnotationID(string $s) { $this->annotationId = $s; }

    /** @param string $s URI of the parent IIIF manifest. */
    public function setManifest(string $s) { $this->manifest = $s; }

    /** @param string $s IIIF fragment selector value (xywh= format). */
    public function setFragmentSelector(string $s) { $this->fragmentSelector = $s; }

    /** @param int $s Edited/dirty flag value (0 or 1). */
    public function setEdited(int $s) { $this->edited = $s; }

    /** @return string Display name of the annotation author. */
    public function getUserName(): string { return $this->username; }

    /** @param string $u Display name of the annotation author. */
    public function setUserName(string $u) { $this->username = $u; }


    /********************************************************************/
    /*  PERMISSION CHECKS                                               */
    /********************************************************************/

    /**
     * Determine whether a user may edit this annotation.
     *
     * Administrators may edit any annotation; regular users may only edit
     * annotations they own.
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
     * Determine whether a user may create annotations.
     *
     * Only administrators are permitted to create annotations.
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
     * Determine whether a user may delete this annotation.
     *
     * Administrators may delete any annotation; regular users may only delete
     * annotations they own.
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
     * Determine whether a user may view this annotation.
     *
     * All annotations are publicly viewable regardless of the requesting user.
     *
     * @param int $id user_details_id of the user to check.
     * @return bool Always true.
     */
    public function canView(int $id): bool
    {
        return true;
    }


    /********************************************************************/
    /*  CREATION FROM JSON                                              */
    /********************************************************************/

    /**
     * Populate this annotation from a JSON payload received from the client.
     *
     * Expects a JSON object with the structure:
     * <pre>
     * {
     *   "annotation": {
     *     "canvas":  "<canvas URI>",
     *     "data":    "<annotation JSON>",
     *     "uuid":    "<annotation UUID>",
     *     "creator": "<session ASCII ID>"
     *   }
     * }
     * </pre>
     *
     * The creator field is a session ID used to look up the user's display name.
     * After populating properties, decodeAnnotation() is called to derive
     * manifest, fragment selector, and image URL fields.
     *
     * @param string $json JSON payload from the client.
     * @return void
     */
    public function createFromJSON(string $json)
    {
        $arr = json_decode($json, true);
        $this->setCanvas($arr['annotation']['canvas']);
        $this->setAnnotation($arr['annotation']['data']);
        $this->setAnnotationID($arr['annotation']['uuid']);
        $name = $this->objDB->preparedGetOne(
            "select name from user_details where id=(select user_id from user_session where ascii_session_id = ?)",
            array($arr['annotation']['creator'])
        );
        $this->setUserName($name);
        $this->decodeAnnotation();
    }


    /********************************************************************/
    /*  IIIF MANIFEST METHODS                                           */
    /********************************************************************/

    /**
     * Retrieve and return the raw content of this annotation's parent IIIF manifest.
     *
     * Note: The code below the initial return statement is currently unreachable
     * dead code left from an earlier implementation that injected an annotation
     * page reference into the manifest before returning it. It is retained for
     * reference but has no effect.
     *
     * @return string Raw JSON string of the IIIF manifest.
     */
    public function createAnnotationManifest(): string
    {
        return file_get_contents(str_replace('https://', 'http://', $this->manifest));
        // Dead code – retained for historical reference:
        /**$json = json_decode(file_get_contents($this->manifest), true);
        if (isset($json['items'])) {
            $annotations = array();
            $serverURI = Config::$ANNOTATION_ENDPOINT . "/pages?uri=" . $this->canvas . "&&uid=" . $this->uid;
            $annotations['id'] = $serverURI;
            $annotations['type'] = "AnnotationPage";
            if (isset($json['items'][0]['annotations'])) $json['items'][0]['annotations'][] = $annotations;
            else $json['items']['annotations'] = array($annotations);
        }
        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);**/
    }

    /**
     * Build and return a minimal IIIF Presentation API v3 AnnotationPage manifest
     * containing only this annotation.
     *
     * The returned JSON object has type "AnnotationPage" and a single item
     * produced by getJSONAnnotationPage().
     *
     * @return string Pretty-printed JSON string of the AnnotationPage.
     */
    public function createManifest(): string
    {
        $json = array();
        $json['@context'] = 'http://iiif.io/api/presentation/3/context.json';
        $json['id'] = "";
        $json['type'] = "AnnotationPage";
        $json['label'] = array(array("en" => "AnnotationPage created by Publink"));
        $json['items'] = array($this->getJSONAnnotationPage());

        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Populate this annotation's properties from a full database row.
     *
     * Expects a row containing all image_annotation columns plus a `username`
     * field joined from user_details. Calls decodeAnnotation() to derive
     * image URLs and selectors from the stored JSON, exactly as the constructor
     * does — but without issuing any additional database queries.
     *
     * @param array $row Associative array of column values (+ username from JOIN).
     * @return void
     */
    private function hydrateFromRow(array $row): void
    {
        $this->id               = (int)$row['id'];
        $this->uid              = (int)$row['user_details_id'];
        $this->username         = $row['username'] ?? '';
        $this->canvas           = $row['canvas'];
        $this->annotation       = $row['annotation'];
        $this->annotationId     = $row['annotation_id'];
        $this->manifest         = $row['manifest'];
        $this->fragmentSelector = $row['fragment_selector'];
        $this->edited           = (int)($row['edited'] ?? 0);
        if (!empty($row['image_root'])) {
            $this->imageRoot = $row['image_root'];
        }
        $this->decodeAnnotation();
    }

    /**
     * Build and return a IIIF Presentation API v3 AnnotationPage containing all
     * annotations from a PDOStatement result set.
     *
     * Each row is hydrated directly into an ImageAnnotation without issuing
     * additional database queries (no N+1). The statement must contain full
     * image_annotation columns plus a `username` field (see getAnnotationListForUser).
     *
     * @param PDOStatement $annotations Result set of full annotation rows from the DB.
     * @param PDODatabase  $objDB       Active database connection.
     * @return string Pretty-printed JSON string of the AnnotationPage.
     */
    public static function createCanvasAnnotationList(PDOStatement $annotations, PDODatabase $objDB): string
    {
        $json = array();
        $json['@context'] = 'http://iiif.io/api/presentation/3/context.json';
        $json['id'] = "https://address_goes_here";
        $json['type'] = "AnnotationPage";
        $json['label'] = array(array("en" => "AnnotationPage created by Publink"));

        $annArr = array();
        while ($row = $annotations->fetch()) {
            $ann = new ImageAnnotation($objDB, 0);
            $ann->hydrateFromRow($row);
            array_push($annArr, $ann->getJSONAnnotationPage());
        }
        $json['items'] = $annArr;

        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Serialise this annotation to a IIIF Presentation API v3 Annotation array.
     *
     * Constructs the full annotation structure including:
     * - TextualBody with the plain-text annotation text.
     * - SpecificResource target pointing to the canvas, including a
     *   FragmentSelector (xywh) and, if present, an SvgSelector with a
     *   cleaned and coordinate-corrected SVG path.
     *
     * @return array IIIF v3 Annotation associative array ready for JSON encoding.
     */
    public function getJSONAnnotationPage(): array
    {
        $items = array();
        $items['id'] = "http://" . $this->getAnnotationID();
        $items['type'] = 'Annotation';
        $items['motivation'] = "commenting";

        $ann = array();
        $ann["type"] = "TextualBody";
        $ann["language"] = "en";
        $ann["format"] = "text/plain";
        $ann["value"] = $this->annotationText;
        $items['body'] = $ann;

        $partof = array("id" => $this->getManifest(), "type" => "Manifest");
        $selector = array();

        $svg = $this->svgSelector;
        if (!empty($svg)) {
            $svg = $this->cleanSvgPath($svg);
            $selector[] = array("type" => "SvgSelector", "value" => $svg);
            error_log("stripped svg selector $svg");
        }
        $selector[] = array("type" => "FragmentSelector", "value" => $this->getFragmentSelector());

        $target = array();
        $target['type'] = "SpecificResource";
        $target["source"] = array("id" => $this->getCanvas(), "type" => "Canvas", "partOf" => array($partof));
        $target['selector'] = $selector;
        $items['target'] = $target;

        return $items;
    }


    /********************************************************************/
    /*  SVG PATH HELPERS                                                */
    /********************************************************************/

    /**
     * Clean and normalise an SVG path string received from Mirador.
     *
     * Processing steps:
     * 1. Strip PHP magic-quote slashes.
     * 2. Remove all non-essential SVG attributes, keeping only `d` and `xmlns`.
     * 3. Extract the path data, run it through SVGPathFixer to convert any
     *    relative coordinates to absolute, then write the corrected data back.
     *
     * @param string $svgString Raw SVG string from the annotation.
     * @return string Cleaned SVG string with corrected path coordinates.
     */
    private function cleanSvgPath(string $svgString): string
    {
        // Step 1: Strip escape slashes added by PHP magic quotes / JSON encoding.
        $cleaned = stripslashes($svgString);

        // Step 2: Remove all SVG attributes except `d` and `xmlns` to avoid
        //         browser rendering conflicts with Mirador's presentation layer.
        $pattern = '/\s+(?!(?:d|xmlns)\s*=)[a-zA-Z][a-zA-Z0-9-]*\s*=\s*["\'][^"\']*["\']/';
        $cleaned = preg_replace($pattern, '', $cleaned);

        // Step 3: Extract the raw path data, fix coordinates, and re-insert.
        $svgCoords = $this->extractSVGPath($cleaned);
        $fixer = new SVGPathFixer();
        $coords = $fixer->fixPath($svgCoords);
        $cleaned = $this->replaceSVGPath($cleaned, $coords);

        return $cleaned;
    }

    /**
     * Extract the path data string from an SVG element or return it as-is if
     * it is already raw path data.
     *
     * Handles two forms:
     * - A bare path data string beginning with an SVG command letter (M, L, etc.).
     * - A full `<path d="...">` element from which the `d` attribute is extracted.
     *
     * @param string $svgString Full SVG element string or bare path data.
     * @return string The extracted path data string, or an empty string on failure.
     */
    private function extractSVGPath(string $svgString): string
    {
        // Already bare path data — return directly.
        if (preg_match('/^[MmLlHhVvCcSsQqTtAaZz]/', trim($svgString))) {
            return trim($svgString);
        }

        // Extract the value of the d= attribute from a full SVG element.
        if (preg_match('/d=[\"\']([^\"\']+)[\"\']/', $svgString, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Replace the path data in an SVG element string with new path data.
     *
     * Substitutes the value of the `d` attribute in the given SVG string with
     * $newPathData, preserving the rest of the element markup unchanged.
     *
     * @param string $svgString   The original SVG element string.
     * @param string $newPathData The replacement path data to insert.
     * @return string SVG string with the `d` attribute value replaced.
     */
    private function replaceSVGPath(string $svgString, string $newPathData): string
    {
        return preg_replace_callback(
            '/d=[\"\']([^\"\']+)[\"\']/',
            function () use ($newPathData) { return 'd="' . $newPathData . '"'; },
            $svgString
        );
    }


    /********************************************************************/
    /*  INTERNAL DECODE                                                 */
    /********************************************************************/

    /**
     * Decode the raw annotation JSON and populate derived instance properties.
     *
     * Extracts and sets:
     * - annotationText  — plain-text body value.
     * - manifest        — parent manifest URI (if not already set).
     * - fragmentSelector — xywh region string (if not already set from the DB row).
     * - svgSelector     — SVG path value from the second selector entry, if present.
     *
     * Then, if a manifest and canvas are available, fetches the IIIF Image API
     * service root URL and constructs thumbnailURL, smallThumbnailURL, and
     * fragmentURL for use in the UI.
     *
     * @return void
     */
    private function decodeAnnotation()
    {
        $annotation = json_decode($this->getAnnotation(), true);

        $this->annotationText = isset($annotation['body']['value'])
            ? trim(strip_tags($annotation['body']['value'])) : "";

        // Only overwrite manifest if the JSON contains one — preserve the DB value otherwise.
        if (isset($annotation['target']['source']['partOf']['id'])) {
            $this->manifest = $annotation['target']['source']['partOf']['id'];
        }

        // Only overwrite fragmentSelector if it was not already loaded from the DB row.
        if (empty($this->fragmentSelector))
            $this->fragmentSelector = isset($annotation['target']['selector'][0]['value'])
                ? $annotation['target']['selector'][0]['value'] : "";

        $this->svgSelector = isset($annotation['target']['selector'][1]['value'])
            ? $this->svgSelector = $annotation['target']['selector'][1]['value'] : "";

        // Build derivative image URLs from the stored image root if available.
        // If image_root is not yet stored, call resolveImageUrls() explicitly.
        if (!empty($this->imageRoot)) {
            $this->buildImageUrls();
        }
    }

    /**
     * Fetch the IIIF Image API service root from the manifest and build image URLs.
     *
     * This is the only method that makes an outbound HTTP request. Call it
     * explicitly when image URLs are needed and image_root is not yet stored.
     * Persisting image_root via updateImageAnnotation() afterwards avoids
     * repeated fetches on future loads.
     *
     * @return void
     */
    public function resolveImageUrls(): void
    {
        if (empty($this->manifest) || !empty($this->imageRoot)) {
            return;
        }

        $serviceUrls = self::getServiceUrls($this->manifest, $this->canvas);
        if (isset($serviceUrls[0])) {
            $this->imageRoot = $serviceUrls[0];
            $this->buildImageUrls();
        }
    }

    /**
     * Construct thumbnail and fragment image URLs from the stored image root.
     *
     * @return void
     */
    private function buildImageUrls(): void
    {
        $this->thumbnailURL      = $this->imageRoot . "/full/!200,200/0/default.jpg";
        $this->smallThumbnailURL = $this->imageRoot . "/full/!80,80/0/default.jpg";
        $coords = str_replace("xywh=", "", $this->fragmentSelector);
        $this->fragmentURL       = $this->imageRoot . "/$coords/!200,200/0/default.jpg";
    }


    /********************************************************************/
    /*  IIIF SERVICE URL RESOLUTION                                     */
    /********************************************************************/

    /**
     * Fetch a IIIF manifest and return the Image API service root URL(s) for a
     * specific canvas.
     *
     * Supports both IIIF Presentation API v2 (sequences/canvases) and v3
     * (items/items) manifest structures. Matches canvases by URI against
     * $mycanvas and collects any Image API service `id` / `@id` values found.
     *
     * @param string $manifestfile URL of the IIIF manifest JSON file.
     * @param string $mycanvas     Canvas URI to match within the manifest.
     * @return array Deduplicated array of Image API service root URL strings.
     */
    public static function getServiceUrls(string $manifestfile, string $mycanvas): array
    {
        $services = [];

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'ignore_errors' => false
            ]
        ]);

        // Rewrite to HTTP as a workaround for SSL verification issues on this network (see ImageCanvas.php:547).
        $manifest = file_get_contents(str_replace('https://', 'http://', $manifestfile), false, $context);

        if ($manifest === false) {
            $error = error_get_last();
            error_log("Error fetching manifest: " . $error['message']);
            $responseHeaders = function_exists('http_get_last_response_headers')
                ? http_get_last_response_headers()
                : (isset($http_response_header) ? $http_response_header : []);
            if (!empty($responseHeaders)) {
                error_log("HTTP Response: " . $responseHeaders[0]);
            }
        }

        $manifest = json_decode($manifest, true);

        // IIIF Presentation API v2 — sequences → canvases → images
        if (isset($manifest['sequences'])) {
            foreach ($manifest['sequences'] as $sequence) {
                if (isset($sequence['canvases'])) {
                    foreach ($sequence['canvases'] as $canvas) {
                        if (isset($canvas['images'])) {
                            foreach ($canvas['images'] as $image) {
                                if (isset($image['resource']['service']['@id'])) {
                                    if (!strcmp($mycanvas, $canvas['@id']))
                                        $services[] = $image['resource']['service']['@id'];
                                }
                            }
                        }
                    }
                }
            }
        }

        // IIIF Presentation API v3 — items (canvases) → items (pages) → items (annotations)
        if (isset($manifest['items'])) {
            foreach ($manifest['items'] as $canvas) {
                if (isset($canvas['items'])) {
                    foreach ($canvas['items'] as $page) {
                        if (isset($page['items'])) {
                            foreach ($page['items'] as $annotation) {
                                if (isset($annotation['body']['service'])) {
                                    $service = $annotation['body']['service'];
                                    // Normalise both single-object and array-of-services forms.
                                    $serviceList = is_array($service) && isset($service[0])
                                        ? $service : [$service];
                                    foreach ($serviceList as $svc) {
                                        if (!strcmp($mycanvas, $canvas['id'])) {
                                            $serviceId = $svc['id'] ?? $svc['@id'] ?? null;
                                            if ($serviceId) {
                                                error_log("Got Service ID :: $serviceId");
                                                $services[] = $serviceId;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return array_unique($services);
    }


    /********************************************************************/
    /*  CONTENT STATE / DEEP LINK                                       */
    /********************************************************************/

    /**
     * Generate a IIIF Content State "Specific Region Recipe" for this annotation.
     *
     * Builds a IIIF v3 Annotation with `motivation: contentState` targeting the
     * annotated canvas fragment, encodes it as Base64, and appends a deep-link
     * URL for the Theseus viewer. The returned string contains both the raw JSON
     * and a clickable HTML anchor.
     *
     * @return string HTML string containing the JSON recipe followed by a Theseus viewer link.
     */
    public function getSpecificRegionRecipe(): string
    {
        $json = [
            '@context'   => 'http://iiif.io/api/presentation/3/context.json',
            'id'         => $this->getAnnotationID() . '/specific_region_recipe',
            'type'       => 'Annotation',
            'motivation' => ['contentState'],
            'target'     => [
                'id'     => $this->canvas . '#' . $this->fragmentSelector,
                'type'   => 'Canvas',
                'partOf' => [[
                    'id'   => $this->manifest,
                    'type' => "Manifest"
                ]]
            ]
        ];
        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function getSpecificRegionURL(): string
    {
        $encoded = base64_encode($this->getSpecificRegionRecipe());
        return "https://theseusviewer.org?iiif-content=$encoded";
    }


    /********************************************************************/
    /*  DATABASE METHODS                                                */
    /********************************************************************/

    /**
     * Persist this annotation to the database (insert or update).
     *
     * Performs an INSERT when $this->id is 0 (new record) or an UPDATE when
     * $this->id is a positive integer (existing record). The operation is
     * skipped silently if the manifest URI is empty, as a valid manifest is
     * required for a meaningful annotation record.
     *
     * @return void
     */
    public function updateImageAnnotation()
    {
        if (empty($this->imageRoot)) {
            $this->resolveImageUrls();
        }

        $vals = array();
        $vals['canvas']           = $this->getCanvas();
        $vals['annotation']       = $this->getAnnotation();
        $vals['annotation_id']    = $this->getAnnotationID();
        $vals['user_details_id']  = User::userExists($this->getUserName());
        $vals['manifest']         = $this->getManifest();
        $vals['fragment_selector'] = $this->getFragmentSelector();
        $vals['edited']           = $this->getEdited();
        $vals['image_root']       = $this->imageRoot;

        if (!empty($vals['manifest'])) {
            if ($this->id == 0) {
                 $vals['created'] = time();
                $this->objDB->insert($this->tableName, $vals); 
            }
            else $this->objDB->update($this->tableName, $vals, "id=" . $this->getID());
        }
    }

    /**
     * Delete this annotation and clean up related canvas-sharing records.
     *
     * Deletion logic:
     * 1. If this is the user's only annotation on the canvas, the canvas-sharing
     *    record in `user_details_canvas_annotation` is also removed.
     * 2. After deleting the annotation row, the `edited` flag is set to 1 on
     *    another annotation targeting the same canvas (owned by this user or
     *    shared with them via an owner) so that downstream sync services are
     *    notified of the change.
     *
     * @param int $uid user_details_id of the user requesting deletion. Must match
     *                 the annotation owner to satisfy the DELETE WHERE clause.
     * @return void
     */
    public function deleteImageAnnotation(int $uid)
    {
        $canvasAnnotations = $this->objDB->preparedGetOne(
            "select count(*) from image_annotation where canvas = ? and user_details_id = ?",
            array($this->canvas, $uid)
        );

        // Remove the canvas-sharing entry if this was the last annotation on this canvas.
        if ($canvasAnnotations <= 1)
            $this->objDB->preparedSelect(
                "delete from user_details_canvas_annotation where owner_id = ? and canvas = ?",
                array($uid, $this->canvas)
            );

        $this->objDB->preparedSelect(
            "delete from image_annotation where user_details_id = ? and id = ?",
            array($uid, $this->id)
        );

        // Signal downstream sync by marking another canvas annotation as edited.
        if ($canvasAnnotations > 1) {
            $id = $this->objDB->preparedGetOne(
                "select id from image_annotation where canvas = ? and user_details_id = ? limit 1",
                array($this->canvas, $uid)
            );
        } else {
            $owner = $this->objDB->preparedGetOne(
                "select owner_id from user_details_canvas_annotation where user_details_id = ? and canvas = ?",
                array($uid, $this->canvas)
            );
            $id = $this->objDB->preparedGetOne(
                "select id from image_annotation where canvas = ? and user_details_id = ? limit 1",
                array($this->canvas, $owner)
            );
        }

        if (is_numeric($id) && $id > 0) {
            $ann = new ImageAnnotation($this->objDB, $id);
            $ann->updateEdited(1);
        }
    }

    /**
     * Share this annotation with another user.
     *
     * Inserts a row into `user_details_image_annotation` linking the target user
     * to this annotation, provided the share does not already exist and the
     * target user is not the annotation's owner.
     *
     * @param int $uid user_details_id of the user to share with.
     * @return void
     */
    public function shareImageAnnotation(int $uid)
    {
        $vals = array();
        $vals['user_details_id'] = $uid;
        $vals['annotation_id']   = $this->getID();
        $exists = $this->objDB->preparedGetOne(
            "select id from user_details_image_annotation where user_details_id = ? and annotation_id = ?",
            array($uid,$this->getID())
        );
        $myID = User::userExists($this->getUserName());
        if (!$exists && $uid != $myID)
            $this->objDB->insert("user_details_image_annotation", $vals);
    }

    /**
     * Set the edited/dirty flag on this annotation in the database.
     *
     * Used to signal to downstream sync services that this annotation has changed.
     * Pass 1 to mark as dirty, 0 to mark as clean.
     *
     * @param int $flag The value to write to the `edited` column (0 or 1).
     * @return void
     */
    public function updateEdited(int $flag)
    {
        $vals = array();
        $vals['edited'] = $flag;
        $this->objDB->update("image_annotation", $vals, "id=" . $this->id);
    }


    /********************************************************************/
    /*  STATIC QUERY METHODS                                            */
    /********************************************************************/

    /**
     * Resolve the database primary key for an annotation from a JSON payload.
     *
     * Looks up the annotation in the database by matching the Mirador UUID,
     * canvas URI, and user ID derived from the session creator identifier in
     * the JSON.
     *
     * @param string      $json   JSON object with keys: uuid, canvas, creator (session ID).
     * @param PDODatabase $objDB  Active database connection.
     * @return int Primary key of the matching annotation row, or 0 if not found.
     */
    public static function getIDFromJSON(string $json, PDODatabase $objDB): int
    {
        $arr = json_decode($json, true);
        $aid = $arr['uuid'];
        $cid = $arr['canvas'];
        $uid = User::userExists($arr['creator']);

        $annId = 0;
        if (isset($aid))
            $annId = $objDB->preparedGetOne(
                "select id from image_annotation where user_details_id=? and canvas=? and annotation_id=?",
                array($uid, $cid, $aid)
            );
        return $annId;
    }

    /**
     * Retrieve all annotations visible to a user, grouped by canvas.
     *
     * Returns the full annotation row (plus the author's display name) for the
     * highest-ID annotation per canvas. Includes both annotations owned by the
     * user and annotations on canvases shared via `user_details_canvas_annotation`.
     *
     * @param int         $uid   user_details_id of the requesting user.
     * @param PDODatabase $objDB Active database connection.
     * @return PDOStatement Full annotation rows + ud.name aliased as username.
     */
    public static function getAnnotationListForUser(int $uid, PDODatabase $objDB): PDOStatement
    {
        return $objDB->preparedSelect(
            "SELECT ia.*, ud.name AS username
             FROM image_annotation ia
             JOIN user_details ud ON ia.user_details_id = ud.id
             WHERE ia.id IN (
                 SELECT MAX(id)
                 FROM image_annotation
                 WHERE (user_details_id = ?
                        OR canvas IN (
                            SELECT canvas FROM user_details_canvas_annotation WHERE user_details_id = ?
                        ))
                 GROUP BY canvas
             )",
            array($uid, $uid)
        );
    }

    /**
     * Return the distinct list of manifest URIs for all annotations owned by a user.
     *
     * @param int         $uid   user_details_id of the annotation owner.
     * @param PDODatabase $objDB Active database connection.
     * @return array Flat array of unique manifest URI strings.
     */
    public static function getManifestListForUser(int $uid, PDODatabase $objDB): array
    {
        $manifests = $objDB->preparedSelect(
            "select distinct(manifest) from image_annotation where user_details_id = ?",
            array($uid)
        );
        $mans = array();
        while ($man = $manifests->fetch()) {
            $mans[] = $man['manifest'];
        }
        return $mans;
    }

    /**
     * Return the distinct list of manifest URIs for canvases shared with a user.
     *
     * Looks up canvases in `user_details_canvas_annotation` where the user appears
     * as a sharee, then returns the unique manifests associated with those canvases.
     *
     * @param int         $uid   user_details_id of the sharee.
     * @param PDODatabase $objDB Active database connection.
     * @return array Flat array of unique manifest URI strings.
     */
    public static function getSharedManifestListForUser(int $uid, PDODatabase $objDB): array
    {
        $manifests = $objDB->preparedSelect(
            "select distinct(manifest) from image_annotation 
             where canvas in 
             (select canvas from user_details_canvas_annotation where user_details_id = ?)",
            array($uid)
        );
        $mans = array();
        while ($man = $manifests->fetch()) {
            $mans[] = $man['manifest'];
        }
        return $mans;
    }

    /**
     * Return all users who have NOT yet been shared a given canvas.
     *
     * Used to populate the "share with" dropdown in the UI. Excludes the canvas
     * owner ($uid) and any users who already have a share record for this canvas.
     *
     * @param int         $uid    user_details_id of the canvas owner.
     * @param string      $canvas Canvas URI.
     * @param PDODatabase $objDB  Active database connection.
     * @return array Numerically indexed array of [user_id, "First Last"] pairs.
     */
    public static function getShareListForCanvas(int $uid, string $canvas, PDODatabase $objDB): array
    {
        $users = $objDB->preparedSelect(
            "select distinct(id), first_name, last_name from user_details where 
             id <> ? 
             and id not in (select user_details_id from user_details_canvas_annotation where canvas = ?)",
            array($uid, $canvas)
        );
        $userIds = array();
        $c = 0;
        while ($user = $users->fetch()) {
            $userIds[$c][0] = $user['id'];
            $userIds[$c][1] = $user['first_name'] . " " . $user['last_name'];
            $c++;
        }
        return $userIds;
    }

    /**
     * Return all users a given canvas is currently shared with.
     *
     * Looks up users in `user_details_canvas_annotation` where the owner is $uid
     * and the canvas matches.
     *
     * @param int         $uid    user_details_id of the canvas owner.
     * @param string      $canvas Canvas URI.
     * @param PDODatabase $objDB  Active database connection.
     * @return array Numerically indexed array of [user_id, "First Last"] pairs.
     */
    public static function getSharersForCanvas(int $uid, string $canvas, PDODatabase $objDB): array
    {
        $users = $objDB->preparedSelect(
            "select * from user_details where id in
             (select user_details_id from user_details_canvas_annotation where owner_id=? and canvas = ?)",
            array($uid, $canvas)
        );
        $userIds = array();
        $c = 0;
        while ($user = $users->fetch()) {
            $userIds[$c][0] = $user['id'];
            $userIds[$c][1] = $user['first_name'] . " " . $user['last_name'];
            $c++;
        }
        return $userIds;
    }

    /**
     * Share a canvas with another user by inserting a sharing record.
     *
     * Inserts a row into `user_details_canvas_annotation` linking $sharer as the
     * owner and $uid as the sharee. The operation is a no-op if a matching record
     * already exists.
     *
     * @param int         $uid    user_details_id of the user receiving access.
     * @param int         $sharer user_details_id of the canvas owner granting access.
     * @param string      $canvas Canvas URI to share.
     * @param PDODatabase $objDB  Active database connection.
     * @return void
     */
    public static function shareCanvas(int $uid, int $sharer, string $canvas, PDODatabase $objDB)
    {
        $exists = $objDB->preparedGetOne(
            "select id from user_details_canvas_annotation where 
             canvas = ?
             and user_details_id = ?
             and owner_id = ?",
            array($canvas, $uid, $sharer)
        );

        if ($exists > 0) return;

        $vals = array();
        $vals['canvas']          = $canvas;
        $vals['user_details_id'] = $uid;
        $vals['owner_id']        = $sharer;

        $objDB->insert("user_details_canvas_annotation", $vals);
        return;
    }

    /**
     * Revoke a canvas share with a specific user.
     *
     * Deletes the matching row from `user_details_canvas_annotation`.
     *
     * @param int         $uid    user_details_id of the sharee whose access is being revoked.
     * @param int         $sharer user_details_id of the canvas owner.
     * @param string      $canvas Canvas URI.
     * @param PDODatabase $objDB  Active database connection.
     * @return void
     */
    public static function deleteSharer(int $uid, int $sharer, string $canvas, PDODatabase $objDB)
    {
        $objDB->preparedStatement(
            "delete from user_details_canvas_annotation where 
             canvas = ?
             and user_details_id = ?
             and owner_id = ?",
            array($canvas, $uid, $sharer)
        );
        return;
    }


    /********************************************************************/
    /*  MANIFEST CONSTRUCTION                                           */
    /********************************************************************/

    /**
     * Build a complete IIIF Presentation API v3 Manifest embedding a linked
     * external annotation page.
     *
     * Creates a single-canvas manifest with the supplied image as the painting
     * annotation and appends the raw annotation JSON from this instance to the
     * canvas's `annotations` array.
     *
     * Note: The canvas dimensions (2000×1500) are hardcoded and should ideally be
     * derived from the actual image dimensions.
     *
     * @param string $manifestId                 URI to assign as the manifest `id`.
     * @param string $canvasId                   URI to assign as the canvas `id`.
     * @param string $imageUrl                   URL of the image resource to paint onto the canvas.
     * @param string $externalAnnotationPageUrl  URI of the external AnnotationPage (currently unused
     *                                           in the array construction; reserved for future use).
     * @return array IIIF v3 Manifest as a PHP associative array.
     */
    public function getNewManifest(
        string $manifestId,
        string $canvasId,
        string $imageUrl,
        string $externalAnnotationPageUrl
    ): array {
        $annotation = json_decode($this->getAnnotation(), true);
        $manifest = [
            '@context' => [
                'http://www.w3.org/ns/anno.jsonld',
                'http://iiif.io/api/presentation/3/context.json'
            ],
            'id'    => $manifestId,
            'type'  => 'Manifest',
            'label' => [
                'en' => ['New Manifest with Linked Annotations']
            ],
            'items' => [
                [
                    'id'    => $canvasId,
                    'type'  => 'Canvas',
                    'label' => [
                        'en' => ['Canvas with External Annotations']
                    ],
                    'height' => 2000,
                    'width'  => 1500,
                    'items'  => [
                        [
                            'id'    => $canvasId . '/page/painting',
                            'type'  => 'AnnotationPage',
                            'items' => [
                                [
                                    'id'         => $canvasId . '/annotation/image',
                                    'type'       => 'Annotation',
                                    'motivation' => 'painting',
                                    'body'       => [
                                        'id'     => $imageUrl,
                                        'type'   => 'Image',
                                        'format' => 'image/jpeg',
                                        'height' => 2000,
                                        'width'  => 1500
                                    ],
                                    'target' => $canvasId
                                ]
                            ]
                        ]
                    ],
                    'annotations' => [
                        $annotation
                    ]
                ]
            ]
        ];

        return $manifest;
    }
}
?>