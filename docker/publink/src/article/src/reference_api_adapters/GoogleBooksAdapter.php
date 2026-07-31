<?php

namespace Biblhertz\Article\reference_api_adapters;

use GuzzleHttp\Client;
use Biblhertz\Article\om\Book;
use Biblhertz\Article\om\Author;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\reference_api_adapters\ReferenceAPIAdapter;
use Biblhertz\Publink\Config;
use Exception;

/**
 * GoogleBooksAdapter
 *
 * Adapter class for resolving academic book and manuscript references via the
 * Google Books API. Extends {@see ReferenceAPIAdapter} and implements the
 * {@see resolve()} method to search for candidate {@see Book} objects matching
 * a given reference's title.
 *
 * Resolution is restricted to {@see Book} and {@see Manuscript} reference types;
 * all other types return null without making an API call.
 *
 * Results are returned as a {@see ReferenceCollection} and also stored on the
 * originating reference via {@see Reference::setRefCheck()} for later comparison.
 *
 * Identifier priority when setting the book's pub-id:
 *  1. ISBN-13
 *  2. ISBN-10
 *  3. Any `OTHER` industry identifier (stored with pub-id type `other`)
 *
 * @package  Biblhertz\Article\reference_api_adapters
 * @author   Chris Tomlinson
 * @date     2nd July 2025
 */
class GoogleBooksAdapter extends ReferenceAPIAdapter {

    /****************************************************************/
    /* STATIC VARIABLES                                             */
    /****************************************************************/

    /**
     * Base URL for the Google Books Volumes API endpoint.
     *
     * @var string
     */
    private static string $GOOGLE_API = 'https://www.googleapis.com/books/v1/volumes';

    /**
     * Maximum number of candidate results to request from the Google Books API
     * per search query.
     *
     * @var int
     */
    private static int $MAX_RESULTS = 5;

    /** @var Client Guzzle HTTP client used for all Google Books API requests. */
    private Client $client;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialises the Guzzle HTTP client.
     */
    public function __construct() {
        $this->client = new Client();
    }


    /****************************************************************/
    /* ABSTRACT METHOD IMPLEMENTATIONS                              */
    /****************************************************************/

    /**
     * Resolve the adapter's {@see $reference} against the Google Books API.
     *
     * Only processes references that are instances of {@see Book} or
     * {@see Manuscript}. Performs a title-based search via {@see searchByTitle()},
     * stores the result on the reference with key `"google"`, and returns it.
     *
     * Returns `null` silently if the reference type is not supported, or on
     * exception (error is logged and a plain-text message is sent to output).
     *
     * @return ReferenceCollection|null  Collection of candidate Book references,
     *                                   or null if the type is unsupported or an
     *                                   error occurs.
     */
    public function resolve(): ReferenceCollection|null {
        try {
            if (is_a($this->reference, "Biblhertz\Article\om\Book") ||
                is_a($this->reference, "Biblhertz\Article\om\Manuscript")) {
                $collection = $this->searchByTitle($this->reference->getTitle());
                $this->reference->setRefCheck(["google" => $collection]);
                return $collection;
            }
        } catch (\Throwable $e) {
            error_log("GoogleBooksAdapter::resolve failed: " . $e->getMessage());
        }

        return null;
    }


    /****************************************************************/
    /* PRIVATE METHODS                                              */
    /****************************************************************/

    /**
     * Search the Google Books API by title and return matching references.
     *
     * Builds an `intitle:` query against the Google Books Volumes endpoint,
     * requests up to {@see $MAX_RESULTS} results, and parses each returned
     * volume into a {@see Book} object via {@see parseBook()}.
     *
     * Successfully parsed books are added to a {@see ReferenceCollection}
     * keyed by the Google volume ID. {@see \InvalidArgumentException} errors
     * on individual items are logged and skipped without aborting the loop.
     *
     * @param  string $title  The book title to search for.
     *
     * @return ReferenceCollection  Collection of matched {@see Book} objects
     *                              (may be empty if no results are found).
     *
     * @throws \Exception  If the HTTP response code is not 200.
     */
    private function searchByTitle(string $title): ReferenceCollection {
        $url = self::$GOOGLE_API . '?q=intitle:' . urlencode($title)
             . '&maxResults=' . self::$MAX_RESULTS;
        if (!empty(Config::$GOOGLE_BOOKS_API_KEY)) {
            $url .= '&key=' . urlencode(Config::$GOOGLE_BOOKS_API_KEY);
        }

        $response = $this->client->request('GET', $url, [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 30,
        ]);
        $response = json_decode((string) $response->getBody(), true);
        $collection = new ReferenceCollection();

        if (isset($response['items']) && is_array($response['items'])) {
            foreach ($response['items'] as $item) {
                $book = $this->parseBook($item, $title);
                try {
                    $collection->offsetSet($book->getLabel(), $book);
                } catch (\InvalidArgumentException $e) {
                    error_log($e->getMessage());
                    continue;
                }
            }
        }

        return $collection;
    }

    /**
     * Parse a single Google Books API volume item into a {@see Book} reference.
     *
     * Maps the following `volumeInfo` fields onto the {@see Book} object:
     * - `id`            → label (falls back to `uniqid()`)
     * - `title`         → title; `subtitle` is appended as `"title: subtitle"` when present
     * - `publisher`     → publisher
     * - `publishedDate` → year (only the 4-digit year portion is stored)
     * - `previewLink`   → URI (canonical Google Books preview URL)
     * - `infoLink`      → note (as an HTML anchor tag)
     * - `language`      → language
     * - `authors`       → author list (parsed via {@see Author::parseBibtexAuthors()})
     * - `editors`       → editor list when `authors` contains "(ed.)" markers
     *
     * ISBN assignment follows the priority: ISBN-13 > ISBN-10 > OTHER identifier.
     *
     * @param  array  $bookData       A single item from the Google Books `items` array.
     * @param  string $originalTitle  Source title used for match-percent scoring.
     * @return Book  The populated Book reference object.
     */
    private function parseBook(array $bookData, string $originalTitle = ''): Book {
        $book       = new Book();
        $volumeInfo = $bookData['volumeInfo'] ?? [];

        $book->setLabel($bookData['id'] ?? uniqid());

        // Append subtitle when present
        $title = $volumeInfo['title'] ?? 'Unknown Title';
        if (!empty($volumeInfo['subtitle'])) {
            $title .= ': ' . $volumeInfo['subtitle'];
        }
        $book->setTitle($title);

        $book->setPublisher($volumeInfo['publisher'] ?? '');

        // Extract only the 4-digit year from dates like "2020-01-15" or "2020-01"
        $rawDate = $volumeInfo['publishedDate'] ?? '';
        if (preg_match('/(\d{4})/', $rawDate, $m)) {
            $book->setYear($m[1]);
        }

        // Canonical preview URL as URI
        if (!empty($volumeInfo['previewLink'])) {
            $book->setURI($volumeInfo['previewLink']);
        }

        // Attach a Google Books info link as an HTML note if available
        if (isset($volumeInfo['infoLink'])) {
            $link   = $volumeInfo['infoLink'];
            $target = "new_window_" . $book->getLabel();
            $book->setNote("<a href=\"" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "\" target=\"" . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . "\">Google Link</a>");
        }

        if (!empty($volumeInfo['language'])) {
            $book->setLanguage($volumeInfo['language']);
        }

        // Assign the best available ISBN / identifier
        $isbn = $this->extractISBN($volumeInfo);
        if (isset($isbn['isbn13'])) {
            $book->setIsbn($isbn['isbn13']);
        } elseif (isset($isbn['isbn10'])) {
            $book->setIsbn($isbn['isbn10']);
        } elseif (isset($isbn['OTHER'])) {
            $book->setPubIdType('other');
            $book->setPubId($isbn['OTHER']);
        }

        if ($originalTitle !== '') {
            $book->setMatchPercent(self::scoreTitleMatch($originalTitle, $book->getTitle()));
        }

        // Build and assign the author list
        if (isset($volumeInfo['authors']) && is_array($volumeInfo['authors'])) {
            // Google returns authors as plain strings; join with BibTeX "and" separator
            $astr      = implode(" and ", $volumeInfo['authors']);
            $authorArr = Author::parseBibtexAuthors($astr);
            $book->setAuthors($authorArr);
            $book->getAuthorList(false, true);
        }

        return $book;
    }

    /**
     * Extract ISBN and other industry identifiers from a volume's info array.
     *
     * Iterates over `industryIdentifiers` and maps entries of type `ISBN_10`,
     * `ISBN_13`, and `OTHER` into the returned array. Unrecognised identifier
     * types are ignored.
     *
     * @param  array $volumeInfo  The `volumeInfo` sub-array from a Google Books item.
     *
     * @return array{isbn10: string|null, isbn13: string|null, OTHER?: string}
     *              Associative array of found identifiers; `isbn10` and `isbn13`
     *              are always present (null if not found); `OTHER` is only set
     *              when an identifier of that type exists.
     */
    private function extractISBN(array $volumeInfo): array {
        $isbn = [
            'isbn10' => null,
            'isbn13' => null,
        ];

        if (isset($volumeInfo['industryIdentifiers'])) {
            foreach ($volumeInfo['industryIdentifiers'] as $identifier) {
                if ($identifier['type'] === 'ISBN_10') {
                    $isbn['isbn10'] = $identifier['identifier'];
                } elseif ($identifier['type'] === 'ISBN_13') {
                    $isbn['isbn13'] = $identifier['identifier'];
                } elseif ($identifier['type'] === 'OTHER') {
                    $isbn['OTHER'] = $identifier['identifier'];
                }
            }
        }

        return $isbn;
    }

    /**
     * Extract cover image URLs from a volume's info array.
     *
     * Maps the standard Google Books image size keys (`smallThumbnail`,
     * `thumbnail`, `small`, `medium`, `large`, `extraLarge`) to their URLs.
     * Only sizes returned by the API are populated; others remain null.
     *
     * Note: this method is defined but not currently used within {@see parseBook()}.
     * It is retained for potential future use when image data is required.
     *
     * @param  array $volumeInfo  The `volumeInfo` sub-array from a Google Books item.
     *
     * @return array<string, string|null>  Associative array of image size → URL.
     */
    private function extractImages(array $volumeInfo): array {
        $images = [
            'smallThumbnail' => null,
            'thumbnail'      => null,
            'small'          => null,
            'medium'         => null,
            'large'          => null,
            'extraLarge'     => null,
        ];

        if (isset($volumeInfo['imageLinks'])) {
            foreach ($volumeInfo['imageLinks'] as $size => $url) {
                if (array_key_exists($size, $images)) {
                    $images[$size] = $url;
                }
            }
        }

        return $images;
    }

    /**
     * Extract available digital format information from a volume's access info.
     *
     * Returns an array containing `epub` and/or `pdf` sub-arrays if those
     * formats are present in the API response. Absent formats are omitted
     * from the returned array entirely.
     *
     * Note: this method is defined but not currently used within {@see parseBook()}.
     * It is retained for potential future use when format availability is required.
     *
     * @param  array $accessInfo  The `accessInfo` sub-array from a Google Books item.
     *
     * @return array<string, array>  Associative array of format name → format info.
     */
    private function extractFormats(array $accessInfo): array {
        $formats = [];

        if (isset($accessInfo['epub'])) {
            $formats['epub'] = $accessInfo['epub'];
        }

        if (isset($accessInfo['pdf'])) {
            $formats['pdf'] = $accessInfo['pdf'];
        }

        return $formats;
    }
}
?>