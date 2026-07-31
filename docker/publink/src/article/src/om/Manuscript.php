<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\Reference;
use Biblhertz\Publink\utilities\Utilities;
use XmlWriter;
use SimpleXMLElement;

/**
 * Manuscript
 *
 * Represents an unpublished manuscript reference within the PubLink article
 * model. Extends {@see JournalArticle}, overriding the publication type to
 * `"manuscript"` and BibTeX type to `"unpublished"`.
 *
 * Manuscripts often lack complete bibliographic data, so the JATS parsing
 * logic in {@see createFromJatsXMLFragment()} uses a fallback chain to extract
 * the best available title and year from whichever elements are present.
 *
 * JATS XML export delegates entirely to {@see JournalArticle::getJATSReference()},
 * producing an `<element-citation publication-type="manuscript">` block.
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class Manuscript extends JournalArticle {

    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a Manuscript reference.
     *
     * Calls the parent {@see JournalArticle} constructor and overrides the
     * publication type to `"manuscript"` and BibTeX type to `"unpublished"`.
     */
    public function __construct() {
        parent::__construct();
        $this->setPublicationType("manuscript");
        $this->setBibtexType("unpublished");
    }


    /****************************************************************/
    /* INHERITED METHODS                                            */
    /****************************************************************/

    /**
     * Populate this Manuscript from a JATS XML `<ref>` citation fragment.
     *
     * Uses a fallback chain for the title, since manuscripts may lack an
     * `<article-title>` element:
     * 1. `<article-title>` — preferred title element
     * 2. `<source>`        — used as title if `<article-title>` is absent
     * 3. `<pub-id>`        — used as title if both above are absent
     *
     * Year is similarly resolved via a fallback chain:
     * 1. `<year>`                        — standard year element
     * 2. `<date-in-citation><year>`      — used if top-level `<year>` is absent
     *                                      (common in citation database exports)
     *
     * All values are trimmed and passed through {@see Utilities::to_utf()} for
     * UTF-8 normalisation.
     *
     * @param  SimpleXMLElement $xml  The JATS citation XML fragment to parse.
     * @return void
     */
    public function createFromJatsXMLFragment(SimpleXMLElement $xml): void {
        // Title fallback chain: article-title → source → pub-id
        $title = "";
        if (isset($xml->{'article-title'})) {
            $title = trim(Utilities::to_utf(self::innerText($xml->{'article-title'})));
        } elseif (isset($xml->{'source'})) {
            $title = trim(Utilities::to_utf(self::innerText($xml->{'source'})));
        } elseif (isset($xml->{'pub-id'})) {
            $title = trim(Utilities::to_utf((string) $xml->{'pub-id'}));
        } elseif (isset($xml->{'italic'})) {
            $title = trim(Utilities::to_utf(self::innerText($xml->{'italic'})));
        }
        $this->setTitle($title);

        // Year fallback chain: year → date-in-citation/year
        $year = "";
        if (isset($xml->{'year'})) {
            $year = Utilities::to_utf((string) $xml->{'year'});
        } elseif (isset($xml->{'date-in-citation'}->{'year'})) {
            $year = Utilities::to_utf((string) $xml->{'date-in-citation'}->{'year'});
        }
        $this->setYear($year);
    }

    /**
     * Return the CrossRef API filter string for manuscript queries.
     *
     * Maps to the CrossRef `monograph` type, which is the closest available
     * type for unpublished or pre-publication works.
     *
     * @return string  CrossRef type filter string.
     */
    public function getFilterType(): string {
        return "type:monograph";
    }

    /**
     * Serialise this Manuscript to a JATS `<element-citation>` XML block.
     *
     * Delegates entirely to {@see JournalArticle::getJATSReference()}, producing
     * an `<element-citation publication-type="manuscript">` block with the same
     * field structure as a journal article.
     *
     * @return string|true  Serialised XML string when writing standalone,
     *                      or `true` when appending to an existing XmlWriter.
     */
    public function getJATSReference(): string|true {
        return parent::getJATSReference();
    }
}
?>