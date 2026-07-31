<?php
/**
 * Reference Lookup Service
 *
 * Resolves a single reference against an external bibliographic API and returns
 * the enriched result as an HTML table list for inline page injection. Also
 * records the resolution check status on the reference object in the database.
 *
 * Request parameters:
 *   oid      int     ID of the serialized object (Article or ReferenceCollection)
 *                    containing the reference to look up. Must be numeric.
 *   key      string  Label of the specific reference to resolve.
 *   service  string  External API to query. Supported values:
 *                      "alma"      → {@see PrimoAPIAdapter}  (Ex Libris Alma/Primo)
 *                      "crossref"  → {@see CrossRefAdapter}  (CrossRef DOI lookup)
 *                      "google"    → {@see GoogleBooksAdapter} (Google Books)
 *
 * Flow:
 *   1. Load and deserialize the object, extract the target reference by key.
 *   2. Select the appropriate adapter and call {@see resolve()} to fetch the
 *      reference data from the external service.
 *   3. Re-fetch the object from the database before saving, to avoid
 *      overwriting concurrent updates from other async services running
 *      against the same object (e.g. parallel reference checker jobs).
 *   4. Record the check result for this service on the reference via
 *      {@see Reference::setRefCheck()} and persist the updated collection.
 *   5. Return the retrieved reference(s) as an HTML table list.
 *
 * Step 3 is important: the object may have been modified between the initial
 * load and the save, so the freshest version is always re-fetched before
 * writing back the check status.
 *
 * Response cases:
 *   - Adapter returns a single Reference     → wrapped in a temporary
 *     ReferenceCollection for uniform presentation
 *   - Adapter returns a ReferenceCollection  → rendered directly
 *   - Adapter returns nothing / unrecognised → HTML error message
 *   - Invalid service value                  → HTML error message, exits early
 *   - Reference cannot be resolved from key  → HTML error message
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML table list of retrieved reference(s), or an HTML error message.
 *
 * Note: the outer catch block rethrows exceptions without handling them,
 * resulting in an unformatted PHP error page. It should either handle the
 * exception (as the inner catch does) or be removed, letting the exception
 * propagate naturally.
 *
 * @package Biblhertz\Publink
 * @see     PrimoAPIAdapter
 * @see     CrossRefAdapter
 * @see     GoogleBooksAdapter
 * @see     ReferenceCollectionPresentation::getAsTableList()
 */

require 'vendor/autoload.php';

use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\presentation\ReferencePresentation;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\om\presentation\ReferenceCollectionPresentation;
use Biblhertz\Article\reference_api_adapters\CrossRefAdapter;
use Biblhertz\Article\reference_api_adapters\PrimoAPIAdapter;
use Biblhertz\Article\reference_api_adapters\GoogleBooksAdapter;
use Biblhertz\Publink\om\SerializedObject;
use Biblhertz\Publink\pages\Bibliotheca_Content_Page;

try {
    $page = new Bibliotheca_Content_Page();

    // -------------------------------------------------------------------------
    // Validate and load the serialized object
    // -------------------------------------------------------------------------

    if (isset($_REQUEST['oid']) && is_numeric($_REQUEST['oid'])) {
        $object = new SerializedObject($page->getObjDB(), $_REQUEST['oid']);
    } else {
        throw new Exception("Error :: No object ID is set or ID is not numeric");
    }

    // -------------------------------------------------------------------------
    // Validate the reference key
    // -------------------------------------------------------------------------

    if (isset($_REQUEST['key']) && strcmp("", $_REQUEST['key'])) {
        $key = $_REQUEST['key'];
    } else {
        throw new Exception("Error :: No reference ID is set");
    }

    // -------------------------------------------------------------------------
    // Extract the target reference from the collection or article
    // -------------------------------------------------------------------------

    $collection = unserialize($object->getObject());

    if (is_a($collection, "\Biblhertz\Article\om\ReferenceCollection") ||
        is_a($collection, "\Biblhertz\Article\om\Article")) {
        $reference = $collection->getReferenceFromKey($key);
    }

    // -------------------------------------------------------------------------
    // Resolve the reference via the selected external API
    // -------------------------------------------------------------------------

    if (isset($reference) && is_a($reference, "\Biblhertz\Article\om\Reference")) {

        try {
            // Select the adapter for the requested external service
            if (!strcmp($_REQUEST['service'], "alma")) {
                $adapter = new PrimoAPIAdapter();
            } elseif (!strcmp($_REQUEST['service'], "crossref")) {
                $adapter = new CrossRefAdapter();
            } elseif (!strcmp($_REQUEST['service'], "google")) {
                $adapter = new GoogleBooksAdapter();
            } else {
                header('Content-Type:text/html; charset=UTF-8');
                echo "<hr/><b>No valid service was selected</b>";
                exit;
            }

            $adapter->setReference($reference);
            $retrievedReference = $adapter->resolve();

            // -------------------------------------------------------------------------
            // Persist the check status — re-fetch the object first to avoid
            // overwriting concurrent updates from other async services
            // -------------------------------------------------------------------------

            // Get the check result recorded by the adapter on the original reference
            $updated = $reference->getRefCheck($_REQUEST['service']);

            // Re-fetch the latest version of the object from the database
            $object     = new SerializedObject($page->getObjDB(), $_REQUEST['oid']);
            $collection = unserialize($object->getObject());

            // Update only the check status on the reference, leaving other fields intact
            $oldRef = $collection->getReferenceFromKey($key);
            $oldRef->setRefCheck([$_REQUEST['service'] => $updated]);

            // Save the updated collection back to the database
            $object->updateObject($collection);

            // -------------------------------------------------------------------------
            // Render the retrieved reference(s)
            // -------------------------------------------------------------------------

            if (isset($retrievedReference) && is_a($retrievedReference, "\Biblhertz\Article\om\Reference")) {
                // Single reference returned — wrap in a temporary collection for presentation
                $collection = new ReferenceCollection();
                $collection->offsetSet($retrievedReference->getLabel(), $retrievedReference);
                $presentation = new ReferenceCollectionPresentation($collection);
                header('Content-Type:text/html; charset=UTF-8');
                echo $presentation->getAsTableList();

            } elseif (isset($retrievedReference) && is_a($retrievedReference, "\Biblhertz\Article\om\ReferenceCollection")) {
                // Collection of candidates returned — render directly
                $presentation = new ReferenceCollectionPresentation($retrievedReference);
                header('Content-Type:text/html; charset=UTF-8');
                echo $presentation->getAsTableList();

            } else {
                // Adapter returned nothing or an unrecognised type
                header('Content-Type:text/html; charset=UTF-8');
                echo "<hr/><b>Could not retrieve Reference from remote source</b><hr/>$retrievedReference";
            }

        } catch (Exception $e) {
            error_log((string)$e);
            header('Content-Type:text/html; charset=UTF-8');
            echo (string)$e;
        }

    } else {
        // Reference key did not resolve to a valid Reference object
        header('Content-Type:text/html; charset=UTF-8');
        echo "<b>Could not create Reference object</b>";
        echo $reference;
    }

} catch (Exception $e) {
    // Note: rethrowing here produces an unformatted PHP error page.
    // Consider handling as the inner catch does, or removing this block.
    throw $e;
}

?>