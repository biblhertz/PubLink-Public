<?php
namespace Biblhertz\Publink\annotation;

/**
 * BaseController
 *
 * Abstract base class for API controllers in the Image Annotation API.
 * Provides shared utility methods for URI parsing, query string handling,
 * and HTTP response output. All API controllers should extend this class.
 *
 * @package Biblhertz\Publink\annotation
 */
abstract class BaseController
{
    /**
     * Callable invoked to terminate execution after sending a response.
     * Defaults to the built-in exit(). Can be overridden in tests.
     *
     * @var callable
     */
    protected $exitHandler;

    public function __construct(?callable $exitHandler = null)
    {
        $this->exitHandler = $exitHandler ?? static function () { exit; };
    }

    /**
     * Magic method to handle calls to undefined methods.
     *
     * Returns a 404 Not Found response when a requested route/method
     * does not exist on the controller.
     *
     * @param string $name      The name of the method being called.
     * @param array  $arguments The arguments passed to the method.
     * @return void
     */
    public function __call($name, $arguments): void
    {
        $this->sendOutput(
            json_encode(['error' => 'Not Found']),
            ['HTTP/1.1 404 Not Found', 'Content-Type: application/json']
        );
    }

    /**
     * Parse and return the URI path segments from the current request.
     *
     * Splits the request URI path on forward slashes. Empty segments
     * (produced by leading/trailing slashes) are removed.
     *
     * Example:
     *   Request URI: /api/annotation/123
     *   Returns:     ['api', 'annotation', '123']
     *
     * @return array The URI path split into individual segments.
     */
    protected function getUriSegments(): array
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return array_values(array_filter(explode('/', $uri)));
    }

    /**
     * Parse and return the query string parameters from the current request.
     *
     * Decodes the query string into an associative array of key/value pairs.
     *
     * Example:
     *   Query string: ?page=2&limit=10
     *   Returns:      ['page' => '2', 'limit' => '10']
     *
     * @return array Associative array of query string parameters.
     */
    protected function getQueryStringParams(): array
    {
        parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
        return $query;
    }

    /**
     * Send the API response with optional HTTP headers and terminate execution.
     *
     * Strips outgoing Set-Cookie headers to prevent session leakage in API
     * responses, then applies the provided headers and outputs the response body.
     *
     * @param string $data        The response body to output (typically a JSON string).
     * @param array  $httpHeaders Optional HTTP header strings to send.
     *                            e.g. ['HTTP/1.1 200 OK', 'Content-Type: application/json']
     * @return void
     */
    protected function sendOutput(string $data, array $httpHeaders = []): void
    {
        // Strip Set-Cookie to prevent session leakage from API responses.
        header_remove('Set-Cookie');

        if (!in_array('Content-Type: application/json', $httpHeaders, true)) {
            header('Content-Type: application/json');
        }

        foreach ($httpHeaders as $httpHeader) {
            header($httpHeader);
        }

        echo $data;
        ($this->exitHandler)();
    }
}