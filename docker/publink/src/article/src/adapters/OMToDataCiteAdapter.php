<?php

namespace Biblhertz\Article\Adapters;

use XmlWriter;
use DomDocument;
use Biblhertz\Publink\utilities\Logger;
use Biblhertz\Article\utilities\Utilities;
use Biblhertz\Article\Config;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Article\om\Article;

/********************************************************************/
/*      DataCite Adapter                                            */
/*                                                                  */
/*      Author  :   Chris Tomlinson                                 */
/*      Date    :   11th July 2023                                  */
/*                                                                  */
/*      Generate DataCite XML from a ReferenceCollection object     */
/*                                                                  */
/********************************************************************/

/**
 * Serialises the reference list of a PubLink {@see Article} to DataCite XML.
 *
 * Generates a {@code <relatedIdentifiers>} block conforming to the DataCite
 * Metadata Schema. Each reference in the article's {@see ReferenceCollection}
 * is mapped to a {@code <relatedIdentifier>} element with a "Cites" relation
 * type. The article's own DOI (or fallback CiteAs value) is additionally
 * written as an "IsPartOf" identifier at the top of the block.
 *
 * Output can be written directly to a file (when a URI is provided) or
 * returned as a string (when no URI is given).
 *
 * Identifier type resolution order for each reference:
 *   1. The reference's own {@code pubIdType} / {@code pubId}
 *   2. Fallback to ISSN if type is UNSET or URN and an ISSN is available
 *   3. Fallback to ISBN if type is UNSET or URN and an ISBN is available
 *   4. Fallback to URL  if type is UNSET or URN and a URL  is available
 *
 * Only identifier types present in {@see $allowedidtypes} are written;
 * references with no resolvable allowed identifier are silently skipped.
 *
 * @package  Biblhertz\Article\Adapters
 * @author   Chris Tomlinson
 * @since    2023-07-11
 */
class OMToDataCiteAdapter {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /**
     * The reference collection to serialise, sourced from the Article.
     *
     * @var ReferenceCollection
     */
    private ReferenceCollection $references;

    /**
     * XmlWriter instance used to build the DataCite XML output.
     *
     * @var XmlWriter
     */
    private XmlWriter $xmlWriter;

    /**
     * Filesystem URI for the output XML file.
     * An empty string signals in-memory (string return) mode.
     *
     * @var string
     */
    private string $uri = "";

    /**
     * Logger instance for recording adapter activity.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * The Article object model whose references are being serialised.
     *
     * @var Article
     */
    private Article $article;

    /**
     * Maps CSL/OJS publication type strings to DataCite resourceTypeGeneral values.
     *
     * @var array<string, string>
     */
    private array $conversions = [
        "article-journal"  => "JournalArticle",
        "book"             => "Book",
        "chapter"          => "BookChapter",
        "paper-conference" => "ConferencePaper",
    ];

    /**
     * Identifier types accepted by the DataCite schema.
     * References whose resolved type is not in this list are skipped.
     *
     * @var array<string>
     */
    private array $allowedidtypes = ["PMID", "DOI", "URN", "Handle", "ISSN", "ISBN", "URL"];


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Constructs the adapter for the given Article and optional output URI.
     *
     * The article's reference collection is extracted immediately and stored
     * for use during XML generation. If {@see $uri} is empty, output will be
     * returned as a string from {@see generateXML()}.
     *
     * @param Article $article The article whose references will be serialised
     * @param string  $uri     Optional filesystem path for the output XML file.
     *                         Omit or pass an empty string for in-memory mode.
     */
    public function __construct(Article $article, string $uri = "") {
        $this->article    = $article;
        $this->references = $article->getReferences();
        $this->uri        = $uri;
        $this->xmlWriter  = new XmlWriter();
    }


    /****************************************************************/
    /*  INTERFACE METHODS                                           */
    /****************************************************************/

    /**
     * Sets the Logger instance for recording adapter output.
     *
     * @param Logger $l Logger to use
     * @return void
     */
    public function setLogger(Logger $l): void {
        $this->logger = $l;
    }


    /****************************************************************/
    /*  OTHER METHODS                                               */
    /****************************************************************/

    /**
     * Generates the DataCite XML document.
     *
     * Behaviour depends on whether a URI was supplied at construction:
     * - **File mode** (`$uri` non-empty): writes XML directly to the file and
     *   returns nothing (flush writes to disk).
     * - **Memory mode** (`$uri` empty): builds XML in memory and returns it
     *   as a string.
     *
     * @return string|null DataCite XML string in memory mode; null in file mode.
     */
    public function generateXML(): string|null {
        if ($this->uri !== "") $this->xmlWriter->openUri($this->uri);
        else $this->xmlWriter->openMemory();

        $this->xmlWriter->startDocument();
        $this->xmlWriter->setIndent(true);
        $this->writeDataCite();
        $this->xmlWriter->endDocument();

        // File mode: flush to disk; memory mode: flush returns the XML string
        if ($this->uri !== "") {
            $this->xmlWriter->flush();
            return null;
        }
        return $this->xmlWriter->flush();
    }


    /**
     * Writes the {@code <relatedIdentifiers>} block to the XmlWriter.
     *
     * First writes the article's own DOI (or CiteAs fallback) as an
     * "IsPartOf" / "ConferencePaper" identifier. Then iterates over every
     * reference in the collection, resolving the best available identifier
     * type in the following priority order:
     *
     * 1. The reference's native pubIdType / pubId
     * 2. "OTHER" type is rewritten as "URN" with a "urn:" prefix
     * 3. ISSN fallback (if type is still UNSET or URN and the reference has one)
     * 4. ISBN fallback (if type is still UNSET or URN and the reference has one)
     * 5. URL  fallback (if type is still UNSET or URN and the reference has one)
     *
     * References whose resolved type is not in {@see $allowedidtypes}, or
     * whose identifier value is empty, are silently skipped.
     *
     * The publication type string is mapped through {@see $conversions} to a
     * DataCite resourceTypeGeneral value where a mapping exists.
     *
     * @return void
     */
    private function writeDataCite(): void {
        $this->xmlWriter->startElement("relatedIdentifiers");

        // Resolve the article's own identifier: prefer DOI, fall back to CiteAs
        $doi = $this->article->getDOI();
        if (empty($doi)) $doi = $this->article->getCiteAs();
        if (empty($doi)) $doi = "SET DOI HERE"; // Placeholder if neither is available

        $this->xmlWriter->startElement("relatedIdentifier");
        $this->xmlWriter->writeAttribute("relatedIdentifierType", "DOI");
        $this->xmlWriter->writeAttribute("relationType", "IsPartOf");
        $this->xmlWriter->writeAttribute("resourceTypeGeneral", "ConferencePaper");
        $this->xmlWriter->writeRaw($doi);
        $this->xmlWriter->endElement();

        // Iterate over each reference and resolve its best available identifier
        foreach ($this->references as $ref) {
            $type = strtoupper($ref->getPubIdType());
            $id   = $ref->getPubId();

            // "OTHER" is not a DataCite type; convert to URN with appropriate prefix
            if ($type === "OTHER") {
                $type = "URN";
                $id   = "urn:" . $id;
            }

            // Fallback chain: ISSN → ISBN → URL (only when type is still unresolved)
            if (($type === "UNSET" || $type === "URN") && method_exists($ref, 'getISSN')) {
                $issn = $ref->getISSN();
                if (!empty($issn)) { $type = "ISSN"; $id = $issn; }
            }

            if (($type === "UNSET" || $type === "URN") && method_exists($ref, 'getISBN')) {
                $isbn = $ref->getISBN();
                if (!empty($isbn)) { $type = "ISBN"; $id = $isbn; }
            }

            if (($type === "UNSET" || $type === "URN") && method_exists($ref, 'getURL')) {
                $url = $ref->getURL();
                if (!empty($url)) { $type = "URL"; $id = $url; }
            }

            // Normalise "HANDLE" casing to match DataCite's expected value
            if ($type === "HANDLE") $type = "Handle";

            // Only write the element if the identifier is non-empty and the type is allowed
            if (!empty($id) && in_array($type, $this->allowedidtypes)) {
                $this->xmlWriter->startElement("relatedIdentifier");
                $this->xmlWriter->writeAttribute("relatedIdentifierType", $type);
                $this->xmlWriter->writeAttribute("relationType", "Cites");

                // Map CSL publication type to DataCite resourceTypeGeneral if a mapping exists
                $ptype = $ref->getPublicationType();
                if (isset($this->conversions[$ptype])) $ptype = $this->conversions[$ptype];
                $this->xmlWriter->writeAttribute("resourceTypeGeneral", $ptype);

                $this->xmlWriter->writeRaw($id);
                $this->xmlWriter->endElement();
            }
        }

        $this->xmlWriter->endElement(); // </relatedIdentifiers>
    }
}

?>