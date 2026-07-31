<?php
namespace Biblhertz\Publink\annotation;

/**
 * SVGPathFixer
 *
 * Parses, normalises, and repairs SVG path data for use in IIIF annotations.
 *
 * Mirador and other IIIF viewers can produce SVG paths with relative coordinates
 * or malformed command sequences that break when embedded in an AnnotationPage.
 * This class converts any SVG path to absolute coordinates and optionally
 * simplifies curved paths to polygon approximations.
 *
 * Three fix strategies are available via fixPath():
 *
 * | Method      | Description                                                        |
 * |-------------|--------------------------------------------------------------------|
 * | normalize   | Convert all commands to absolute coordinates (default, lossless)  |
 * | simplify    | Approximate curves as sampled line segments (polygon output)       |
 * | extract     | Keep only endpoint coordinates, discard curve control points       |
 *
 * Supported SVG path commands: M, L, H, V, C, Q, A, Z (both upper and lower case).
 *
 * @package Biblhertz\Publink\annotation
 */
class SVGPathFixer
{

    /********************************************************************/
    /*  INSTANCE VARIABLES                                              */
    /********************************************************************/

    /**
     * @var float X coordinate of the current pen position.
     *            Updated as each command is processed during path parsing.
     */
    private $currentX = 0;

    /**
     * @var float Y coordinate of the current pen position.
     *            Updated as each command is processed during path parsing.
     */
    private $currentY = 0;

    /**
     * @var float X coordinate of the most recent M (moveto) command.
     *            Used to restore pen position when a Z (closepath) command is encountered.
     */
    private $startX = 0;

    /**
     * @var float Y coordinate of the most recent M (moveto) command.
     *            Used to restore pen position when a Z (closepath) command is encountered.
     */
    private $startY = 0;


    /********************************************************************/
    /*  PUBLIC API                                                      */
    /********************************************************************/

    /**
     * Fix SVG path data using the specified strategy.
     *
     * Parses $pathData into absolute-coordinate commands via parseToAbsolute(),
     * then applies the chosen method to produce the output string. On any
     * exception the original path data is returned unchanged.
     *
     * @param string $pathData Raw SVG path data string (the value of a `d` attribute).
     * @param string $method   Fix strategy: 'normalize' (default), 'simplify', or 'extract'.
     * @return string Fixed SVG path string, or the original $pathData on failure.
     */
    public function fixPath(string $pathData, string $method = 'normalize'): string
    {
        try {
            switch ($method) {
                case 'normalize':
                    // Convert all commands to absolute coordinates, preserving curve types.
                    $commands = $this->parseToAbsolute($pathData);
                    return $this->commandsToPath($commands);

                case 'simplify':
                    // Convert to absolute, then approximate all curves as sampled line segments.
                    $commands = $this->parseToAbsolute($pathData);
                    $points   = $this->simplifyToPolygon($commands);
                    return $this->createPolygonPath($points);

                case 'extract':
                    // Convert to absolute, then keep only endpoint coordinates (discard control points).
                    $commands = $this->parseToAbsolute($pathData);
                    $points   = $this->extractPolygonPoints($commands);
                    return $this->createPolygonPath($points);

                default:
                    return $pathData;
            }
        } catch (\Exception $e) {
            return $pathData;
        }
    }

    /**
     * Parse an SVG path string and return all commands converted to absolute coordinates.
     *
     * Resets the internal pen position to (0,0) before parsing. Each token
     * produced by tokenizePath() is passed to convertToAbsolute(); null results
     * (unrecognised or malformed commands) are silently skipped.
     *
     * @param string $pathData Raw SVG path data string.
     * @return array Array of absolute command arrays, each with keys:
     *               'command' (string) and 'coords' (float[]).
     */
    private function parseToAbsolute(string $pathData): array
    {
        $commands = $this->tokenizePath($pathData);
        $absoluteCommands = [];

        // Reset pen position for a fresh parse.
        $this->currentX = 0;
        $this->currentY = 0;
        $this->startX   = 0;
        $this->startY   = 0;

        foreach ($commands as $cmd) {
            $absCmd = $this->convertToAbsolute($cmd);
            if ($absCmd !== null) {
                $absoluteCommands[] = $absCmd;
            }
        }

        return $absoluteCommands;
    }


    /********************************************************************/
    /*  PARSING & TOKENISING                                            */
    /********************************************************************/

    /**
     * Tokenise an SVG path string into an array of command/coordinate pairs.
     *
     * Uses a regex to split the path on SVG command letters, then parses the
     * coordinate string that follows each letter. Coordinates may be separated
     * by spaces, commas, or a combination. Non-numeric tokens are discarded.
     *
     * Known limitation: The regex pattern `[\d\s,.-]*` does not handle
     * negative numbers immediately following another number (e.g. "10-20")
     * without a separator, which is valid SVG but will be tokenised incorrectly.
     *
     * @param string $pathData Raw SVG path data string.
     * @return array Array of token arrays, each with keys:
     *               'command' (string) and 'coords' (float[]).
     */
    private function tokenizePath(string $pathData): array
    {
        $tokens = [];

        // Match each SVG command letter followed by its optional coordinate string.
        preg_match_all('/([MmLlHhVvCcSsQqTtAaZz])([\d\s,.-]*)/', $pathData, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $command  = $match[1];
            $coordStr = trim($match[2]);

            $coords = [];
            if (!empty($coordStr)) {
                // Split on one or more whitespace or comma characters, then cast to float.
                $coords = preg_split('/[\s,]+/', $coordStr);
                $coords = array_map('floatval', array_filter($coords, 'is_numeric'));
            }

            $tokens[] = [
                'command' => $command,
                'coords'  => $coords
            ];
        }

        return $tokens;
    }

    /**
     * Convert a single SVG path command to its absolute-coordinate equivalent.
     *
     * Lower-case commands are relative (offsets from the current pen position);
     * upper-case commands are already absolute. After conversion, $this->currentX
     * and $this->currentY are updated to the new pen position.
     *
     * H and V are normalised to L commands (explicit X and Y) in the output.
     * S and T smooth-curve commands are not yet implemented and will return null.
     *
     * @param array $cmd Associative array with keys 'command' (string) and 'coords' (float[]).
     * @return array|null Absolute command array with keys 'command' and 'coords',
     *                    or null if the command is unsupported or has insufficient coordinates.
     */
    private function convertToAbsolute(array $cmd): ?array
    {
        $command    = $cmd['command'];
        $coords     = $cmd['coords'];
        $isRelative = ctype_lower($command);
        $absCommand = strtoupper($command);

        switch ($absCommand) {
            case 'M': // Move to — sets new pen position and records subpath start.
                if (count($coords) >= 2) {
                    $this->currentX = $isRelative ? $this->currentX + $coords[0] : $coords[0];
                    $this->currentY = $isRelative ? $this->currentY + $coords[1] : $coords[1];
                    $this->startX   = $this->currentX;
                    $this->startY   = $this->currentY;
                    return ['command' => 'M', 'coords' => [$this->currentX, $this->currentY]];
                }
                break;

            case 'L': // Line to — draws a straight line to the target point.
                if (count($coords) >= 2) {
                    $this->currentX = $isRelative ? $this->currentX + $coords[0] : $coords[0];
                    $this->currentY = $isRelative ? $this->currentY + $coords[1] : $coords[1];
                    return ['command' => 'L', 'coords' => [$this->currentX, $this->currentY]];
                }
                break;

            case 'H': // Horizontal line — normalised to an L command with explicit Y.
                if (count($coords) >= 1) {
                    $this->currentX = $isRelative ? $this->currentX + $coords[0] : $coords[0];
                    return ['command' => 'L', 'coords' => [$this->currentX, $this->currentY]];
                }
                break;

            case 'V': // Vertical line — normalised to an L command with explicit X.
                if (count($coords) >= 1) {
                    $this->currentY = $isRelative ? $this->currentY + $coords[0] : $coords[0];
                    return ['command' => 'L', 'coords' => [$this->currentX, $this->currentY]];
                }
                break;

            case 'C': // Cubic Bézier — requires 6 coordinates (x1,y1, x2,y2, x,y).
                if (count($coords) >= 6) {
                    $newCoords = $isRelative
                        ? [
                            $this->currentX + $coords[0], $this->currentY + $coords[1], // control 1
                            $this->currentX + $coords[2], $this->currentY + $coords[3], // control 2
                            $this->currentX + $coords[4], $this->currentY + $coords[5]  // endpoint
                          ]
                        : array_slice($coords, 0, 6);

                    $this->currentX = $newCoords[4];
                    $this->currentY = $newCoords[5];
                    return ['command' => 'C', 'coords' => $newCoords];
                }
                break;

            case 'Q': // Quadratic Bézier — requires 4 coordinates (x1,y1, x,y).
                if (count($coords) >= 4) {
                    $newCoords = $isRelative
                        ? [
                            $this->currentX + $coords[0], $this->currentY + $coords[1], // control
                            $this->currentX + $coords[2], $this->currentY + $coords[3]  // endpoint
                          ]
                        : array_slice($coords, 0, 4);

                    $this->currentX = $newCoords[2];
                    $this->currentY = $newCoords[3];
                    return ['command' => 'Q', 'coords' => $newCoords];
                }
                break;

            case 'A': // Elliptical arc — requires 7 parameters (rx,ry,x-rotation,large-arc,sweep,x,y).
                if (count($coords) >= 7) {
                    // The first 5 parameters (radii, rotation, flags) are not affected by relativity.
                    $newCoords   = array_slice($coords, 0, 5);
                    $newCoords[] = $isRelative ? $this->currentX + $coords[5] : $coords[5]; // endpoint X
                    $newCoords[] = $isRelative ? $this->currentY + $coords[6] : $coords[6]; // endpoint Y

                    $this->currentX = $newCoords[5];
                    $this->currentY = $newCoords[6];
                    return ['command' => 'A', 'coords' => $newCoords];
                }
                break;

            case 'Z': // Close path — returns pen to the start of the current subpath.
                $this->currentX = $this->startX;
                $this->currentY = $this->startY;
                return ['command' => 'Z', 'coords' => []];
        }

        return null;
    }


    /********************************************************************/
    /*  OUTPUT SERIALISATION                                            */
    /********************************************************************/

    /**
     * Serialise an array of absolute command arrays back to an SVG path string.
     *
     * Commands with no coordinates (i.e. Z) are output as a bare letter.
     * All others are output as the command letter followed by comma-joined coordinates.
     *
     * @param array $commands Array of absolute command arrays (as returned by parseToAbsolute()).
     * @return string SVG path data string.
     */
    private function commandsToPath(array $commands): string
    {
        $pathParts = [];

        foreach ($commands as $cmd) {
            if (empty($cmd['coords'])) {
                $pathParts[] = $cmd['command'];
            } else {
                $pathParts[] = $cmd['command'] . implode(',', $cmd['coords']);
            }
        }

        return implode('', $pathParts);
    }

    /**
     * Build a closed polygon path string from an array of [x, y] point pairs.
     *
     * Produces an M…L…L…Z path using only straight line segments. Returns an
     * empty string if fewer than two points are provided.
     *
     * @param array $points Numerically indexed array of [x, y] float pairs.
     * @return string SVG path string, or '' if $points has fewer than 2 entries.
     */
    private function createPolygonPath(array $points): string
    {
        if (count($points) < 2) {
            return '';
        }

        $path = 'M' . $points[0][0] . ',' . $points[0][1];

        for ($i = 1; $i < count($points); $i++) {
            $path .= 'L' . $points[$i][0] . ',' . $points[$i][1];
        }

        $path .= 'Z';
        return $path;
    }


    /********************************************************************/
    /*  POINT EXTRACTION STRATEGIES                                     */
    /********************************************************************/

    /**
     * Extract a flat list of key polygon points from absolute commands.
     *
     * For straight-line commands (M, L), the target point is taken directly.
     * For curve commands (C, Q, A), only the curve endpoint is kept; control
     * points are discarded. Z commands produce no points.
     *
     * This is the 'extract' strategy — use it when you want a clean polygon
     * outline with the minimum number of vertices.
     *
     * @param array $commands Array of absolute command arrays.
     * @return array Array of [x, y] float pairs.
     */
    private function extractPolygonPoints(array $commands): array
    {
        $points = [];

        foreach ($commands as $cmd) {
            switch ($cmd['command']) {
                case 'M':
                case 'L':
                    if (count($cmd['coords']) >= 2)
                        $points[] = [$cmd['coords'][0], $cmd['coords'][1]];
                    break;

                case 'C': // Cubic Bézier — take endpoint (coords[4], coords[5]).
                    if (count($cmd['coords']) >= 6)
                        $points[] = [$cmd['coords'][4], $cmd['coords'][5]];
                    break;

                case 'Q': // Quadratic Bézier — take endpoint (coords[2], coords[3]).
                    if (count($cmd['coords']) >= 4)
                        $points[] = [$cmd['coords'][2], $cmd['coords'][3]];
                    break;

                case 'A': // Arc — take endpoint (coords[5], coords[6]).
                    if (count($cmd['coords']) >= 7)
                        $points[] = [$cmd['coords'][5], $cmd['coords'][6]];
                    break;
            }
        }

        return $points;
    }

    /**
     * Convert all commands to a polygon point list, approximating curves with
     * sampled line segments.
     *
     * Straight-line commands (M, L) contribute their endpoint directly.
     * Curve commands are sampled at evenly spaced t values using the standard
     * Bézier parametric formula:
     * - Cubic Bézier (C): sampled at t = 0.25, 0.5, 0.75, 1.0 (4 points).
     * - Quadratic Bézier (Q): sampled at t = 1/3, 2/3, 1.0 (3 points).
     * - Arcs (A): approximated as a single endpoint only (simplified; not a
     *   true arc-to-segments conversion).
     *
     * This is the 'simplify' strategy — use it when curve fidelity matters more
     * than vertex count.
     *
     * @param array $commands Array of absolute command arrays.
     * @return array Array of [x, y] float pairs.
     * @todo  Implement proper arc-to-line-segments conversion for the A command.
     */
    private function simplifyToPolygon(array $commands): array
    {
        $points = [];
        $prevX  = 0;
        $prevY  = 0;

        foreach ($commands as $cmd) {
            switch ($cmd['command']) {
                case 'M':
                case 'L':
                    if (count($cmd['coords']) >= 2) {
                        $points[] = [$cmd['coords'][0], $cmd['coords'][1]];
                        $prevX    = $cmd['coords'][0];
                        $prevY    = $cmd['coords'][1];
                    }
                    break;

                case 'C':
                    // Sample cubic Bézier B(t) = (1-t)³P0 + 3(1-t)²tP1 + 3(1-t)t²P2 + t³P3
                    if (count($cmd['coords']) >= 6) {
                        list($x1, $y1, $x2, $y2, $x3, $y3) = $cmd['coords'];

                        for ($i = 1; $i <= 4; $i++) {
                            $t = $i / 4;
                            $x = pow(1-$t, 3) * $prevX + 3 * pow(1-$t, 2) * $t * $x1
                               + 3 * (1-$t) * pow($t, 2) * $x2 + pow($t, 3) * $x3;
                            $y = pow(1-$t, 3) * $prevY + 3 * pow(1-$t, 2) * $t * $y1
                               + 3 * (1-$t) * pow($t, 2) * $y2 + pow($t, 3) * $y3;
                            $points[] = [$x, $y];
                        }

                        $prevX = $x3;
                        $prevY = $y3;
                    }
                    break;

                case 'Q':
                    // Sample quadratic Bézier B(t) = (1-t)²P0 + 2(1-t)tP1 + t²P2
                    if (count($cmd['coords']) >= 4) {
                        list($x1, $y1, $x2, $y2) = $cmd['coords'];

                        for ($i = 1; $i <= 3; $i++) {
                            $t = $i / 3;
                            $x = pow(1-$t, 2) * $prevX + 2 * (1-$t) * $t * $x1 + pow($t, 2) * $x2;
                            $y = pow(1-$t, 2) * $prevY + 2 * (1-$t) * $t * $y1 + pow($t, 2) * $y2;
                            $points[] = [$x, $y];
                        }

                        $prevX = $x2;
                        $prevY = $y2;
                    }
                    break;

                case 'A':
                    // Simplified: use only the arc endpoint. A full implementation
                    // would convert the arc parameters to a series of line segments.
                    if (count($cmd['coords']) >= 7) {
                        $points[] = [$cmd['coords'][5], $cmd['coords'][6]];
                        $prevX    = $cmd['coords'][5];
                        $prevY    = $cmd['coords'][6];
                    }
                    break;
            }
        }

        return $points;
    }


    /********************************************************************/
    /*  UTILITY METHODS                                                 */
    /********************************************************************/

    /**
     * Generate a rectangular SVG path from a IIIF fragment selector string.
     *
     * Parses the xywh= format used by IIIF FragmentSelector and returns a
     * closed rectangle path. This is the most reliable way to produce a correct
     * bounding-box path for rectangular annotations, bypassing any SVG path
     * normalisation issues entirely.
     *
     * Example:
     *   "xywh=1641,580,172,154" → "M1641,580h172v154h-172Z"
     *
     * @param string $fragmentSelector IIIF fragment selector string, e.g. "xywh=100,200,300,400".
     * @return string SVG path string for the rectangle, or '' if the input cannot be parsed.
     */
    public function pathFromFragmentSelector(string $fragmentSelector): string
    {
        if (preg_match('/xywh=([^,]+),([^,]+),([^,]+),(.+)/', $fragmentSelector, $matches)) {
            list(, $x, $y, $w, $h) = $matches;
            return "M{$x},{$y}h{$w}v{$h}h-{$w}Z";
        }

        return '';
    }

    /**
     * Process all SvgSelector and FragmentSelector annotations in a IIIF manifest
     * and return fixed path data alongside extracted bounding boxes.
     *
     * Iterates the annotation items in `manifest.items[0].annotations[0].items`
     * and for each annotation:
     * - Applies all three fix strategies ('normalize', 'simplify', 'extract') to
     *   any SvgSelector path found.
     * - Generates a fragment-based path and bounding box from any FragmentSelector.
     *
     * Returns an empty array if the expected manifest structure is not present.
     *
     * @param array $manifest Parsed IIIF manifest as a PHP associative array.
     * @return array Array of processed annotation records, each with keys:
     *               'id', 'body', 'original_paths', 'fixed_paths',
     *               'fragment_selector', 'bounding_box'.
     */
    public function processIIIFAnnotations(array $manifest): array
    {
        $processedAnnotations = [];

        if (!isset($manifest['items'][0]['annotations'][0]['items'])) {
            return $processedAnnotations;
        }

        $annotations = $manifest['items'][0]['annotations'][0]['items'];

        foreach ($annotations as $annotation) {
            $processed = [
                'id'                => $annotation['id'] ?? '',
                'body'              => $annotation['body']['value'] ?? '',
                'original_paths'    => [],
                'fixed_paths'       => [],
                'fragment_selector' => '',
                'bounding_box'      => null
            ];

            if (isset($annotation['target']['selector']) && is_array($annotation['target']['selector'])) {
                foreach ($annotation['target']['selector'] as $selector) {

                    if ($selector['type'] === 'SvgSelector') {
                        // Extract the d= attribute value and apply all three fix strategies.
                        if (preg_match('/d="([^"]+)"/', $selector['value'], $matches)) {
                            $originalPath = $matches[1];
                            $processed['original_paths'][] = $originalPath;

                            $processed['fixed_paths']['normalize'] = $this->fixPath($originalPath, 'normalize');
                            $processed['fixed_paths']['simplify']  = $this->fixPath($originalPath, 'simplify');
                            $processed['fixed_paths']['extract']   = $this->fixPath($originalPath, 'extract');
                        }
                    }

                    if ($selector['type'] === 'FragmentSelector') {
                        $processed['fragment_selector']            = $selector['value'];
                        $processed['fixed_paths']['from_fragment'] = $this->pathFromFragmentSelector($selector['value']);

                        // Extract the numeric bounding box from the xywh= string.
                        if (preg_match('/xywh=([^,]+),([^,]+),([^,]+),(.+)/', $selector['value'], $matches)) {
                            $processed['bounding_box'] = [
                                'x'      => floatval($matches[1]),
                                'y'      => floatval($matches[2]),
                                'width'  => floatval($matches[3]),
                                'height' => floatval($matches[4])
                            ];
                        }
                    }
                }
            }

            $processedAnnotations[] = $processed;
        }

        return $processedAnnotations;
    }
}
?>