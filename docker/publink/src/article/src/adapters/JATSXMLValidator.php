<?php
namespace Biblhertz\Article\Adapters;

use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Publink\Config;
use DomDocument;

/**
 * JATSXMLValidator
 *
 * Validates a JATS XML document against one or more versioned JATS XSD schema
 * files registered in {@see Config::$JATS_XSD}.
 *
 * Validation is attempted against each registered schema version in turn.
 * The first successful validation short-circuits the loop and returns true.
 * If no schema matches, false is returned and per-version libxml errors are
 * written to the logger.
 *
 * Input can be supplied either as a filesystem path (default) or as a raw
 * XML string via {@see setXMLString()}. When an XML string is set it takes
 * precedence over the filename argument passed to {@see validateJATSXML()}.
 *
 * Typical usage (path-based):
 * ```php
 * $validator = new JATSXMLValidator($logger);
 * $isJats = $validator->validateJATSXML('/path/to/article.xml');
 * ```
 *
 * Typical usage (string-based):
 * ```php
 * $validator = new JATSXMLValidator($logger);
 * $validator->setXMLString($rawXmlString);
 * $isJats = $validator->validateJATSXML('');
 * ```
 *
 * @package  Biblhertz\Article\Adapters
 * @author   Chris Tomlinson
 * @since    11th July 2023
 */
class JATSXMLValidator {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var Logger Logger instance for validation progress and error output */
    private Logger $logger;

    /**
     * @var string Optional raw XML string to validate instead of reading from disk.
     *             When non-empty this takes precedence over the $filename argument
     *             in {@see validateJATSXML()}. Defaults to empty string (unused).
     */
    private string $xmlString = "";

    /**
     * @param Logger $logger Logger instance for validation progress and error output
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }


    /****************************************************************/
    /*  ACCESSOR METHODS                                            */
    /****************************************************************/

    /**
     * Supplies a raw XML string to validate instead of reading from a file path.
     *
     * When set to a non-empty string, {@see validateJATSXML()} will use this
     * content rather than loading from $filename.
     *
     * @param string $s Raw XML document string
     */
    public function setXMLString(string $s): void {
        $this->xmlString = $s;
    }


    /****************************************************************/
    /*  VALIDATION METHODS                                          */
    /****************************************************************/

    /**
     * Validates an XML document against all registered JATS XSD schema versions.
     *
     * Loads the XML from $filename (or from $this->xmlString if set), then
     * iterates over {@see Config::$JATS_XSD} — an associative array of
     * version => XSD path entries — trying each schema in turn. Returns true
     * as soon as any schema validates successfully. If all schemas fail, returns
     * false.
     *
     * libxml internal error handling is enabled before each schema attempt and
     * disabled immediately after to avoid interfering with other XML operations
     * elsewhere in the application. Per-line libxml errors are written to the
     * logger on failure.
     *
     * Debug logging (schema load confirmation and start message) is gated on
     * {@see Config::$SCHEDULER_DEBUG} to avoid noise in production.
     *
     * @param string $filename Absolute path to the JATS XML file to validate.
     *                         Ignored if {@see setXMLString()} has been called
     *                         with a non-empty string.
     * @return bool            True if the document validates against at least one
     *                         registered JATS schema version; false otherwise
     *
     */
    public function validateJATSXML(string $filename): bool {

        // Load raw XML content from the target file
        if (!empty($this->xmlString)) {
            $fcontent = $this->xmlString;
        } else {
            $raw = file_get_contents($filename);
            if ($raw === false) {
                $this->logger->print("Failed to read file: $filename");
                return false;
            }
            $fcontent = ltrim($raw, "\xEF\xBB\xBF");
        }

        // Parse the XML string into a DOM tree
        $doc = new DOMDocument();
        $doc->resolveExternals    = true;
        $doc->substituteEntities  = true;

        // First attempt: load with DTD so entity substitution and DTD validation work
        libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($fcontent, LIBXML_DTDLOAD | LIBXML_DTDATTR);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        // If DTD loading failed (e.g. missing MathML entities, XHTML modules, ISO
        // character sets), retry without DTD loading. XSD validation does not require
        // the DTD, so this fallback still allows full schema validation to proceed.
        // Only log errors if the fallback also fails — that indicates a genuine XML
        // parse problem rather than an incomplete DTD installation.
        if ($loaded === false) {
            $doc2 = new DOMDocument();
            libxml_use_internal_errors(true);
            $loaded = $doc2->loadXML($fcontent);
            $fallbackErrors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors(false);

            if ($loaded !== false) {
                $doc = $doc2;
            } else {
                $this->logger->print("Failed to parse XML source: $filename");
                foreach ($fallbackErrors as $e) {
                    error_log("libxml [{$e->level}] line {$e->line}: {$e->message}");
                }
                return false;
            }
        }


        // Validate the loaded document
        $is_valid_xml = false;

        // Try DTD validation first if the document has a DOCTYPE
        if ($doc->doctype !== null) {
            $this->logger->print("Trying DTD validation :: $filename");
            
            libxml_use_internal_errors(true);
            $is_valid_xml = $doc->validate();
           

            //ignore predictable xmlns warning messages
            $ignorePatterns = [
                    'No declaration for attribute xmlns',   // namespace declarations
                    'No declaration for attribute xml:',    // xml:lang, xml:space etc.
                ];

            $dtdErrors = array_filter(libxml_get_errors(), function($err) use ($ignorePatterns) {
            foreach ($ignorePatterns as $pattern) {
                    if (str_contains($err->message, $pattern)) return false;
                }
                return true;
            });

            if (empty($dtdErrors)) {
                $is_valid_xml = true; // override — only xmlns warnings were found
            }

            libxml_clear_errors();
            libxml_use_internal_errors(false);


            if ($is_valid_xml) {
                $this->logger->print("XML document :: $filename passed DTD validation");
                $this->logger->printLn();
                return true;
            }

            // Log DTD errors but fall through to XSD as fallback
            $this->logger->print("DTD validation failed, trying XSD fallback");
            foreach ($dtdErrors as $err) {
                $this->logger->print("Line " . $err->line . " : " . $err->message);
            }
        }

        // Try each registered JATS schema version; return true on first match
        if (!$is_valid_xml) {
            foreach (Config::$JATS_XSD as $key => $value) {
                $this->logger->printLn();
                $this->logger->print("Trying XML document :: $filename against JATS V$key :: $value");
                error_log("Trying XML document :: $filename against JATS V$key :: $value");

                // Enable internal libxml error capture for this validation attempt
                libxml_use_internal_errors(true);
                $is_valid_xml = $doc->schemaValidate($value);

                if (!$is_valid_xml) {
                    $this->logger->printLn();
                    $this->logger->print("XML document :: $filename failed validation against JATS V$key :: $value");

                    // Log each libxml error with its line number for diagnostics
                    foreach (libxml_get_errors() as $err) {
                        $this->logger->print("Line " . $err->line . " : " . $err->message);
                    }

                    libxml_clear_errors();

                } else {
                    // Validation passed — log success and return immediately
                    $this->logger->print("XML document :: $filename passed validation against JATS V$key :: $value");
                    $this->logger->printLn();
                    libxml_clear_errors();
                    libxml_use_internal_errors(false);
                    return true;
                }
            }
        }


        libxml_use_internal_errors(false);
        return false;
    }
}