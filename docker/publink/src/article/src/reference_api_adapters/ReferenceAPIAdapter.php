<?php

namespace Biblhertz\Article\reference_api_adapters;

use Biblhertz\Article\om\Reference;
use Biblhertz\Article\om\ReferenceCollection;

/**
 * ReferenceAPIAdapter
 *
 * Abstract base class for all external reference resolution adapters.
 *
 * Each concrete adapter (e.g. {@see CrossRefAdapter}, {@see GoogleBooksAdapter},
 * {@see PrimoAPIAdapter}) targets a specific bibliographic API and is responsible
 * for implementing {@see resolve()} to retrieve matching reference data for the
 * {@see Reference} object assigned via {@see setReference()}.
 *
 * The static utility method {@see putReferenceinCollection()} is provided as a
 * convenience for subclasses that need to normalise a single {@see Reference}
 * or an existing {@see ReferenceCollection} into a consistent
 * {@see ReferenceCollection} return value.
 *
 * Typical usage:
 * ```php
 * $adapter = new CrossRefAdapter();
 * $adapter->setReference($reference);
 * $collection = $adapter->resolve();
 * ```
 *
 * @package  Biblhertz\Article\reference_api_adapters
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
abstract class ReferenceAPIAdapter {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * The reference object that this adapter will attempt to resolve.
     * Set before calling {@see resolve()} via {@see setReference()}.
     *
     * @var Reference
     */
    protected Reference $reference;


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the reference to be resolved by this adapter.
     *
     * Must be called before {@see resolve()}. The reference provides the
     * source data (title, authors, pub-id type, etc.) that the adapter uses
     * to query its target API.
     *
     * @param  Reference $r  The reference object to resolve.
     *
     * @return void
     */
    public function setReference(Reference $r): void {
        $this->reference = $r;
    }

    /**
     * Get the reference currently assigned to this adapter.
     *
     * @return Reference  The reference object set via {@see setReference()}.
     */
    public function getReference(): Reference {
        return $this->reference;
    }


    /****************************************************************/
    /* ABSTRACT METHODS                                             */
    /****************************************************************/

    /**
     * Resolve the assigned reference against the adapter's external API.
     *
     * Implementations should query their respective API using data from
     * {@see $reference}, map the results to one or more {@see Reference}
     * objects, and return them — typically as a {@see ReferenceCollection}.
     *
     * Results should also be stored on the reference via
     * {@see Reference::setRefCheck()} so that callers can compare candidates
     * against the original.
     *
     * @return mixed  Typically a {@see ReferenceCollection} on success, or
     *                a string / null to signal no results or failure.
     */
    abstract public function resolve(): mixed;

    /**
     * Normalise a Reference or ReferenceCollection into a ReferenceCollection.
     *
     * Convenience utility for subclasses that may receive either a single
     * {@see Reference} or an already-assembled {@see ReferenceCollection} and
     * need to return a consistent type to the caller.
     *
     * - If `$ref` is already a {@see ReferenceCollection}, it is returned as-is.
     * - If `$ref` is a {@see Reference}, it is wrapped in a new
     *   {@see ReferenceCollection} keyed by its label.
     * - Otherwise, returns `false`.
     *
     * @param  mixed $ref  A {@see Reference}, {@see ReferenceCollection}, or
     *                     any other value.
     *
     * @return ReferenceCollection|false  The normalised collection, or `false`
     *                                    if `$ref` is neither a Reference nor a
     *                                    ReferenceCollection.
     */
    /**
     * Score the title similarity between two strings.
     *
     * Normalises both strings (lowercased, punctuation collapsed to spaces,
     * whitespace trimmed) then uses PHP's similar_text() to compute a
     * percentage similarity. Returns 0.0 if either input is empty.
     *
     * @param  string $a  Source title (from the original reference).
     * @param  string $b  Candidate title (from the API result).
     * @return float  Similarity percentage 0.0–100.0, rounded to one decimal place.
     */
    protected static function scoreTitleMatch(string $a, string $b): float {
        if (empty($a) || empty($b)) return 0.0;
        $normalize = fn(string $s): string => trim(preg_replace('/\s+/', ' ',
            preg_replace('/[^\w\s]/u', ' ', mb_strtolower($s))
        ));
        similar_text($normalize($a), $normalize($b), $pct);
        return round((float) $pct, 1);
    }

    /**
     * Compute a match score for an identifier-based lookup (DOI, PMID).
     *
     * Because the identifier is unambiguous, a successful lookup is always a
     * match. When both titles are present, text similarity is used so the score
     * reflects any normalisation differences. When the original reference has
     * no title (common for bare-identifier imports), 95.0 is returned to signal
     * a confirmed identifier match without a comparable title.
     *
     * Returns 0.0 only when the fetched reference also has no title, which
     * indicates the lookup itself failed or returned no usable data.
     *
     * @param  string $originalTitle  Title from the source reference (may be empty).
     * @param  string $fetchedTitle   Title returned by the API (empty if lookup failed).
     * @return float  Score in the range 0.0–100.0.
     */
    protected static function identifierMatchScore(string $originalTitle, string $fetchedTitle): float {
        if (empty($fetchedTitle)) return 0.0;          // lookup returned nothing useful
        $score = self::scoreTitleMatch($originalTitle, $fetchedTitle);
        return $score > 0.0 ? $score : 95.0;           // no original title — exact id match
    }


    public static function putReferenceinCollection(mixed $ref): mixed {
        if (is_a($ref, "Biblhertz\Article\om\ReferenceCollection")) {
            return $ref;
        }
        if (is_a($ref, "Biblhertz\Article\om\Reference")) {
            $collection = new ReferenceCollection();
            $collection->offsetSet($ref->getLabel(), $ref);
            return $collection;
        }
        return false;
    }
}
?>