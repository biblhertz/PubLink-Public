<?php

namespace Biblhertz\Article\om;

/**
 * Affiliation
 *
 * Represents an institutional affiliation associated with an article author.
 * Extends {@see ArticleObject} and stores the standard address components
 * used in academic publishing (institution name, division, address, city,
 * and country).
 *
 * The class is designed to support transformation to different output
 * representations (e.g. JATS XML, HTML, plain text) via its accessor methods
 * and the {@see getAffiliation()} convenience formatter.
 *
 * GUI editing is disabled for this object type ({@see $ALLOW_EDIT} = false).
 *
 * @package  Biblhertz\Article\om
 * @author   Chris Tomlinson
 * @date     10th July 2023
 */
class Affiliation extends ArticleObject {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /**
     * Name of the institution (e.g. "Bibliotheca Hertziana").
     *
     * @var string
     */
    private string $name = "";

    /**
     * Division or department within the institution
     * (e.g. "Department of Art History").
     *
     * @var string
     */
    private string $division = "";

    /**
     * Street address of the institution.
     *
     * @var string
     */
    private string $address = "";

    /**
     * City in which the institution is located.
     *
     * @var string
     */
    private string $city = "";

    /**
     * Country in which the institution is located.
     *
     * @var string
     */
    private string $country = "";

    /**
     * Whether this object may be edited via the GUI.
     * Affiliations are not directly editable in the front-end interface.
     *
     * @var bool
     */
    public static bool $ALLOW_EDIT = false;


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the institution name.
     *
     * @param  string $s  Institution name.
     * @return void
     */
    public function setName(string $s): void {
        $this->name = $s;
    }

    /**
     * Get the institution name.
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Set the division or department name.
     *
     * @param  string $s  Division name.
     * @return void
     */
    public function setDivision(string $s): void {
        $this->division = $s;
    }

    /**
     * Get the division or department name.
     *
     * @return string
     */
    public function getDivision(): string {
        return $this->division;
    }

    /**
     * Set the street address.
     *
     * @param  string $s  Street address.
     * @return void
     */
    public function setAddress(string $s): void {
        $this->address = $s;
    }

    /**
     * Get the street address.
     *
     * @return string
     */
    public function getAddress(): string {
        return $this->address;
    }

    /**
     * Set the city.
     *
     * @param  string $s  City name.
     * @return void
     */
    public function setCity(string $s): void {
        $this->city = $s;
    }

    /**
     * Get the city.
     *
     * @return string
     */
    public function getCity(): string {
        return $this->city;
    }

    /**
     * Set the country.
     *
     * @param  string $s  Country name.
     * @return void
     */
    public function setCountry(string $s): void {
        $this->country = $s;
    }

    /**
     * Get the country.
     *
     * @return string
     */
    public function getCountry(): string {
        return $this->country;
    }

    /**
     * Get a short formatted affiliation string.
     *
     * Returns the division and institution name joined by a comma and space,
     * e.g. `"Department of Art History, Bibliotheca Hertziana"`.
     * Empty components are omitted so no leading or trailing separator appears.
     *
     * @return string  Formatted affiliation string.
     */
    public function getAffiliation(): string {
        $parts = array_filter([$this->getDivision(), $this->getName()], 'strlen');
        return implode(', ', $parts);
    }

    /**
     * Check whether this affiliation is the same as another by JATS ID.
     *
     * Compares the JATS ID of this affiliation against that of the supplied
     * affiliation object. Used to detect duplicates when building author
     * affiliation lists.
     *
     * @param  Affiliation $affiliation  The affiliation to compare against.
     *
     * @return bool  `true` if both affiliations share the same JATS ID,
     *               `false` otherwise.
     */
    public function affiliationExists(Affiliation $affiliation): bool {
        return $this->getJatsID() === $affiliation->getJatsID();
    }
}
?>