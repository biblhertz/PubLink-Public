<?php

namespace Biblhertz\Article\om;

/**
 * ArticleObject
 *
 * Abstract superclass for all domain objects in the PubLink article model.
 *
 * Provides two foundational capabilities shared across every object in the
 * hierarchy ({@see Article}, {@see Reference}, {@see Author}, {@see Affiliation},
 * {@see GalleyFile}, etc.):
 *
 * - **JATS ID** — a unique string identifier used to cross-reference objects
 *   within a JATS XML document and as collection keys throughout the model.
 * - **Disallowed fields** — a list of property names that should be excluded
 *   from GUI field-selection interfaces (e.g. reference matching dropdowns).
 *   Subclasses populate this list in their constructors as needed.
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class ArticleObject {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * Unique identifier for this object within a JATS XML document.
     * Used as a collection key and for cross-referencing between objects
     * (e.g. linking authors to their affiliations).
     *
     * @var string
     */
    private string $jatsID = "";

    /**
     * List of property names excluded from GUI field-selection interfaces.
     *
     * Subclasses populate this array in their constructors to prevent
     * internal or structural fields (e.g. raw XML chunks, flags) from
     * appearing as selectable options in reference-matching or editing UIs.
     *
     * @var string[]
     */
    protected array $disallowedFields = [];


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Get the JATS ID for this object.
     *
     * @return string  The JATS ID, or an empty string if not yet assigned.
     */
    public function getJatsID(): string {
        return $this->jatsID;
    }

    /**
     * Set the JATS ID for this object.
     *
     * Should be unique within the context of a single JATS document.
     * Subclasses typically assign this via `uniqid()` in their constructor,
     * or map it from an existing `id` attribute during XML import.
     *
     * @param  string $id  The JATS ID to assign.
     * @return void
     */
    public function setJatsID(string $id): void {
        $this->jatsID = $id;
    }

    /**
     * Return the list of field names disallowed from GUI field-selection.
     *
     * @return string[]  Array of property name strings.
     */
    public function getDisallowedFields(): array {
        return $this->disallowedFields;
    }
}
?>