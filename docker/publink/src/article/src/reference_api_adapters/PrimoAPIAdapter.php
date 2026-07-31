<?php

namespace Biblhertz\Article\reference_api_adapters;

use GuzzleHttp\Client;
use Biblhertz\Article\reference_api_adapters\ReferenceAPIAdapter;
use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\Author;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Publink\Config;
use Exception;

/**
 * PrimoAPIAdapter
 *
 * Adapter class for resolving academic references via the Ex Libris Primo
 * discovery API (hosted EU region, Kubikat / MPG instance). Extends
 * {@see ReferenceAPIAdapter} and implements the {@see resolve()} method to
 * perform a title-based search and map results to {@see Reference} objects.
 *
 * Resolution strategy:
 * - For {@see Chapter} references, the chapter title and book title are
 *   concatenated and used as the search query.
 * - For all other types, the reference's own title is used directly.
 *
 * Results are stored on the originating reference via
 * {@see Reference::setRefCheck()} under the key `"alma"` and returned as a
 * {@see ReferenceCollection}. If no results are found, an empty collection is
 * stored and the string `"No Results Returned"` is returned.
 *
 * An alternative fuzzy search path is available via {@see fuzzySearchPrimo()},
 * which strips stopwords, scores candidates by title/author/year similarity,
 * and returns only the single best match above a configurable threshold.
 *
 * @package  Biblhertz\Article\reference_api_adapters
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class PrimoAPIAdapter extends ReferenceAPIAdapter {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var Client Guzzle HTTP client used for all Primo API requests. */
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
     * Resolve the adapter's {@see $reference} via the Primo search API.
     *
     * Attempts up to three progressively broader queries, stopping as soon as
     * a credible result (match percent ≥ 30) is found:
     *  1. Full title / chapter+book title.
     *  2. Stopword-stripped keywords, up to 5 words.
     *  3. Stopword-stripped keywords, up to 3 words.
     *
     * The first non-empty collection is kept as a fallback when no query
     * produces a credible hit, so the user still sees candidates.
     *
     * The final collection is stored on the reference under key `"alma"` via
     * {@see Reference::setRefCheck()} regardless of whether results were found.
     *
     * @return ReferenceCollection|string  A populated collection on success,
     *                                     or the string `"No Results Returned"`
     *                                     if all queries return no documents.
     *
     * @throws \Exception  Re-thrown after logging if an unrecoverable error occurs.
     */
    public function resolve(): ReferenceCollection|string {
        try {
            error_reporting(E_ERROR | E_PARSE);
            set_time_limit(60);

            $isChapter    = is_a($this->reference, "Biblhertz\Article\om\Chapter");
            $bookTitle    = $this->reference->getTitle();
            $chapterTitle = $isChapter ? $this->reference->getChapterTitle() : '';

            $sourceTitle = ($isChapter && !empty($chapterTitle))
                ? $chapterTitle . ", " . $bookTitle
                : $bookTitle;

            // First author's last name only — avoids over-constraining the query
            $authorStr   = $this->reference->getAuthorList(false);
            $firstAuthor = !empty($authorStr) ? trim(strtok($authorStr, ',')) : '';

            // Extract 4-digit year
            $year = '';
            if (preg_match('/\d{4}/', $this->reference->getYear(), $m)) {
                $year = $m[0];
            }

            // Queries ordered from most constrained to broadest fallback
            $queries = [
                $this->buildSearchQuery($sourceTitle, 5, $firstAuthor, $year),  // title + author + year
                $this->buildSearchQuery($sourceTitle, 5, $firstAuthor),          // title + author
                $this->buildSearchQuery($sourceTitle, 5, year: $year),           // title + year, no author
                $this->buildSearchQuery($sourceTitle, 3),                        // keywords only — broadest fallback
            ];

            // For chapters, add dedicated queries on chapter title and book title separately
            if ($isChapter && !empty($chapterTitle) && !empty($bookTitle)) {
                $queries[] = $this->buildSearchQuery($chapterTitle, 5, $firstAuthor, $year);
                $queries[] = $this->buildSearchQuery($chapterTitle, 5, $firstAuthor);
                $queries[] = $this->buildSearchQuery($bookTitle, 5, $firstAuthor, $year);
                $queries[] = $this->buildSearchQuery($bookTitle, 5, $firstAuthor);
                $queries[] = $this->buildSearchQuery($chapterTitle, 3);
            }

            $collection    = new ReferenceCollection();
            $firstNonEmpty = null;

            foreach ($queries as $query) {
                $result = $this->searchAndCollect($query);
                if (!$this->isEmptyCollection($result)) {
                    if ($firstNonEmpty === null) {
                        $firstNonEmpty = $result;
                    }
                    if (!$this->lacksCredibleResults($result)) {
                        $collection = $result;
                        break;
                    }
                }
            }

            // Fall back to the first non-empty result if nothing credible was found
            if ($this->isEmptyCollection($collection) && $firstNonEmpty !== null) {
                $collection = $firstNonEmpty;
            }

            $this->reference->setRefCheck(["alma" => $collection]);
            return $this->isEmptyCollection($collection) ? "No Results Returned" : $collection;

        } catch (Exception $e) {
            error_log((string) $e);
            throw $e;
        }
    }


    /****************************************************************/
    /* PRIVATE METHODS                                              */
    /****************************************************************/

    /**
     * Execute a Primo query and return the results as a ReferenceCollection.
     *
     * @param  string $query  Pre-formed Primo query string (e.g. `any,contains,foo`).
     * @param  string $scope  Search scope; defaults to `'MyInstitution'`.
     * @return ReferenceCollection  Parsed results (may be empty).
     */
    private function searchAndCollect(string $query, string $scope = 'MyInstitution'): ReferenceCollection {
        $results    = $this->httpGet($this->buildUrl($query, $scope));
        $collection = new ReferenceCollection();
        foreach ($results['docs'] ?? [] as $item) {
            $pref = $this->primoToReference($item);
            try {
                $collection->offsetSet($pref->getLabel(), $pref);
            } catch (\InvalidArgumentException $e) {
                // skip references with invalid labels
            }
        }
        return $collection;
    }


    /**
     * Returns true if no reference in the collection scores at or above $threshold.
     *
     * An empty collection also returns true (no credible results).
     *
     * @param  ReferenceCollection $collection
     * @param  float               $threshold  Match percent threshold (default 30).
     */
    private function lacksCredibleResults(ReferenceCollection $collection, float $threshold = 30.0): bool {
        foreach ($collection as $ref) {
            if ($ref->getMatchPercent() >= $threshold) {
                return false;
            }
        }
        return true;
    }


    /**
     * Returns true if the collection contains no references.
     *
     * @param  ReferenceCollection $collection
     */
    private function isEmptyCollection(ReferenceCollection $collection): bool {
        foreach ($collection as $_) {
            return false;
        }
        return true;
    }


    /**
     * Search Primo with fuzzy title matching and optional author/year re-ranking.
     *
     * Strips stopwords from the title, queries Primo for up to 10 candidates,
     * scores each result by title similarity (60%), author similarity (30%),
     * and year match (10%), then returns the single best-scoring document
     * above $threshold, or null if no candidates qualify.
     *
     * To use this as the primary strategy in {@see resolve()}, replace the
     * {@see searchPrimoByTitle()} call with a call to this method and adapt
     * the result handling accordingly.
     *
     * @param  string      $title      Title to search for.
     * @param  string|null $author     Optional author name for re-ranking.
     * @param  int|null    $year       Optional publication year for re-ranking.
     * @param  float       $threshold  Minimum similarity score (0–100) to accept.
     * @return array|null  Best-matching Primo doc, or null if none qualify.
     */
    private function fuzzySearchPrimo(
        string  $title,
        ?string $author    = null,
        ?int    $year      = null,
        float   $threshold = 40.0
    ): ?array {
        $data = $this->httpGet($this->buildUrl($this->buildSearchQuery($title)));
        $docs = $data['docs'] ?? [];

        if (empty($docs)) {
            return null;
        }

        $scored = [];
        foreach ($docs as $doc) {
            $score = $this->scoreDoc($doc, $title, $author, $year);
            if ($score >= $threshold) {
                $scored[] = ['doc' => $doc, 'score' => $score];
            }
        }

        if (empty($scored)) {
            return null;
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored[0]['doc'];
    }


    /**
     * Build a fully qualified Primo API search URL from a query string.
     *
     * Uses the configured {@see Config::$PRIMO_URI}, {@see Config::$PRIMO_VID},
     * and {@see Config::$PRIMO_API_KEY} values.
     *
     * @param  string $query  Pre-formed Primo query string (e.g. `any,contains,foo`).
     * @param  string $scope  Search scope; defaults to `'MyInstitution'`.
     * @return string  Complete URL ready for a GET request.
     */
    private function buildUrl(string $query, string $scope = 'MyInstitution'): string {
        $params = [
            'vid'    => Config::$PRIMO_VID,
            'tab'    => 'LibraryCatalog',
            'scope'  => $scope,
            'q'      => $query,
            'apikey' => Config::$PRIMO_API_KEY,
            'limit'  => 10,
            'offset' => 0,
            'lang'   => 'en',
            'sort'   => 'rank',
        ];
        return rtrim(Config::$PRIMO_URI, '/') . '/primo/v1/search?'
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }


    /**
     * Send an authenticated GET request to the Primo API and return decoded JSON.
     *
     * @param  string $url  Fully qualified API URL.
     * @return array  Decoded JSON response as an associative array.
     * @throws \Exception  On HTTP error or network failure (propagated from Guzzle).
     */
    private function httpGet(string $url): array {
        $response = $this->client->request('GET', $url, [
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'Primo-Search/1.0',
            ],
            'timeout' => 30,
        ]);
        return json_decode((string) $response->getBody(), true) ?? [];
    }


    /**
     * Build a Primo search query from a title string with optional author and year.
     *
     * Strips common stopwords and takes the first $maxWords remaining keywords
     * to avoid Primo 500 errors on very long titles. Appends $author and $year
     * when provided so Primo's `any` field matches across title, creator, and date.
     *
     * @param  string $title     Raw title string.
     * @param  int    $maxWords  Maximum number of keywords to include (default 5).
     * @param  string $author    Optional author surname to append to the query.
     * @param  string $year      Optional publication year to append to the query.
     * @return string  Primo `any,contains,{keywords}` query string.
     */
    private function buildSearchQuery(string $title, int $maxWords = 5, string $author = '', string $year = ''): string {
        $stopwords = [
            'de', 'di', 'del', 'della', 'the', 'a', 'an', 'of',
            'in', 'da', 'il', 'la', 'le', 'lo', 'gli', 'un', 'una',
            'and', 'or', 'for', 'to', 'with', 'by', 'on', 'at',
        ];

        $words = explode(' ', strtolower(trim($title)));
        $words = array_filter($words, fn($w) => !in_array($w, $stopwords) && strlen($w) > 2);
        $words = array_slice(array_values($words), 0, $maxWords);

        $query = "any,contains," . implode(' ', $words);
        if (!empty($author)) {
            $query .= " " . $author;
        }
        if (!empty($year)) {
            $query .= " " . $year;
        }
        return $query;
    }


    /**
     * Score a Primo doc against the search criteria.
     *
     * Weights: title similarity 60%, author similarity 30%, year match 10%.
     * When no author is provided the title weight is redistributed to 90%.
     *
     * @param  array       $doc     A single Primo PNX document.
     * @param  string      $title   Expected title.
     * @param  string|null $author  Expected author name (or null to skip).
     * @param  int|null    $year    Expected publication year (or null to skip).
     * @return float  Score in the range 0–100.
     */
    private function scoreDoc(array $doc, string $title, ?string $author, ?int $year): float {
        $display = $doc['pnx']['display'] ?? [];
        $addata  = $doc['pnx']['addata']  ?? [];

        $docTitle = $display['title'][0] ?? $addata['btitle'][0] ?? '';
        similar_text(
            strtolower($this->normalizeString($title)),
            strtolower($this->normalizeString($docTitle)),
            $titleScore
        );
        $score = $titleScore * 0.6;

        if ($author) {
            $authorScore = 0.0;
            $docAuthors  = array_merge(
                $display['creator']     ?? [],
                $display['contributor'] ?? [],
                $addata['au']           ?? [],
                $addata['addau']        ?? []
            );
            foreach ($docAuthors as $docAuthor) {
                similar_text(
                    strtolower($this->normalizeString($author)),
                    strtolower($this->normalizeString($docAuthor)),
                    $pct
                );
                $authorScore = max($authorScore, $pct);
            }
            $score += $authorScore * 0.3;
        } else {
            $score = $titleScore * 0.9;
        }

        if ($year) {
            $docYear = (int) ($display['creationdate'][0] ?? $addata['date'][0] ?? 0);
            if ($docYear === $year) {
                $score += 10.0;
            } elseif (abs($docYear - $year) <= 2) {
                $score += 5.0;
            }
        }

        return $score;
    }


    /**
     * Normalize a string for comparison.
     *
     * Strips Primo internal `$$X` codes, removes punctuation, and collapses
     * whitespace so that fuzzy string matching is not affected by formatting.
     *
     * @param  string $str  Raw string (may contain Primo codes or punctuation).
     * @return string  Normalised string.
     */
    private function normalizeString(string $str): string {
        $str = preg_replace('/\$\$[^\s]+/', '', $str);
        $str = preg_replace('/[^\w\s]/u', ' ', $str);
        return trim(preg_replace('/\s+/', ' ', $str));
    }


    /**
     * Map a Primo PNX record to a {@see Reference} object.
     *
     * Clones the adapter's current {@see $reference} as a base and populates
     * it with data from the Primo `pnx` display and search sections.
     *
     * @param  array $recordData  A single document from the Primo `docs` array.
     * @return Reference  A cloned and populated reference object.
     */
    private function primoToReference(array $recordData): Reference {
        $reference = clone $this->reference;

        $reference->setTitle($recordData['pnx']['display']['title'][0] ?? '');

        $authors = $recordData['pnx']['display']['creator'] ?? [];
        $astr    = '';
        $first   = true;
        foreach ($authors as $author) {
            if (!$first) $astr .= " and ";
            $first  = false;
            // Strip everything from the first $$ onwards — Primo appends internal
            // codes (e.g. "$$TSmith, John") after the actual name, and removing
            // individual $$X tokens leaves name fragments behind.
            $author = trim(preg_replace('/\s*\$\$.*$/s', '', $author));
            $astr  .= $author;
        }
        $reference->setAuthors(Author::parseBibtexAuthors($astr));

        $date = str_replace(['[', ']'], '', $recordData['pnx']['display']['creationdate'][0] ?? '');
        $reference->setYear($date);

        $publisher = $recordData['pnx']['display']['publisher'] ?? [];
        if (count($publisher)) $reference->setPublisher($publisher[0]);

        $isbn = $recordData['pnx']['search']['isbn'] ?? [];
        if (count($isbn)) $reference->setISBN($isbn[0]);

        $issn = $recordData['pnx']['search']['issn'] ?? [];
        if (count($issn)) $reference->setISSN($issn[0]);

        $doi = $recordData['pnx']['search']['doi'] ?? [];
        if (count($doi)) {
            $reference->setPubIdType("doi");
            $reference->setPubId($doi[0]);
        }

        $url = $recordData['pnx']['links']['primo_url'] ?? '';
        if (!empty($url)) $reference->setUri($url);

        $reference->setLabel(uniqid());

        // For chapters, score against both chapter title and book title; take the higher.
        // Primo typically indexes books, so the result title is the book title, but
        // some systems index individual chapters — checking both maximises match quality.
        if (is_a($this->reference, "Biblhertz\Article\om\Chapter")) {
            $chapterScore = self::scoreTitleMatch($this->reference->getChapterTitle(), $reference->getTitle());
            $bookScore    = self::scoreTitleMatch($this->reference->getTitle(), $reference->getTitle());
            $reference->setMatchPercent(max($chapterScore, $bookScore));
        } else {
            $reference->setMatchPercent(self::scoreTitleMatch($this->reference->getTitle(), $reference->getTitle()));
        }

        return $reference;
    }
}
?>
