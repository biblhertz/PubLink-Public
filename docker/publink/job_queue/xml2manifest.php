<?php
/**
 * JATS XML → IIIF Manifest job handler.
 *
 * Executed by the Beanstalkd worker via include(). Receives a JATS XML file
 * ID and manifest config fields from the job parameters, writes a temporary
 * config JSON, runs xml2manifest/xml_to_manifest_http.py, registers the output
 * manifest in the database, and marks the job as finished.
 *
 * @param  object  $objDB   Database connection/query object (injected by worker)
 * @param  object  $job     Job instance for status reporting (injected by worker)
 * @param  array   $params  Job parameters; must include:
 *                            - 'file_id'           (int)    ID of the source JATS XML file
 *                            - 'user_details_id'   (int)    ID of the owning user
 *                            - 'manifest_id'       (string) Canonical manifest URL
 *                            - 'base_canvas'       (string) Base canvas URL
 *                            - 'label_it'          (string) Italian manifest label
 *                            - 'label_en'          (string) English manifest label
 *                            - 'rights'            (string) Rights/licence URL
 *                            - 'required_stmt_it'  (string) Italian attribution statement
 *                            - 'required_stmt_en'  (string) English attribution statement
 *                          Optional:
 *                            - 'fetch_delay'       (float)  Delay between info.json fetches
 *                            - 'fallback_width'    (int)    Canvas width when info.json unreachable
 *                            - 'fallback_height'   (int)    Canvas height when info.json unreachable
 * @param  object  $logger  Logger instance (injected by worker)
 *
 * @throws Exception  Re-thrown after marking the job as FAILED and logging the error.
 */

require 'vendor/autoload.php';

use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\pages\htmlPage;

try {
    $user = new User($objDB, $params['user_details_id']);
    $file = new File($objDB, $params['file_id']);
    $logger->print("Source   :: " . $file->getName());
    $logger->print("Manifest :: " . $params['manifest_id']);

    // Derive the output filename from the canonical manifest_id URL supplied in the form.
    $manifestName = basename(parse_url($params['manifest_id'], PHP_URL_PATH));
    if (!$manifestName || pathinfo($manifestName, PATHINFO_EXTENSION) !== 'json') {
        $manifestName = $file->getFileNameWithoutExtension() . "_manifest.json";
    }
    $outputFilePath = $user->getMyFileStoreDirectoryPath() . DIRECTORY_SEPARATOR . $manifestName;

    // If a file with this path already exists, remove it from disk and from the
    // file table so the new run starts clean and leaves no orphaned DB records.
    if (file_exists($outputFilePath)) {
        $existing = $objDB->preparedSelect(
            "SELECT id FROM file WHERE path = ? AND user_details_id = ? LIMIT 1",
            [$outputFilePath, $params['user_details_id']]
        )->fetch();
        if ($existing) {
            $objDB->preparedStatement("DELETE FROM file WHERE id = ?", [$existing['id']]);
        }
        @unlink($outputFilePath);
        $logger->print("xml2manifest :: removed existing output file: $outputFilePath");
    }

    // Assemble the manifest config from job parameters.
    $configData = [
        'manifest_id'      => $params['manifest_id'],
        'base_canvas'      => $params['base_canvas'],
        'label_it'         => $params['label_it'],
        'label_en'         => $params['label_en'],
        'rights'           => $params['rights'],
        'required_stmt_it' => $params['required_stmt_it'],
        'required_stmt_en' => $params['required_stmt_en'],
    ];

    foreach (['fetch_delay', 'fallback_width', 'fallback_height'] as $key) {
        if (isset($params[$key])) $configData[$key] = $params[$key];
    }

    if (!empty($params['force_http_hosts'])) {
        $configData['force_http_hosts'] = $params['force_http_hosts'];
    }

    // Write config to a temporary file so the Python script can read it.
    $configPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manifest_cfg_' . uniqid() . '.json';
    file_put_contents($configPath, json_encode($configData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // Locate the Python script relative to the job queue directory.
    $pythonScript = Config::$JOB_QUEUE_DIR
        . DIRECTORY_SEPARATOR . 'xml2manifest'
        . DIRECTORY_SEPARATOR . 'xml_to_manifest_http.py';

    $cmd = 'python3 '
        . escapeshellarg($pythonScript)        . ' '
        . escapeshellarg($file->getPath())     . ' '
        . escapeshellarg($outputFilePath)      . ' '
        . escapeshellarg($configPath)          . ' 2>&1';

    $output     = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);

    $logger->print("xml_to_manifest_http.py :: " . implode("\n", $output));

    // Clean up the temporary config regardless of outcome.
    @unlink($configPath);

    if ($returnCode !== 0) {
        throw new Exception(
            "xml_to_manifest_http.py exited with code $returnCode: " . implode("\n", $output)
        );
    }

    if (!file_exists($outputFilePath)) {
        throw new Exception(
            "xml_to_manifest_http.py completed but output file was not created: $outputFilePath"
        );
    }

    // Register the manifest JSON file in the database.
    $vals = [
        'name'            => basename($outputFilePath),
        'size'            => filesize($outputFilePath),
        'type'            => 'application/json',
        'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
        'user_details_id' => $params['user_details_id'],
        'path'            => $outputFilePath,
    ];

    $typeResult = $objDB->preparedSelect("SELECT id FROM file_type WHERE name = ?", ["json"]);
    $type = $typeResult->fetch();
    if ($type) {
        $vals['file_type_id'] = $type['id'];
    } else {
        $logger->print("!!! File type 'json' is not registered in file_type table :: $outputFilePath");
    }

    $id = $objDB->insert("file", $vals);
    $job->setOutputFileID($id);
    $logger->print("Output   :: " . $outputFilePath);
    $job->updateStatus("FINISHED");
    $logger->writeOutUserLogFile("xml2manifest", $user);

} catch (Exception $e) {
    $job->updateStatus("FAILED");
    $logger->print("!!! Unhandled exception in xml2manifest job :: " . $e->getMessage());
    throw $e;
}
?>
