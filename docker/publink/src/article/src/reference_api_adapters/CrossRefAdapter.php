<?php

namespace Biblhertz\Article\reference_api_adapters;

use GuzzleHttp\Client;
use RenanBr\CrossRefClient;
use Biblhertz\Article\adapters\BibtexToReferenceCollectionAdapter;
use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\reference_api_adapters\ReferenceAPIAdapter;
use Biblhertz\Publink\Config;

/**
 * CrossRefAdapter
 *
 * Adapter class for resolving academic references via the CrossRef and PubMed (NCBI)
 * APIs. Extends {@see ReferenceAPIAdapter} and implements the {@see resolve()} method
 * to retrieve full bibliographic data for a given {@see Reference} object.
 *
 * Resolution strategy is chosen based on the reference's pub-id type:
 *  - `doi`  → direct DOI lookup via CrossRef content negotiation (returns BibTeX)
 *  - `pmid` → PubMed E-utilities fetch, converted to BibTeX internally
 *  - other  → title/author fuzzy search via the CrossRef Works API
 *
 * Retrieved data is always normalised through {@see BibtexToReferenceCollectionAdapter}
 * before being returned, ensuring a consistent {@see Reference} or
 * {@see ReferenceCollection} object regardless of the source.
 *
 * @package  Biblhertz\Article\reference_api_adapters
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class CrossRefAdapter extends ReferenceAPIAdapter {

    /****************************************************************/
    /* STATIC VARIABLES                                             */
    /****************************************************************/

    /**
     * CrossRef API client (RenanBr library wrapper).
     * Shared across all instances of this adapter.
     *
     * @var CrossRefClient
     */
    private static CrossRefClient $crossRefClient;

    /**
     * Guzzle HTTP client configured with the CrossRef base URI.
     * Used for direct DOI content-negotiation requests.
     *
     * @var Client
     */
    private static Client $guzzleClient;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialises the Guzzle and CrossRef API clients.
     *
     * The Guzzle client base URI is taken from {@see Reference::$CROSSREF_API_ADDRESS}
     * so that DOI lookups can be made with relative paths (i.e. the DOI itself).
     */
    public function __construct() {
        $crossref  = Reference::$CROSSREF_API_ADDRESS;
        $userAgent = 'Publink/1.0';
        if (!empty(Config::$CROSSREF_EMAIL)) {
            $userAgent .= ' (mailto:' . Config::$CROSSREF_EMAIL . ')';
        }
        self::$guzzleClient   = new Client([
            'base_uri' => $crossref,
            'headers'  => ['User-Agent' => $userAgent],
        ]);
        self::$crossRefClient = new CrossRefClient();
    }


    /****************************************************************/
    /* ABSTRACT METHOD IMPLEMENTATIONS                              */
    /****************************************************************/

    /**
     * Resolve the adapter's {@see $reference} against an external API.
     *
     * Dispatches to the appropriate lookup method based on the reference's
     * pub-id type (`doi`, `pmid`, or title-based), wraps the result in a
     * {@see ReferenceCollection}, stores it on the reference for later
     * comparison via {@see Reference::setRefCheck()}, and returns it.
     *
     * Error reporting is suppressed to `E_ERROR | E_PARSE` for the duration
     * of this call to avoid noise from third-party libraries.
     *
     * @return ReferenceCollection|null  The resolved collection, or null on failure.
     *
     * @throws \Exception  Re-thrown internally; outputs an error message to stdout
     *                     and logs to the error log on failure.
     */
    public function resolve(): ReferenceCollection|null {
        try {
            error_reporting(E_ERROR | E_PARSE);
            set_time_limit(60);

            if (!isset($this->reference)) {
                throw new \Exception("CrossRef API called on null Reference");
            }

            if ($this->reference->getPubIdType() === "doi") {
                $ref = $this->getFromDOI();
            } elseif ($this->reference->getPubIdType() === "pmid") {
                $ref = $this->getFromPMID();
            } else {
                $ref = $this->getFromTitle();
            }

            $ref = self::putReferenceinCollection($ref);

            // Store the CrossRef result on the reference for diff/check purposes
            $this->reference->setRefCheck(["crossref" => $ref]);

            return $ref;

        } catch (\Exception $e) {
            error_log("CrossRefAdapter::resolve failed: " . $e->getMessage());
        }
        return null;
    }


    /****************************************************************/
    /* PUBLIC LOOKUP METHODS                                        */
    /****************************************************************/

    /**
     * Resolve a reference using its DOI.
     *
     * Sends a GET request to the CrossRef content-negotiation endpoint with the
     * DOI as the path, requesting `application/x-bibtex`. The BibTeX response is
     * parsed by {@see BibtexToReferenceCollectionAdapter} to produce a
     * {@see Reference} object.
     *
     * @return Reference  The fully populated reference retrieved from CrossRef.
     *
     * @throws \Exception  Propagates any Guzzle or parsing exceptions to the caller.
     */
    public function getFromDOI(): Reference {
        $response = self::$guzzleClient->request('GET', $this->reference->getPubId(), [
            'headers' => ['accept' => 'application/x-bibtex'],
        ]);

        $body = (string) $response->getBody();
        $vals = BibtexToReferenceCollectionAdapter::translateBibtexItem($body);
        $ref  = BibtexToReferenceCollectionAdapter::makeReferenceFromBibtexArray($vals);
        $ref->setMatchPercent(self::identifierMatchScore($this->reference->getTitle(), $ref->getTitle()));
        return $ref;
    }

    /**
     * Resolve a reference using its PubMed ID (PMID).
     *
     * Fetches article data from the NCBI E-utilities API via {@see getBibtexFromPmid()},
     * then parses the resulting BibTeX string into a {@see Reference} object.
     *
     * @return Reference  The fully populated reference retrieved from PubMed.
     *
     * @throws \Exception  Propagates any HTTP or parsing exceptions to the caller.
     */
    public function getFromPMID(): Reference {
        $body = $this->getBibtexFromPmid($this->reference->getPubId());
        $vals = BibtexToReferenceCollectionAdapter::translateBibtexItem($body);
        $ref  = BibtexToReferenceCollectionAdapter::makeReferenceFromBibtexArray($vals);
        $ref->setMatchPercent(self::identifierMatchScore($this->reference->getTitle(), $ref->getTitle()));
        return $ref;
    }


    /****************************************************************/
    /* PRIVATE HELPER METHODS                                       */
    /****************************************************************/

    /**
     * Fetch a BibTeX-formatted citation from PubMed using a PMID.
     *
     * Queries the NCBI E-utilities `efetch` endpoint for the article XML,
     * extracts the relevant bibliographic fields (title, authors, journal,
     * year, volume, issue, pages, DOI), and assembles a BibTeX `@article`
     * entry string.
     *
     * The citation key is derived as `{FirstAuthorLastName}{Year}`, falling
     * back to `PMID_{pmid}` if author or year data is unavailable.
     *
     * Special characters in title and journal fields are escaped via
     * {@see escapeForBibtex()}.
     *
     * API identification headers (`tool` and `email`) are included in the
     * request URL as required by NCBI for improved service and rate limits.
     *
     * @param  string $pmid  The numeric PubMed ID to look up.
     *
     * @return string  A BibTeX `@article` entry string, or a BibTeX comment
     *                 string beginning with `% Error:` on failure.
     */
    private function getBibtexFromPmid(string $pmid): string {
        $tool  = "publink";
        $email = !empty(Config::$CROSSREF_EMAIL) ? Config::$CROSSREF_EMAIL : "publink@biblhertz.it";

        // Validate that the PMID is numeric
        if (!is_numeric($pmid)) {
            return "% Error: Invalid PubMed ID: $pmid";
        }

        // Build E-utilities efetch URL
        $efetchUrl = Reference::$ENTREZ_API_ADDRESS
            . "?db=pubmed&id={$pmid}&retmode=xml&tool={$tool}&email={$email}";

        // Fetch XML from PubMed
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $efetchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            curl_close($ch);
            return "% Error: Failed to connect to PubMed API";
        }

        curl_close($ch);

        // Parse XML response
        $xml = simplexml_load_string($response);
        if (!$xml || !isset($xml->PubmedArticle)) {
            return "% Error: No article found with PMID: $pmid";
        }

        $article    = $xml->PubmedArticle[0]->MedlineCitation->Article;
        $pubmedData = $xml->PubmedArticle[0]->PubmedData;

        // --- Extract fields ---

        $title = (string) $article->ArticleTitle;

        // Build author list in "LastName, ForeName" format; collective names are
        // wrapped in braces to prevent BibTeX from treating them as person names.
        $authors = [];
        if (isset($article->AuthorList->Author)) {
            foreach ($article->AuthorList->Author as $author) {
                if (isset($author->CollectiveName)) {
                    $authors[] = "{" . (string) $author->CollectiveName . "}";
                } else {
                    $lastName  = isset($author->LastName)  ? (string) $author->LastName  : '';
                    $foreName  = isset($author->ForeName)  ? (string) $author->ForeName  : '';
                    if ($lastName || $foreName) {
                        $authors[] = $lastName . ", " . $foreName;
                    }
                }
            }
        }

        $journal = (string) $article->Journal->Title;

        // Year may be absent if only a MedlineDate (e.g. "2015 Jan-Feb") is present
        $year = (string) $article->Journal->JournalIssue->PubDate->Year;
        if (empty($year) && isset($article->Journal->JournalIssue->PubDate->MedlineDate)) {
            preg_match('/^(\d{4})/', (string) $article->Journal->JournalIssue->PubDate->MedlineDate, $matches);
            if (isset($matches[1])) {
                $year = $matches[1];
            }
        }

        $volume = isset($article->Journal->JournalIssue->Volume)
            ? (string) $article->Journal->JournalIssue->Volume : '';

        $issue  = isset($article->Journal->JournalIssue->Issue)
            ? (string) $article->Journal->JournalIssue->Issue  : '';

        // BibTeX convention requires en-dash (--) for page ranges
        $pages  = isset($article->Pagination->MedlinePgn)
            ? str_replace('-', '--', (string) $article->Pagination->MedlinePgn) : '';

        // Extract DOI from the ArticleIdList
        $doi = '';
        if (isset($pubmedData->ArticleIdList->ArticleId)) {
            foreach ($pubmedData->ArticleIdList->ArticleId as $id) {
                if ((string) $id['IdType'] === 'doi') {
                    $doi = (string) $id;
                    break;
                }
            }
        }

        // Build citation key: {SanitisedLastName}{Year} or fallback PMID_{pmid}
        $citationKey = "PMID_" . $pmid;
        if (!empty($authors) && !empty($year)) {
            $authorParts = explode(",", $authors[0]);
            $lastName    = preg_replace('/[^a-zA-Z0-9]/', '', trim($authorParts[0]));
            $citationKey = $lastName . $year;
        }

        // Assemble BibTeX entry
        $bibtex  = "@article{" . $citationKey . ",\n";
        $bibtex .= "  title   = {" . $this->escapeForBibtex($title)   . "},\n";
        $bibtex .= "  author  = {" . implode(" and ", $authors)        . "},\n";
        $bibtex .= "  journal = {" . $this->escapeForBibtex($journal)  . "},\n";
        $bibtex .= "  year    = {" . $year                             . "},\n";

        if (!empty($volume)) $bibtex .= "  volume  = {" . $volume . "},\n";
        if (!empty($issue))  $bibtex .= "  number  = {" . $issue  . "},\n";
        if (!empty($pages))  $bibtex .= "  pages   = {" . $pages  . "},\n";
        if (!empty($doi))    $bibtex .= "  doi     = {" . $doi    . "},\n";

        $bibtex .= "  pmid    = {" . $pmid . "},\n";
        $bibtex .= "  note    = {PMID: " . $pmid . "}\n";
        $bibtex .= "}";

        return $bibtex;
    }

    /**
     * Escape characters that have special meaning in BibTeX.
     *
     * Replaces `%`, `$`, `_`, `&`, and `#` with their backslash-escaped
     * equivalents so that field values render correctly in LaTeX.
     *
     * @param  string $text  The raw text to escape.
     *
     * @return string  The escaped string, or an empty string if input is empty.
     */
    private function escapeForBibtex(string $text): string {
        if (empty($text)) {
            return '';
        }

        return str_replace(
            ['%',  '$',  '_',  '&',  '#'],
            ['\%', '\$', '\_', '\&', '\#'],
            $text
        );
    }

    /**
     * Resolve a reference using a title/author fuzzy search via the CrossRef Works API.
     *
     * Queries CrossRef for up to 5 candidate DOIs matching the reference's title
     * (or chapter title for {@see Chapter} objects) and author list, then fetches
     * the full BibTeX entry for each candidate DOI. Successfully parsed references
     * are added to a {@see ReferenceCollection} and returned.
     *
     * If a duplicate citation key is detected within the collection, a {@see uniqid()}
     * fallback label is used to avoid key collisions.
     *
     * @return ReferenceCollection  Collection of candidate references matching the
     *                              title/author query (may be empty if none found).
     *
     * @throws \Exception  Propagates critical exceptions after logging; individual
     *                     DOI fetch failures are caught and skipped silently.
     */
    public function getFromTitle(): ReferenceCollection {
        try {
            // Use chapter title for Chapter references, otherwise use the main title
            if (is_a($this->reference, "Biblhertz\Article\om\Chapter")) {
                $rawTitle = $this->reference->getChapterTitle();
            } else {
                $rawTitle = $this->reference->getTitle();
            }
            $title = urlencode($rawTitle);

            $authors = urlencode($this->reference->getAuthorList(false));
            $filter  = $this->reference->getFilterType();

            // Query CrossRef Works endpoint for candidate DOIs
            $parameters = [
                'filter'              => "$filter",
                'query.bibliographic' => "$title,$authors",
                'select'              => 'DOI',
                'rows'                => '5',
            ];
            if (!empty(Config::$CROSSREF_EMAIL)) {
                $parameters['mailto'] = Config::$CROSSREF_EMAIL;
            }

            $apiResponse = [];
            try {
                $apiResponse = self::$crossRefClient->request('works', $parameters);
            } catch (\Exception $e) {
                error_log("CrossRef Works API request failed: " . $e->getMessage());
            }

            $collection = new ReferenceCollection();

            // For each candidate DOI, fetch full BibTeX and build a Reference object
            foreach ($apiResponse['message']['items'] ?? [] as $item) {
                $doi = urlencode(trim($item['DOI']));

                if (!empty($doi)) {
                    try {
                        $response = self::$guzzleClient->request('GET', $doi, [
                            'headers' => [
                                'accept' => 'application/x-bibtex',
                            ],
                        ]);

                        $bibtex = (string) $response->getBody();
                        $vals   = BibtexToReferenceCollectionAdapter::translateBibtexItem($bibtex);
                        $ref    = BibtexToReferenceCollectionAdapter::makeReferenceFromBibtexArray($vals);
                        $ref->setMatchPercent(self::scoreTitleMatch($rawTitle, $ref->getTitle()));

                        try {
                            // Guard against duplicate keys in the collection
                            if ($collection->exists($ref->getLabel(), false)) {
                                $ref->setLabel(uniqid());
                            }
                            $collection->offsetSet($ref->getLabel(), $ref);

                        } catch (\InvalidArgumentException $e) {
                            error_log("Invalid Argument Exception :: Not Added :: " . $e->getMessage());
                            continue;
                        }

                    } catch (\Exception $e) {
                        error_log("CrossRef DOI fetch failed for $doi: " . $e->getMessage());
                    }
                }
            }

            return $collection;

        } catch (\Exception $e) {
            error_log("CrossRef getFromTitle failed: " . $e->getMessage());
            throw $e;
        }
    }

}
?>