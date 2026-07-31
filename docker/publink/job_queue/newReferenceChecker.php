<?php
/**
 * Enriches the references of a serialized Article object by querying external APIs,
 * persisting progress after each reference, and marking the job as finished.
 *
 * This handler is executed as a scheduled job. It loads a serialized Article, marks it
 * as read-only to signal to other processes that a reference check is in progress, then
 * iterates over its references and attempts to resolve each one against three external
 * APIs in sequence: CrossRef, Primo, and Google Books. The article object is re-persisted
 * to the database after each successfully resolved reference so that progress is not lost
 * if the job is interrupted. Read-only mode is lifted on completion.
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
 * @note  Each reference is resolved against all three APIs unconditionally — there is no
 *        short-circuit on a successful match. If a reference is already resolved by CrossRef,
 *        the Primo and Google Books calls will still be made. Consider adding early-exit
 *        logic based on the return value of resolve() if redundant lookups are a concern.
 *
 * @note  Per-reference exceptions are caught and logged, allowing the loop to continue
 *        with the next reference. The return value of resolve() ($ret) is not currently
 *        inspected; adapter failures that do not throw will be silently ignored.
 *
 * @note  $object->updateObject($article) is called after every successfully processed
 *        reference, which means a DB write occurs on each iteration. This ensures progress
 *        is durable but may be expensive for articles with large reference lists.
 *
 * @see   Biblhertz\Article\reference_api_adapters\CrossRefAdapter
 * @see   Biblhertz\Article\reference_api_adapters\PrimoAPIAdapter
 * @see   Biblhertz\Article\reference_api_adapters\GoogleBooksAdapter
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\Article;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\reference_api_adapters\CrossRefAdapter;
use Biblhertz\Article\reference_api_adapters\PrimoAPIAdapter;
use Biblhertz\Article\reference_api_adapters\GoogleBooksAdapter;

try {
    $object  = new SerializedObject($objDB, $params['object_id']);
    $article = unserialize($object->getObject());
    $user    = new User($objDB, $params['user_details_id']);

    // Validate that deserialization produced a usable Article object.
    // unserialize() returns false on failure, or may produce an incomplete object
    // if the class definition has changed since the object was serialized.
    if (!is_object($article)) {
        throw new Exception("!! Reference Checker :: article object could not be instantiated from serialized data");
    }

    // Mark the article as read-only and flag that a reference check is in progress.
    // This signals to other processes (e.g. the UI) that the object is being modified
    // and should not be edited concurrently. Persisted immediately so the flag is visible
    // before the potentially long-running reference loop begins.
    $article->setReferenceCheck(true);
    $article->setReadOnly(true);
    $object->updateObject($article);

    $numRefs = count($article->getReferences());
    $logger->print("Object   :: " . $object->getName() . " (id: " . $params['object_id'] . ")");
    $logger->print("User     :: " . $user->getName());
    $logger->print("Refs     :: " . $numRefs . " to check");
    $job->setStatus("Starting Reference Check :: $numRefs References Found");

    // Instantiate API adapters once outside the loop to avoid repeated object construction.
    $crossRef = new CrossRefAdapter();
    $primo    = new PrimoAPIAdapter();
    $google   = new GoogleBooksAdapter();

    $cancelled = false;
    $c = 1;
    foreach ($article->getReferences() as $ref) {

            // Check before each reference in case an admin cancelled the job.
            if ($job->isCancelled()) {
                $logger->print("Job cancelled by administrator — stopping at reference $c of $numRefs.");
                $article->setReferenceCheck(false);
                $article->setReadOnly(false);
                $object->updateObject($article);
                $cancelled = true;
                break;
            }

            $message = "Checking Reference $c of $numRefs :: " . $ref->getTitle();
            $job->updateStatus("Checking reference $c of $numRefs");
            $logger->print($message);

            try {
                // Attempt resolution via CrossRef (DOI-based and metadata matching).
                // If resolve() succeeds it enriches $ref in place.
                $crossRef->setReference($ref);
                $crossRef->resolve();
            } catch (\Throwable $e) {
                $logger->print("Reference $c failed CrossRef :: " . $e->getMessage());
            }

            try {
                // Attempt resolution via the Primo library discovery API.
                if (Config::$PRIMO_INTEGRATION) {
                    $primo->setReference($ref);
                    $primo->resolve();
                }
            } catch (\Throwable $e) {
                $logger->print("Reference $c failed Primo :: " . $e->getMessage());
            }

            try {
                // Attempt resolution via Google Books (useful for monograph references).
                $google->setReference($ref);
                $google->resolve();
            } catch (\Throwable $e) {
                $logger->print("Reference $c failed Google :: " . $e->getMessage());
            }

            // Store the best match score across all API results on the reference itself
            // so it can be displayed in the all-references view without re-iterating refCheck.
            $bestScore = 0.0;
            foreach (['crossref', 'alma', 'google'] as $adapterKey) {
                $candidates = $ref->getRefCheck($adapterKey);
                if ($candidates instanceof ReferenceCollection) {
                    foreach ($candidates as $candidate) {
                        if ($candidate->getMatchPercent() > $bestScore) {
                            $bestScore = $candidate->getMatchPercent();
                        }
                    }
                }
            }
            if ($bestScore > 0.0) {
                $ref->setMatchPercent($bestScore);
            }

            // Persist the updated article after each reference so progress survives
            // a mid-job failure. This incurs a DB write per iteration.
            $object->updateObject($article);

            $logger->print("Completed $c of $numRefs :: updated object in DB");
            $c++;

    }

    if (!$cancelled) {
        $logger->print("Reference check complete :: all $numRefs references processed");

        // Lift read-only mode now that all references have been processed, and persist
        // the final state of the article.
        $article->setReadOnly(false);
        $object->updateObject($article);

        $job->updateStatus("FINISHED");
        $logger->writeOutUserLogFile("ReferenceChecker", $user);
    }

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in ReferenceChecker job :: " . $e->getMessage());
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