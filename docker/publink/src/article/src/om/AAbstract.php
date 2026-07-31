<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\Paragraph;

/**
 * AAbstract
 *
 * Represents the abstract section of an academic article, composed of one or
 * more {@see Paragraph} objects. Extends {@see ArticleObject}.
 *
 * The class name uses the `AAbstract` prefix to avoid collision with PHP's
 * reserved keyword `abstract`.
 *
 * Paragraphs are stored in insertion order and can be retrieved as a plain
 * array, looked up individually by their JATS ID, or serialised to an
 * HTML-escaped string for display.
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     7th June 2024
 */
class AAbstract extends ArticleObject {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * Ordered collection of {@see Paragraph} objects that make up this abstract.
     * Paragraphs are appended via {@see addParagraph()} and retrieved via
     * {@see getParagraphs()} or {@see getParagraphfromKey()}.
     *
     * @var Paragraph[]
     */
    private array $paragraphCollection = [];

    /**
     * Whether this object may be edited via the GUI.
     * When `true`, the front-end renders editing controls for this abstract.
     *
     * @var bool
     */
    public static bool $ALLOW_EDIT = true;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Default constructor. No initialisation required beyond the empty
     * paragraph collection defined in the property declaration.
     */
    public function __construct() {
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Append a paragraph to the abstract.
     *
     * Paragraphs are stored in the order they are added and will appear in
     * that order when retrieved via {@see getParagraphs()} or
     * {@see getAsText()}.
     *
     * @param  Paragraph $p  The paragraph to append.
     *
     * @return void
     */
    public function addParagraph(Paragraph $p): void {
        $this->paragraphCollection[] = $p;
    }

    /**
     * Return all paragraphs in this abstract.
     *
     * @return Paragraph[]  Ordered array of {@see Paragraph} objects.
     */
    public function getParagraphs(): array {
        return $this->paragraphCollection;
    }

    /**
     * Serialise all paragraphs to an HTML-escaped string.
     *
     * Each paragraph's text is wrapped in HTML-escaped `<p>` tags
     * (i.e. `&lt;p&gt;...&lt;/p&gt;`) and straight double-quotes within the
     * text are replaced with the `&quot;` entity, making the output safe
     * for embedding in HTML attribute values or plain-text contexts.
     *
     * @return string  Concatenated, HTML-escaped paragraph strings.
     */
    public function getAsText(): string {
        $str = "";
        foreach ($this->paragraphCollection as $para) {
            $str .= "&lt;p&gt;" . str_replace('"', '&quot;', $para->getText()) . "&lt;/p&gt;";
        }
        return $str;
    }

    /**
     * Retrieve a paragraph by its JATS ID.
     *
     * Performs a linear search over the paragraph collection, comparing each
     * paragraph's JATS ID against the supplied key using a strict string
     * comparison.
     *
     * @param  string $key  The JATS ID of the paragraph to find.
     *
     * @return Paragraph|false  The matching {@see Paragraph}, or `false` if
     *                          no paragraph with that JATS ID exists.
     */
    public function getParagraphfromKey(string $key): mixed {
        foreach ($this->paragraphCollection as $para) {
            if ($para->getJatsID() === $key) {
                return $para;
            }
        }
        return false;
    }
}
?>