<?php
/**
 * Reference Update Service
 *
 * Applies user-submitted field edits to a single reference within a serialized
 * object (Article or ReferenceCollection) and returns an updated HTML
 * representation of the reference for inline page injection.
 *
 * This service is the save endpoint for the reference editing UI, where the
 * user selects individual fields via checkboxes and submits new values.
 *
 * Request parameters:
 *   oid      int     ID of the serialized object containing the reference.
 *                    Must be numeric.
 *   key      string  Label of the reference to update.
 *   checked  string  JSON-encoded array of field update descriptors, each with:
 *                      id     string  Field identifier in the form "{property}_{suffix}",
 *                                     where the part before the first "_" is used to
 *                                     derive the setter method name (e.g. "title_123"
 *                                     → setTitle()).
 *                      value  string  New value to apply.
 *
 * Field update mechanism:
 *   For each checked item, the property name is extracted from the `id` field
 *   (first segment before "_", uppercased), and a corresponding setter is called
 *   on the reference object via dynamic dispatch (`set{Property}($value)`).
 *
 *   Special case — AuthorList:
 *     Author strings use BibTeX "and"-separated format. Comma-separated input
 *     from the UI is converted ("," → " and ") before being parsed via
 *     {@see Author::parseBibtexAuthors()}, which returns a structured author
 *     list that replaces the reference's existing authors.
 *
 * Flow:
 *   1. Load and deserialize the object, extract the target reference by key.
 *   2. Apply each submitted field update to the reference object.
 *   3. Persist the updated collection back to the database.
 *   4. Return the updated reference as an HTML table via
 *      {@see ReferencePresentation::getAsTable()}.
 *
 * Note: the `ReferencePresentation` is constructed before the updates are
 * applied. Since it holds a reference to the same object, the rendered output
 * reflects the post-update state. This is fine as long as `ReferencePresentation`
 * does not cache the reference data at construction time.
 *
 * Note: the outer catch block rethrows without handling, producing an
 * unformatted PHP error page. Consider replacing with a formatted HTML error
 * response, consistent with other services in this suite.
 *
 * Note: `use` declarations for `CrossRefAdapter` and `AlmaAPIAdapter` are
 * imported but unused — safe to remove.
 *
 * Output:
 *   Content-Type: text/html; charset=UTF-8
 *   Body: HTML table representation of the updated reference, or an HTML
 *         error message if the reference could not be resolved.
 *
 * @package Biblhertz\Publink
 * @see     ReferencePresentation::getAsTable()
 * @see     Author::parseBibtexAuthors()
 * @see     SerializedObject::updateObject()
 */

require 'vendor/autoload.php';

use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\Author;
use Biblhertz\Article\om\presentation\ReferencePresentation;
use Biblhertz\Article\om\presentation\ReferenceCollectionPresentation;
use Biblhertz\Article\reference_api_adapters\CrossRefAdapter;   // unused
use Biblhertz\Article\reference_api_adapters\AlmaAPIAdapter;    // unused
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

    if (is_a($collection, "\Biblhertz\Article\om\ReferenceCollection")) {
        $reference = $collection->getReferenceFromKey($key);
    } elseif (is_a($collection, "\Biblhertz\Article\om\Article")) {
        // For Articles, references are held in a nested ReferenceCollection
        $reference = $collection->getReferences()->getReferenceFromKey($key);
    }

    // -------------------------------------------------------------------------
    // Apply field updates from the submitted checkbox form data
    // -------------------------------------------------------------------------

    if (isset($reference) && is_a($reference, "\Biblhertz\Article\om\Reference")) {

        // Construct the presentation before updating — it holds a reference to
        // the same object, so getAsTable() will reflect the post-update state
        $presentation = new ReferencePresentation($reference);

        /** @var array $checked Decoded array of {id, value} field update descriptors */
        $checked = json_decode($_REQUEST['checked'], true);

        foreach ($checked as $cbox) {
            // Derive the property name from the first segment of the id (e.g. "title_123" → "Title")
            $propName = ucfirst(explode("_", $cbox['id'])[0]);
            $value    = $cbox['value'];

            if (isset($propName) && isset($value)) {

                //error_log($propName . " => " . $value);

                if (!strcmp($propName, "AuthorList") || !strcmp($propName, "EditorList")) {
                    // Authors are stored in BibTeX "and"-separated format;
                    // convert comma-separated UI input before parsing
                    $value   = str_replace(",", " and ", $value);
                    $authors = Author::parseBibtexAuthors($value);
                     if (!strcmp($propName, "AuthorList"))$reference->setAuthors($authors);
                     else $reference->setEditors($authors);
                } else {    
                    // Dynamic dispatch: property name → setter method
                    // e.g. "Title" → setTitle($value)
                    $strMethodName = 'set' . $propName;
                    if (method_exists($reference, $strMethodName)) {
                        $reference->{$strMethodName}($value);
                    } else {
                        error_log("refUpdateService: no setter '$strMethodName' on " . get_class($reference));
                    }
                }
            }
        }

        // -------------------------------------------------------------------------
        // Persist the updated collection and return the refreshed reference view
        // -------------------------------------------------------------------------

        $object->updateObject($collection);

        header('Content-Type:text/html; charset=UTF-8');
        echo $presentation->getAsTable();

    } else {
        header('Content-Type:text/html; charset=UTF-8');
        echo "<b>Could not create Reference object</b>";
    }

} catch (Exception $e) {
    // Note: rethrowing here produces an unformatted PHP error page.
    // Consider handling with error_log() + HTML echo, as in other services.
    throw $e;
}

?>