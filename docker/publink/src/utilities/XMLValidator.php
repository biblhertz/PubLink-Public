<?php
namespace Biblhertz\Publink\utilities;

use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Publink\Config;
use DomDocument;

/********************************************************************/
/*      XML Validator                                               */
/*                                                                  */
/*      Author  :   Chris Tomlinson                                 */
/*      Date    :   12th January 2024                               */
/*                                                                  */
/*      Validates an XML document against an XSD schema file.       */
/*      Validation errors are recorded via the Logger utility.      */
/*                                                                  */
/********************************************************************/

/**
 * Validates an XML document against an XSD schema.
 *
 * This class loads an XML file from a given filesystem path and validates it
 * against a specified XSD schema file using PHP's DOMDocument::schemaValidate().
 * All validation activity and errors are written to a Logger instance.
 *
 * Typical usage:
 * ```php
 * $validator = new XMLValidator();
 * $validator->setLogger($logger);
 * $validator->setXSDPath('/path/to/schema.xsd');
 * $validator->setTargetPath('/path/to/document.xml');
 * $isValid = $validator->validateXML();
 * ```
 *
 * @package Biblhertz\Publink\utilities
 * @author  Chris Tomlinson
 * @since   2024-01-12
 */
class XMLValidator {

    /**
     * Logger instance for recording validation progress and errors.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Absolute filesystem path to the XSD schema file.
     *
     * @var string
     */
    private string $xsdPath;

    /**
     * Absolute filesystem path to the XML document to be validated.
     *
     * @var string
     */
    private string $targetPath;

    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Constructs a new XMLValidator instance.
     *
     * Dependencies (logger, XSD path, target path) must be injected
     * via their respective setters before calling validateXML().
     */
    public function __construct(){
    }

    /**
     * Sets the Logger instance used to record validation output.
     *
     * @param Logger $l The logger to write validation messages to.
     * @return void
     */
    public function setLogger(Logger $l): void {
        $this->logger = $l;
    }

    /**
     * Sets the filesystem path to the XSD schema file.
     *
     * @param string $xsd Absolute path to the XSD schema file.
     * @return void
     */
    public function setXSDPath(string $xsd): void {
        $this->xsdPath = $xsd;
    }

    /**
     * Sets the filesystem path to the XML document to validate.
     *
     * @param string $target Absolute path to the XML document.
     * @return void
     */
    public function setTargetPath(string $target): void {
        $this->targetPath = $target;
    }

    /**
     * Validates the XML document at {@see $targetPath} against the XSD schema
     * at {@see $xsdPath}.
     *
     * Loads the XML file contents, parses them into a DOMDocument, and runs
     * schema validation. Internal libxml errors are captured and written to
     * the logger on failure, including the line number and message for each
     * error.
     *
     * Debug logging (document load confirmation and schema validation start)
     * is gated behind {@see Config::$SCHEDULER_DEBUG}.
     *
     * @return bool True if the XML document is valid against the schema,
     *              false if validation fails or an error occurs.
     */
    public function validateXML(): bool {

        // Load raw XML content from the target file
        $fcontent = file_get_contents($this->targetPath);

        // Parse the XML string into a DOM tree
        $doc = new DOMDocument();
        $doc->resolveExternals = true;  // needed to fetch the JATS DTD & entity files
        $doc->substituteEntities = true;

        // Suppress warnings during load (entity loading can be noisy)
        libxml_use_internal_errors(true);
        $doc->loadXML($fcontent, LIBXML_NOENT | LIBXML_DTDLOAD | LIBXML_DTDATTR);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
       
        if (Config::$SCHEDULER_DEBUG) {
            $this->logger->print("Loaded XML document from " . $this->targetPath);
            $this->logger->println();
            $this->logger->print("Starting general XML schema validation");
            $this->logger->println();
        }

        // Suppress libxml errors so they can be retrieved and logged manually
        libxml_use_internal_errors(true);

        $this->logger->println();
        $this->logger->print("Trying XML document :: " . $this->targetPath . " against :: " . $this->xsdPath);

        // Validate the document against the XSD schema
        $is_valid_xml = $doc->schemaValidate($this->xsdPath);

        if (!$is_valid_xml) {
            // Log each schema violation with its line number and message
            $this->logger->println();
            $this->logger->print("XML document :: " . $this->targetPath . " failed validation against :: " . $this->xsdPath);

            foreach (libxml_get_errors() as $err) {
                $this->logger->print("Line " . $err->line . " : " . $err->message);
            }

            // Clean up libxml error state
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            return false;

        } else {
            $this->logger->print("XML document :: " . $this->targetPath . " passed validation against " . $this->xsdPath);
            $this->logger->println();
            libxml_use_internal_errors(false);
            return true;
        }

        return false;
    }

}

?>