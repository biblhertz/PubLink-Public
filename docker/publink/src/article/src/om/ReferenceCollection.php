<?php

namespace Biblhertz\Article\om;

use Biblhertz\Article\om\Reference;

/**
 * ReferenceCollection
 *
 * A type-safe, keyed collection of {@see Reference} objects. Extends PHP's
 * built-in {@see \ArrayObject} and enforces two uniqueness constraints on all
 * items added via {@see offsetSet()}:
 *
 * - **Type** — only {@see Reference} instances (or subclasses) are accepted;
 *   all other types throw an {@see \InvalidArgumentException}.
 * - **Pub ID** — duplicate public identifiers (DOI, PMID, etc.) are rejected
 *   with an {@see \InvalidArgumentException}.
 * - **Label** — duplicate citation labels are silently resolved by replacing
 *   the incoming label with a `uniqid()` value rather than throwing.
 *
 * The collection is keyed by the reference's label string. Items can be
 * retrieved by label via {@see getReferenceFromKey()} or {@see getByLabel()},
 * and the collection can be sorted alphabetically by label via
 * {@see sortByLabel()}.
 *
 * Used throughout the PubLink system as the standard container for reference
 * lists on {@see Article} objects and for returning candidate references from
 * API adapters ({@see CrossRefAdapter}, {@see GoogleBooksAdapter},
 * {@see PrimoAPIAdapter}).
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class ReferenceCollection extends \ArrayObject {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * Whether this object may be edited via the GUI.
     * Editing is disabled for collections; individual references manage their
     * own edit permissions.
     *
     * @var bool
     */
    public static bool $ALLOW_EDIT = false;

    /**
     * Whether an external API reference check has been performed on this
     * collection. Set to `true` after adapters have stored their results
     * via {@see Reference::setRefCheck()}.
     *
     * @var bool
     */
    private bool $referenceCheck = false;

    /**
     * Whether this collection is read-only. When `true`, modifications
     * should be prevented by calling code.
     *
     * @var bool
     */
    private bool $readOnly = false;


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Get the reference-check flag.
     *
     * @return bool  `true` if an API reference check has been performed.
     */
    public function getReferenceCheck(): bool {
        return $this->referenceCheck;
    }

    /**
     * Set the reference-check flag.
     *
     * @param  bool $b  `true` to mark the collection as checked.
     * @return void
     */
    public function setReferenceCheck(bool $b): void {
        $this->referenceCheck = $b;
    }

    /**
     * Get the read-only flag.
     *
     * @return bool  `true` if the collection is read-only.
     */
    public function getReadOnly(): bool {
        return $this->readOnly;
    }

    /**
     * Check whether the collection is read-only.
     * Alias for {@see getReadOnly()} provided for semantic clarity.
     *
     * @return bool  `true` if the collection is read-only.
     */
    public function isReadOnly(): bool {
        return $this->readOnly;
    }

    /**
     * Set the read-only flag.
     *
     * @param  bool $b  `true` to mark the collection as read-only.
     * @return void
     */
    public function setReadOnly(bool $b): void {
        $this->readOnly = $b;
    }

    /**
     * Return this collection itself.
     *
     * Provided for interface consistency in contexts where a method returning
     * a {@see ReferenceCollection} is expected on both {@see Article} and
     * {@see ReferenceCollection}.
     *
     * @return ReferenceCollection  This instance.
     */
    public function getReferences(): ReferenceCollection {
        return $this;
    }

    /**
     * Return the number of references in the collection.
     *
     * @return int  Reference count.
     */
    public function getNumber(): int {
        return count($this);
    }

    /**
     * Retrieve a reference by its citation label.
     *
     * Performs a linear search using strict string comparison.
     *
     * @param  string $key  The citation label to search for.
     * @return Reference|false  The matching reference, or `false` if not found.
     */
    public function getReferenceFromKey(string $key): Reference|false {
        foreach ($this as $ref) {
            if ($ref->getLabel() === $key) return $ref;
        }
        return false;
    }

    /**
     * Retrieve a reference by its citation label.
     * Alias for {@see getReferenceFromKey()}.
     *
     * @param  string $key  The citation label to search for.
     * @return Reference|false  The matching reference, or `false` if not found.
     */
    public function getByLabel(string $key): Reference|false {
        return $this->getReferenceFromKey($key);
    }


    /****************************************************************/
    /* SORTING                                                      */
    /****************************************************************/

    /**
     * Comparator for sorting references by citation label.
     *
     * Returns -1, 0, or 1 based on a string comparison of the two labels.
     * Used as the callback for {@see sortByLabel()}.
     *
     * @param  Reference $a  First reference.
     * @param  Reference $b  Second reference.
     * @return int           Negative, zero, or positive.
     */
    /**
     * Sort the collection in place by citation label (ascending alphabetical).
     *
     * Uses {@see \ArrayObject::uasort()} with a closure comparator,
     * preserving array keys.
     *
     * @return void
     */
    public function sortByLabel(): void {
        $this->uasort(fn(Reference $a, Reference $b) => $a->getLabel() <=> $b->getLabel());
    }


    /****************************************************************/
    /* COLLECTION CONSTRAINT METHODS                                */
    /****************************************************************/

    /**
     * Add a reference to the collection at the given index.
     *
     * Overrides {@see \ArrayObject::offsetSet()} to enforce three constraints:
     * 1. **Type check** — `$newval` must be a {@see Reference} instance;
     *    throws {@see \InvalidArgumentException} otherwise.
     * 2. **Pub ID uniqueness** — throws {@see \InvalidArgumentException} if
     *    the reference's pub ID already exists in the collection. Note: empty
     *    pub IDs (e.g. references without a DOI) are not checked.
     * 3. **Label uniqueness** — if the label already exists, it is silently
     *    replaced with a `uniqid()` value rather than throwing.
     *
     * @param  mixed $index   The key to store the reference under.
     * @param  mixed $newval  The value to add; must be a {@see Reference}.
     * @return void
     *
     * @throws \InvalidArgumentException  If `$newval` is not a {@see Reference}
     *                                    or if its pub ID already exists in the
     *                                    collection.
     */
    public function offsetSet(mixed $index, mixed $newval): void {
        if (!is_a($newval, "Biblhertz\Article\om\Reference", true)) {
            $class = get_class($newval);
            throw new \InvalidArgumentException(
                "Error: Argument of type $class added to ReferenceCollection is not a Reference"
            );
        }

        if ($this->pubIdExists($newval->getPubId())) {
            return;
        }

        // Duplicate labels are resolved silently rather than rejected
        if ($this->labelExists($newval->getLabel())) {
            $newval->setLabel(uniqid());
        }

        parent::offsetSet($index, $newval);
    }

    /**
     * Check whether a given value already exists in the collection, matched
     * against either the pub ID or the label of each reference.
     *
     * Empty values are never considered to match, preventing false positives
     * when references lack a pub ID.
     *
     * @param  string $value  The value to search for.
     * @param  bool   $pub    `true` to match against pub IDs; `false` for labels.
     * @return bool           `true` if a match is found, `false` otherwise.
     */
    public function exists(string $value, bool $pub): bool {
        foreach ($this as $ref) {
            $pid = $pub ? $ref->getPubId() : $ref->getLabel();
            if (!empty($pid) && $value === $pid) return true;
        }
        return false;
    }

    /**
     * Check whether a pub ID already exists in the collection.
     * Shortcut for `exists($value, true)`.
     *
     * @param  string $value  The pub ID to search for.
     * @return bool           `true` if the pub ID is already present.
     */
    public function pubIdExists(string $value): bool {
        return $this->exists($value, true);
    }

    /**
     * Check whether a citation label already exists in the collection.
     * Shortcut for `exists($value, false)`.
     *
     * @param  string $value  The label to search for.
     * @return bool           `true` if the label is already present.
     */
    public function labelExists(string $value): bool {
        return $this->exists($value, false);
    }
}
?>