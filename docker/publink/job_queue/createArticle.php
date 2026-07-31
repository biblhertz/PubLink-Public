<?php
/**
 * Converts one or more JATS XML files into serialized Article object model (OM) records
 * and stores them in the database, then marks the job as finished.
 *
 * This handler is executed as a scheduled job. For each input file it:
 *   1. Copies the JATS XML into a temporary working directory in the user's file store
 *   2. Validates the file against the JATS XML schema
 *   3. Converts the JATS XML to a PubLink Article object model via {@see JATSToOMAdapter}
 *   4. Serializes and stores the Article in the `serialized_object` table
 *   5. Removes the temporary working directory
 *
 * Optional parameters allow associating OJS user credentials, a cover image, and galley
 * files with the converted article.
 *
 * @param  object  $objDB   Database connection/query object
 * @param  object  $job     The scheduled job instance; used to update status at each stage
 * @param  array   $params  Job parameters; must include:
 *                            - 'files'           (string)   Serialized array of file IDs to process
 *                            - 'user_details_id' (int)      ID of the user who owns the job
 *                          Optional:
 *                            - 'ojs_user'        (string)   OJS username to associate with the import
 *                            - 'cover_file'      (int)      File ID of a cover image to attach
 *                            - 'galley_files'    (int[])    Array of file IDs for galley attachments
 *
 * @throws Exception  If JATS validation fails, or if the temporary working directory
 *                    cannot be removed after processing. Re-throws any other unexpected
 *                    exception after marking the job as FAILED and logging the error.
 *
 * @note  A unique temporary directory is created per file under the user's file store path.
 *        It is removed at the end of each iteration; if removal fails, an exception is thrown
 *        and remaining files in the loop will not be processed.
 *
 * @note  CrossRef reference enrichment is implemented but currently commented out. When
 *        re-enabled, it will attempt DOI and title lookups for each article reference via
 *        the CrossRef API, with per-reference exception handling to allow partial failures.
 *
 * @note  $user is instantiated inside the loop but $params['user_details_id'] is constant
 *        across iterations. Consider moving it before the foreach to avoid redundant DB calls.
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Article\adapters\JATSToOMAdapter;
use Biblhertz\Article\adapters\JATSXMLValidator;
use Biblhertz\Article\om\Article;
use Biblhertz\Publink\utilities\Logger;
use GuzzleHttp\Client;
use RenanBr\CrossRefClient;
use Biblhertz\Article\om\Reference;

try {
    //$logger = new Logger();

    // Load the user inside the loop as $params['user_details_id'] is constant —
    // consider moving this before the foreach to avoid redundant DB calls.
    $user = new User($objDB, $params['user_details_id']);

    // Deserialize the list of input file IDs provided by the job dispatcher.
    $files = unserialize($params['files']);

    foreach ($files as $f) {
        $file = new File($objDB, $f);
        $path = $file->getPath();
   

        // Create a unique temporary working directory in the user's file store.
        // The source file is copied here so validation and conversion work on an
        // isolated copy, leaving the original file untouched.
        $myPath         = $user->getMyFileStoreDirectory() . uniqid() . DIRECTORY_SEPARATOR;
        $fname          = pathinfo($path, PATHINFO_FILENAME) . "." . pathinfo($path, PATHINFO_EXTENSION);
        mkdir($myPath);
        $movedfilepath  = $myPath . $fname;
        copy($path, $movedfilepath);

        $logger->print("Processing :: " . $file->getName());
        $logger->print("User       :: " . $user->getName());
        $logger->print("Work dir   :: " . $myPath);

        // Validate the copied file against the JATS XML schema before attempting conversion.
        // Throws if the document is structurally invalid; the temp directory is NOT cleaned
        // up in this case, so the file remains available for manual inspection.
        $validator = new JATSXMLValidator();
        $validator->setLogger($logger);
        $valid = $validator->validateJATSXML($movedfilepath);

        if (!$valid) {
            throw new Exception(
                "createArticle.php :: Document is not valid JATS XML; it has failed validation against the JATS schema"
            );
        }

        // Configure the JATS-to-OM adapter with the working directory and source file path.
        // setInputDir() provides the base directory for resolving relative asset references.
        // setJATSXMLPath() points to the original path, not the copied file — this may be
        // intentional if the adapter resolves assets relative to $myPath but reads XML from $path.
        $jatstoOM = new JATSToOMAdapter();
        $jatstoOM->setLogger($logger);
        $jatstoOM->setInputDir($myPath);
        $jatstoOM->setJATSXMLPath($path);

        // Attach optional OJS user, cover image, and galley files if provided.
        if (isset($params['ojs_user'])) {
            $jatstoOM->setOJSUser($params['ojs_user']);
        }

        if (isset($params['cover_file'])) {
            $jatstoOM->setCoverImageFile(new File($objDB, $params['cover_file']));
        }

        if (isset($params['galley_files'])) {
            foreach ($params['galley_files'] as $fid) {
                $jatstoOM->addGalleyFile(new File($objDB, $fid));
            }
        }

        // Run the conversion and retrieve the resulting Article object.
        $jatstoOM->generateObjectModel();
        $article = $jatstoOM->getArticle();

        /*
         * CrossRef reference enrichment — currently disabled.
         *
         * When re-enabled, this block iterates over the article's references and attempts
         * to enrich each one via the CrossRef API:
         *   - References with a DOI are looked up directly via getCrossRefFromPubID()
         *   - All others are looked up by title via getCrossRefFromTitle()
         * Exceptions from individual lookups are caught and logged so a single failed
         * reference does not abort the entire import.
         *
         * $crossref = Reference::$CROSSREF_API_ADDRESS;
         * $client1  = new Client(['base_uri' => $crossref]);
         * $client2  = new CrossRefClient();
         * error_log("Started Reference Check");
         * foreach ($article->getReferences() as $ref) {
         *     try {
         *         if (!strcmp($ref->getPubIdType(), "doi")) {
         *             $ref->getCrossRefFromPubID($logger, $client1);
         *         } else {
         *             $ref->getCrossRefFromTitle($logger, $client1, $client2);
         *         }
         *     } catch (Exception $e) {
         *         $logger->print($e->getMessage());
         *         continue;
         *     }
         * }
         * error_log("Completed Reference Check");
         */

        // Serialize the Article object to a base64-encoded string for safe database storage.
        $serializedArticle = base64_encode(serialize($article));

        // Persist the serialized Article to the database, linked to the source file and user.
        $vals = [
            'name'            => $file->getFileNameWithoutExtension() . "_ARTICLE_" . uniqid(),
            'object'          => $serializedArticle,
            'type'            => 'Article',
            'user_details_id' => $user->getID(),
            'timestamp'       => date('Y-m-d H:i:s'),
            'file_id'         => $file->getID(),
        ];
        $objDB->insert("serialized_object", $vals);
        $logger->print("Stored     :: " . $vals['name']);

        // Remove the temporary working directory now that the article has been stored.
        // Failure to remove is treated as a hard error to prevent orphaned directories
        // accumulating in the user's file store.
        $job->updateStatus("Removing :: " . $myPath);
        $removed = File::deleteDirectory($myPath);
        $logger->print("Removed    :: " . $myPath);

        if (!$removed) {
            throw new Exception(
                "!!! There was a problem removing the temporary directory $myPath in createArticle.php"
            );
        }
    }

    $job->updateStatus("FINISHED");
    $logger->writeOutUserLogFile("JATSToOM", $user);

} catch (Exception $e) {
    // Mark the job as failed so the scheduler does not treat it as hung or pending.
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in createArticle job :: " . $e->getMessage());

    // Re-throw so the calling scheduler can perform its own error handling if needed.
    throw $e;
}
?>