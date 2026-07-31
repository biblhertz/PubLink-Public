<?php

namespace Biblhertz\Article\om;

/**
 * Keyword
 *
 * Represents a single keyword associated with an {@see Article}.
 * Extends {@see ArticleObject}.
 *
 * Keywords are simple value objects holding a name string. A unique JATS ID
 * is assigned automatically on construction via `uniqid()`, allowing keywords
 * to be managed by JATS ID within article keyword collections.
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class Keyword extends ArticleObject {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string The keyword text. */
    private string $name = "";

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
     * Initialise a Keyword with a unique JATS ID.
     */
    public function __construct() {
        $this->setJatsID(uniqid());
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the keyword text.
     *
     * @param  string $s  Keyword string.
     * @return void
     */
    public function setName(string $s): void {
        $this->name = $s;
    }

    /**
     * Get the keyword text.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }
}
?>