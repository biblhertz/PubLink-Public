<?php

namespace Biblhertz\Article\om;

/**
 * Person
 *
 * Represents a generic named person within the PubLink article model.
 * Extends {@see ArticleObject}.
 *
 * Unlike {@see Author}, which carries full bibliographic metadata (ORCID,
 * affiliations, BibTeX parsing, etc.), Person is a lightweight value object
 * holding only a first and last name. Both components are required at
 * construction.
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class Person extends ArticleObject {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string Person's first (given) name. */
    private string $firstName = "";

    /** @var string Person's last (family) name. */
    private string $lastName = "";

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
     * Initialise a Person with a first and last name.
     *
     * Both name components are required; there is no default constructor.
     *
     * @param  string $fn  First (given) name.
     * @param  string $ln  Last (family) name.
     */
    public function __construct(string $fn, string $ln) {
        $this->firstName = $fn;
        $this->lastName  = $ln;
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the first (given) name.
     *
     * @param  string $s  First name.
     * @return void
     */
    public function setFirstName(string $s): void {
        $this->firstName = $s;
    }

    /**
     * Get the first (given) name.
     *
     * @return string
     */
    public function getFirstName(): string {
        return $this->firstName;
    }

    /**
     * Set the last (family) name.
     *
     * @param  string $s  Last name.
     * @return void
     */
    public function setLastName(string $s): void {
        $this->lastName = $s;
    }

    /**
     * Get the last (family) name.
     *
     * @return string
     */
    public function getLastName(): string {
        return $this->lastName;
    }

    /**
     * Get the full name as a single string in the format `"[first] [last]"`.
     *
     * @return string  Full name with a single space separator.
     */
    public function getFullName(): string {
        return $this->firstName . " " . $this->lastName;
    }

    /**
     * Get the complete name string.
     *
     * Delegates to {@see getFullName()}. Provided for interface consistency
     * with {@see Author::getCompleteName()}, which supports additional name
     * components (particle, suffix) not present on this class.
     *
     * @return string  Full name string.
     */
    public function getCompleteName(): string {
        return $this->getFullName();
    }
}
?>