<?php
/**
 * Reference checker job handler — legacy CrossRef implementation (currently disabled).
 *
 * This is an earlier version of the reference enrichment handler, superseded by
 * referenceCheck.php which uses the dedicated adapter classes (CrossRefAdapter,
 * PrimoAPIAdapter, GoogleBooksAdapter). The core resolution logic in this file is
 * commented out; the handler currently only sets and then immediately clears the
 * article's read-only/reference-check flags, then marks the job as finished.
 *
 * When the resolution block was active, it queried the CrossRef API for each article
 * reference using one of three strategies depending on the available identifier:
 *   - PMID  → direct PubMed ID lookup via getCrossRefFromPMID()
 *   - DOI   → direct DOI lookup via getCrossRefFromPubID()
 *   - Other → title-based fuzzy matching via getCrossRefFromTitle()
 *
 * References without a title were skipped in the title-match path.
 * Per-reference exceptions were caught and logged, allowing the loop to continue.
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to report progress and update status
 * @param  array   $params  Job parameters; must include:
 *                            - 'object_id'       (int) ID of the serialized article object to process
 *                            - 'user_details_id' (int) ID of the user who owns the job
 *
 * @throws Exception  If the deserialized article is not a valid object. Re-throws any other
 *                    unexpected exception after marking the job as FAILED and logging the error.
 *
 * @note  The entire resolution loop is commented out. This handler will mark the article
 *        read-only, immediately clear it, and finish without performing any enrichment.
 *        If this job is still being dispatched, it should either be re-enabled, replaced
 *        with a reference to the newer referenceCheck.php handler, or removed from the
 *        job queue configuration.
 *
 * @note  Unlike the newer handler, $object->updateObject($article) inside the loop is
 *        also commented out, meaning progress would not have been persisted per-reference
 *        if the loop were re-enabled. The final updateObject() call after the loop remains
 *        active and would persist the final state.
 *
 * @note  The GuzzleHttp\Client and RenanBr\CrossRefClient imports are unused while the
 *        resolution block remains commented out.
 *
 * @deprecated  Superseded by referenceCheck.php. Re-enable or remove as appropriate.
 *
 * @see   referenceCheck.php  (current implementation using adapter classes)
 * @see   Biblhertz\Article\om\Reference::getCrossRefFromPMID()
 * @see   Biblhertz\Article\om\Reference::getCrossRefFromPubID()
 * @see   Biblhertz\Article\om\Reference::getCrossRefFromTitle()
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\Logger;
use GuzzleHttp\Client;           // Unused while resolution block is commented out
use RenanBr\CrossRefClient;      // Unused while resolution block is commented out
use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\Article;
use Biblhertz\Article\om\ReferenceCollection;

try {
    $object  = new SerializedObject($objDB, $params['object_id']);
    $article = unserialize($object->getObject());
    $user    = new User($objDB, $params['user_details_id']);
    $logger->print("Object   :: " . $object->getName() . " (id: " . $params['object_id'] . ")");
    $logger->print("User     :: " . $user->getName());

    // Validate that deserialization produced a usable Article object.
    if (!is_object($article)) {
        throw new Exception("!! Reference Checker :: article object could not be instantiated from serialized data");
    }

    // Mark the article as read-only and flag that a reference check is in progress.
    // Persisted immediately so the flag is visible to other processes before any
    // long-running work begins. Currently cleared almost immediately since the
    // resolution loop below is disabled.
    $article->setReferenceCheck(true);
    $article->setReadOnly(true);
    $object->updateObject($article);

    $logger->print("Reference check started (legacy handler — resolution loop disabled)");

    /*
     * CrossRef reference resolution loop — currently disabled.
     *
     * When re-enabled, this block iterates over the article's references and resolves
     * each one via the CrossRef API using the best available identifier strategy:
     *
     *   PMID  → getCrossRefFromPMID()   — direct PubMed ID lookup
     *   DOI   → getCrossRefFromPubID()  — direct DOI lookup
     *   Other → getCrossRefFromTitle()  — fuzzy title-based matching (skipped if no title)
     *
     * Per-reference exceptions are caught and logged so a single failure does not abort
     * the entire job. $object->updateObject() is commented out inside the loop; if
     * re-enabling, consider persisting progress per-reference to survive mid-job failures.
     *
     * $crossref = Reference::$CROSSREF_API_ADDRESS;
     * $numRefs  = count($article->getReferences());
     * $job->setStatus("Starting Reference Check :: $numRefs References Found");
     *
     * $client1 = new Client(['base_uri' => $crossref]);
     * $client2 = new CrossRefClient();
     * $c = 1;
     *
     * foreach ($article->getReferences() as $ref) {
     *     try {
     *         if (!strcmp($ref->getPubIdType(), "pmid")) {
     *             $job->updateStatus("Processing Reference $c of $numRefs from PMID : "
     *                 . $ref->getPubId() . " :: <i>" . $ref->getTitle() . "</i>");
     *             $ref->getCrossRefFromPMID($logger, $client1);
     *
     *         } elseif (!strcmp($ref->getPubIdType(), "doi")) {
     *             $job->updateStatus("Processing Reference $c of $numRefs from DOI : "
     *                 . $ref->getPubId() . " :: <i>" . $ref->getTitle() . "</i>");
     *             $ref->getCrossRefFromPubID($logger, $client1);
     *
     *         } else {
     *             $title = $ref->getTitle();
     *             if (!empty($title)) {
     *                 $job->updateStatus("Processing Reference $c of $numRefs :: Matching on Title :: <i>"
     *                     . $ref->getTitle() . "</i>");
     *                 $ref->getCrossRefFromTitle($logger, $client1, $client2);
     *             }
     *         }
     *
     *         $c++;
     *         // $object->updateObject($article); // Uncomment to persist progress per reference
     *
     *     } catch (Exception $e) {
     *         $logger->print($e->getMessage());
     *         continue;
     *     }
     * }
     */

    $logger->print("Reference check complete");

    // Lift read-only mode and persist the final article state.
    $article->setReadOnly(false);
    $object->updateObject($article);

    $job->updateStatus("FINISHED");
    $logger->writeOutUserLogFile("ReferenceChecker", $user);

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in ReferenceChecker (legacy) job :: " . $e->getMessage());

    // Attempt to lift read-only mode so the article is not left permanently locked
    // if the job fails after setReadOnly(true) was called.
    if (isset($article) && is_object($article)) {
        $article->setReferenceCheck(false);
        $article->setReadOnly(false);
        if (isset($object)) {
            $object->updateObject($article);
        }
    }

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>