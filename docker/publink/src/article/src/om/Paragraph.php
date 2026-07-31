<?php

namespace Biblhertz\Article\om;

/**
 * Paragraph
 *
 * Represents a single paragraph of text within an {@see AAbstract}.
 * Extends {@see ArticleObject}.
 *
 * Paragraphs are simple value objects wrapping a text string. The text may
 * be provided at construction or set later via {@see setText()}. Paragraphs
 * are identified within their parent abstract by the JATS ID inherited from
 * {@see ArticleObject}, which can be used for keyed lookup via
 * {@see AAbstract::getParagraphfromKey()}.
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     7th June 2024
 */
class Paragraph extends ArticleObject {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * The paragraph text content.
     * May contain inline HTML or plain text depending on the import source.
     *
     * @var string
     */
    private string $text;

    /**
     * Whether this object may be edited via the GUI.
     *
     * @var bool
     */
    public static bool $ALLOW_EDIT = true;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialise a Paragraph, optionally with an initial text value.
     *
     * @param  string $text  Initial paragraph text. Defaults to an empty string.
     */
    public function __construct(string $text = "") {
        $this->text = $text;
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the paragraph text content.
     *
     * @param  string $s  Paragraph text.
     * @return void
     */
    public function setText(string $s): void {
        $this->text = $s;
    }

    /**
     * Get the paragraph text content.
     *
     * @return string
     */
    public function getText(): string {
        return $this->text;
    }
}
?>