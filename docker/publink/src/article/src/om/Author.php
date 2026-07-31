<?php

namespace Biblhertz\Article\om;

use ReflectionClass;
use Biblhertz\Publink\utilities\Utilities;

/**
 * Author
 *
 * Represents a journal article author and provides utilities for parsing,
 * comparing, and transforming author data between different representations
 * (JATS XML, BibTeX, OJS export, etc.).
 *
 * Name components follow the BibTeX convention:
 * - `firstName`  — given name(s)
 * - `von`        — lowercase name particle (e.g. "van", "de") or extracted middle initial
 * - `lastName`   — family name
 * - `jr`         — name suffix (e.g. "Jr.", "II")
 *
 * Affiliations are stored as an ordered array of {@see Affiliation} objects
 * and managed via {@see addAffiliation()} (duplicate-safe) or
 * {@see setAffiliations()} (full replacement).
 *
 * @package Biblhertz\Article\om
 * @author  Chris Tomlinson
 * @since   2023-07-10
 */
class Author extends ArticleObject
{
    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string Internal unique identifier for this author. */
    private string $uniqueID = "";

    /** @var string Author's first (given) name. */
    private string $firstName = "";

    /** @var string Author's last (family) name. */
    private string $lastName = "";

    /**
     * Lowercase name particle (e.g. "von", "van", "de") or extracted middle
     * initial. Set by {@see setVon()} or populated during BibTeX parsing.
     *
     * @var string
     */
    private string $von = "";

    /** @var string Name suffix (e.g. "Jr.", "Sr.", "II"). */
    private string $jr = "";

    /**
     * Full display name as it appeared in the original source string.
     * Preserved verbatim by {@see parseAuthorName()} before normalisation.
     *
     * @var string|null
     */
    private ?string $fullName = null;

    /** @var string Author's email address. */
    private string $email = "";

    /** @var string ORCID identifier (e.g. `"0000-0001-2345-6789"`). */
    private string $orcID = "";

    /** @var string Short biographical note about the author. */
    private string $biography = "";

    /** @var bool Whether the author is deceased. */
    private bool $deceased = false;

    /**
     * Whether this author contributed equally with other listed authors.
     * Defaults to `true`; corresponds to the JATS `equal-contrib` attribute.
     *
     * @var bool
     */
    private bool $equalContrib = true;

    /**
     * Whether this is the corresponding author for the article.
     * Only one author per article should have this flag set.
     *
     * @var bool
     */
    private bool $correspondingAuthor = false;

    /**
     * Institutional affiliations associated with this author.
     * Managed via {@see addAffiliation()} and {@see setAffiliations()}.
     *
     * @var Affiliation[]
     */
    private array $affiliations = [];

    /**
     * Whether this object may be edited via the GUI.
     *
     * @var bool
     */
    public static bool $ALLOW_EDIT = true;


    /****************************************************************/
    /* CONSTRUCTOR                                                   */
    /****************************************************************/

    /**
     * Constructs a new Author instance with default empty values.
     * Subclass-specific `disallowedFields` can be set here if needed.
     */
    public function __construct()
    {
    }


    /****************************************************************/
    /* NAME METHODS                                                  */
    /****************************************************************/

    /**
     * Set the author's first (given) name.
     *
     * @param  string $s  First name.
     * @return void
     */
    public function setFirstName(string $s): void
    {
        $this->firstName = $s;
    }

    /**
     * Get the author's first (given) name.
     *
     * @return string
     */
    public function getFirstName(): string
    {
        return $this->firstName;
    }

    /**
     * Set the author's last (family) name.
     *
     * @param  string $s  Last name.
     * @return void
     */
    public function setLastName(string $s): void
    {
        $this->lastName = $s;
    }

    /**
     * Get the author's last (family) name.
     *
     * @return string
     */
    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * Set the full display name (e.g. as it appeared in the original source).
     *
     * This may include middle initials or formatting not captured by the
     * individual name component fields. Always preserved verbatim.
     *
     * @param  string $s  Full name string.
     * @return void
     */
    public function setFullName(string $s): void
    {
        $this->fullName = $s;
    }

    /**
     * Get the full display name.
     *
     * @return string|null  The original full name, or null if not set.
     */
    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    /**
     * Set the name particle (e.g. "von", "van", "de") or middle initial.
     *
     * @param  string $s  Name particle or initial.
     * @return void
     */
    public function setVon(string $s): void
    {
        $this->von = $s;
    }

    /**
     * Get the name particle (e.g. "von", "van", "de") or middle initial.
     *
     * @return string
     */
    public function getVon(): string
    {
        return $this->von;
    }

    /**
     * Set the name suffix (e.g. "Jr.", "Sr.", "II").
     *
     * @param  string $s  Name suffix.
     * @return void
     */
    public function setJr(string $s): void
    {
        $this->jr = $s;
    }

    /**
     * Get the name suffix (e.g. "Jr.", "Sr.", "II").
     *
     * @return string
     */
    public function getJr(): string
    {
        return $this->jr;
    }

    /**
     * Get the assembled complete name in the format: `[first] [von] [last] [jr]`.
     *
     * Empty components are omitted and the result is trimmed.
     * Example: `"John von Neumann"`, `"Vincent van Gogh"`, `"John Smith Jr."`.
     *
     * @return string  Complete name string.
     */
    public function getCompleteName(): string
    {
        $str = "";
        if ($this->firstName !== "") $str .= $this->firstName . " ";
        if ($this->von !== "")       $str .= $this->von . " ";
        if ($this->lastName !== "")  $str .= $this->lastName . " ";
        if ($this->jr !== "")        $str .= $this->jr;

        return trim($str);
    }


    /****************************************************************/
    /* CONTACT / IDENTITY METHODS                                   */
    /****************************************************************/

    /**
     * Set the author's email address.
     *
     * @param  string $s  Email address.
     * @return void
     */
    public function setEmail(string $s): void
    {
        $this->email = $s;
    }

    /**
     * Get the author's email address.
     *
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Set the author's internal unique identifier.
     *
     * @param  string $s  Unique ID.
     * @return void
     */
    public function setUniqueID(string $s): void
    {
        $this->uniqueID = $s;
    }

    /**
     * Get the author's internal unique identifier.
     *
     * @return string
     */
    public function getUniqueID(): string
    {
        return $this->uniqueID;
    }

    /**
     * Set the author's ORCID identifier.
     *
     * @param  string $s  ORCID string (e.g. `"0000-0001-2345-6789"`).
     * @return void
     */
    public function setOrcID(string $s): void
    {
        $this->orcID = $s;
    }

    /**
     * Get the author's ORCID identifier.
     *
     * @return string
     */
    public function getOrcID(): string
    {
        return $this->orcID;
    }


    /****************************************************************/
    /* BIOGRAPHICAL / STATUS METHODS                                */
    /****************************************************************/

    /**
     * Set the author's short biographical note.
     *
     * @param  string $s  Biography text.
     * @return void
     */
    public function setBiography(string $s): void
    {
        $this->biography = $s;
    }

    /**
     * Get the author's short biographical note.
     *
     * @return string
     */
    public function getBiography(): string
    {
        return $this->biography;
    }

    /**
     * Set whether the author is deceased.
     *
     * @param  bool $s  `true` if deceased.
     * @return void
     */
    public function setDeceased(bool $s): void
    {
        $this->deceased = $s;
    }

    /**
     * Get whether the author is deceased.
     *
     * @return bool
     */
    public function getDeceased(): bool
    {
        return $this->deceased;
    }

    /**
     * Set the corresponding author flag.
     * The corresponding author is the primary contact for the article.
     * Only one author per article should have this set to `true`.
     *
     * @param  bool $b  `true` if this is the corresponding author.
     * @return void
     */
    public function setCorrespondingAuthor(bool $b): void
    {
        $this->correspondingAuthor = $b;
    }

    /**
     * Get whether this author is the corresponding author.
     *
     * @return bool
     */
    public function getCorrespondingAuthor(): bool
    {
        return $this->correspondingAuthor;
    }

    /**
     * Set the equal contribution flag.
     * When `true`, this author contributed equally alongside other listed authors.
     * Corresponds to the JATS `equal-contrib` attribute.
     *
     * @param  bool $b  `true` if equal contribution.
     * @return void
     */
    public function setEqualContrib(bool $b): void
    {
        $this->equalContrib = $b;
    }

    /**
     * Get whether this author contributed equally with other authors.
     *
     * @return bool
     */
    public function getEqualContrib(): bool
    {
        return $this->equalContrib;
    }


    /****************************************************************/
    /* AFFILIATION METHODS                                          */
    /****************************************************************/

    /**
     * Replace the entire affiliations collection.
     *
     * @param  Affiliation[] $s  Array of {@see Affiliation} objects.
     * @return void
     */
    public function setAffiliations(array $s): void
    {
        $this->affiliations = $s;
    }

    /**
     * Return all affiliations for this author.
     *
     * @return Affiliation[]
     */
    public function getAffiliations(): array
    {
        return $this->affiliations;
    }

    /**
     * Return the display string of the first affiliation, or false if none exist.
     *
     * Delegates to {@see Affiliation::getAffiliation()} on the first element.
     *
     * @return string|false  First affiliation string, or `false` if the collection is empty.
     */
    public function getFirstAffiliation(): string|false
    {
        if (count($this->affiliations)) {
            return $this->affiliations[0]->getAffiliation();
        }
        return false;
    }

    /**
     * Add an affiliation to this author's collection, ignoring duplicates.
     *
     * Duplicate detection is delegated to {@see Affiliation::affiliationExists()},
     * which compares by JATS ID.
     *
     * @param  Affiliation $affiliation  The affiliation to add.
     * @return void
     */
    public function addAffiliation(Affiliation $affiliation): void
    {
        $exists = false;
        foreach ($this->affiliations as $a) {
            if ($affiliation->affiliationExists($a)) $exists = true;
        }
        if (!$exists) array_push($this->affiliations, $affiliation);
    }


    /****************************************************************/
    /* UTILITY METHODS                                              */
    /****************************************************************/

    /**
     * Check whether the given Author represents the same person as this one.
     *
     * Matching priority:
     * 1. If this author has a non-empty email and it matches `$author`'s email → match.
     * 2. Otherwise, match on first name + last name (case-sensitive string comparison).
     *
     * @param  Author $author  The author to compare against.
     * @return bool  `true` if the authors are considered the same person.
     */
    public function authorExists(Author $author): bool
    {
        if ($this->email !== "" && $author->getEmail() === $this->email) return true;
        if ($author->getFirstName() === $this->getFirstName() &&
            $author->getLastName() === $this->getLastName()) return true;
        return false;
    }

    /**
     * Update this author's scalar properties from a POST array.
     *
     * Uses reflection to iterate over all instance properties. Array properties
     * (such as `$affiliations`) are automatically skipped. Only properties whose
     * names appear as keys in `$post` are updated.
     *
     * @param  array $post  Associative array of property name → value pairs,
     *                      typically from `$_POST`.
     * @return void
     */
    public function updateAuthor(array $post): void
    {
        $reflect = new ReflectionClass($this);
        $props   = $reflect->getProperties();

        foreach ($props as $prop) {
            if (!is_array($prop->getValue($this))) {
                if (isset($post[$prop->getName()])) {
                    $value = $post[$prop->getName()];
                    if ($prop->getType()?->getName() === 'bool') {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    }
                    $prop->setValue($this, $value);
                }
            }
        }
    }


    /****************************************************************/
    /* BIBTEX PARSING                                               */
    /****************************************************************/

    /**
     * Parse a BibTeX author string into an array of Author objects.
     *
     * Authors are split on ` and ` (case-insensitive) as per BibTeX convention.
     * Each individual name is then parsed by {@see parseAuthorName()}.
     *
     * Example input:
     * ```
     * "Smith, John and van Gogh, Vincent and Aristotle"
     * ```
     *
     * @param  string    $authorString  Raw BibTeX author string.
     * @return Author[]                 Parsed Author objects, or an empty array if input is empty.
     */
    public static function parseBibtexAuthors(string $authorString): array
    {
        if (empty($authorString)) {
            return [];
        }

        $authors       = preg_split('/\s+and\s+/i', trim($authorString));
        $parsedAuthors = [];

        foreach ($authors as $author) {
            $parsedAuthors[] = self::parseAuthorName(trim($author));
        }

        return $parsedAuthors;
    }

    /**
     * Parse a single author name string into an Author object.
     *
     * Supported formats:
     * - `"Last, First"`          — comma-separated last, first
     * - `"Last, Jr, First"`      — comma-separated with suffix
     * - `"von Last, First"`      — last name contains a lowercase particle
     * - `"First Last"`           — natural order, two parts
     * - `"First von Last"`       — natural order with particle
     * - `"First Last Jr"`        — natural order with trailing suffix
     *
     * Middle initials (e.g. `"John A. Smith"`) are extracted via
     * {@see extractMiddleInitial()} and stored in the `von` field before the
     * initial is stripped from the name for further parsing.
     *
     * The original full name string is always preserved in `fullName`.
     *
     * @param  string  $name  Single author name string.
     * @return Author         Populated Author object.
     */
    private static function parseAuthorName(string $name): Author
    {
        $name = Utilities::renderBibtexTitle(trim(ucwords(strtolower($name), " \t\r\n\f\v-")));
        $name = preg_replace('/\s+/', ' ', $name);

        $result = [
            'first' => '',
            'von'   => '',
            'last'  => '',
            'jr'    => '',
            'full'  => $name,
        ];

        // Extract and store middle initial before stripping it from the name
        $initial = self::extractMiddleInitial($name);
        if ($initial) $result['von'] = $initial;
        $name = preg_replace('/\s+[A-Z]\.\s+/', ' ', $name);

        if (strpos($name, ',') !== false) {
            // Comma-delimited: "Last, First" or "Last, Jr, First"
            $parts = array_map('trim', explode(',', $name));

            if (count($parts) == 2) {
                $result['last']  = $parts[0];
                $result['first'] = $parts[1];

                // Detect and extract a von particle from the last name (e.g. "van Gogh")
                $lastParts = explode(' ', $parts[0]);
                if (count($lastParts) > 1) {
                    $vonParts      = [];
                    $lastNameParts = [];

                    foreach ($lastParts as $part) {
                        if (ctype_lower($part[0])) {
                            $vonParts[] = $part;
                        } else {
                            $lastNameParts[] = $part;
                        }
                    }

                    if (!empty($vonParts)) {
                        $result['von']  = implode(' ', $vonParts);
                        $result['last'] = implode(' ', $lastNameParts);
                    }
                }
            } elseif (count($parts) == 3) {
                $result['last']  = $parts[0];
                $result['jr']    = $parts[1];
                $result['first'] = $parts[2];
            }
        } else {
            // Natural order: "First Last", "First von Last", "First Last Jr"
            $parts = explode(' ', $name);

            if (count($parts) == 1) {
                // Single token — treat as last name only (e.g. "Aristotle")
                $result['last'] = $parts[0];
            } elseif (count($parts) == 2) {
                $result['first'] = $parts[0];
                $result['last']  = $parts[1];
            } else {
                $firstParts = [];
                $vonParts   = [];
                $lastParts  = [];
                $jrParts    = [];

                $suffixes = ['Jr', 'Jr.', 'Sr', 'Sr.', 'II', 'III', 'IV', 'V'];

                // Pop a trailing suffix if present before parsing the rest
                if (in_array(end($parts), $suffixes)) {
                    $jrParts[] = array_pop($parts);
                }

                // First word is always the given name in natural-order format
                $firstParts[] = array_shift($parts);

                // Remaining parts: lowercase → von particle, uppercase → last name
                foreach ($parts as $part) {
                    if (ctype_lower($part[0])) {
                        $vonParts[] = $part;
                    } else {
                        $lastParts[] = $part;
                    }
                }

                $result['first'] = implode(' ', $firstParts);
                $result['von']   = implode(' ', $vonParts);
                $result['last']  = implode(' ', $lastParts);
                $result['jr']    = implode(' ', $jrParts);
            }
        }

        $author = new Author();
        $author->setFirstName($result['first']);
        $author->setLastName($result['last']);
        $author->setVon($result['von']);
        $author->setJr($result['jr']);
        $author->setFullName($result['full']);

        return $author;
    }

    /**
     * Extract a middle initial from a three-part name string.
     *
     * Matches patterns like `"John A. Smith"` or `"John A Smith"` and returns
     * the initial with its period if present (e.g. `"A."` or `"A"`).
     * Returns `null` if no middle initial pattern is found.
     *
     * @param  string      $name  Name string to inspect.
     * @return string|null        The extracted initial, or `null` if none found.
     */
    private static function extractMiddleInitial(string $name): ?string
    {
        if (preg_match('/^\w+\s+([A-Z]\.?)\s+\w+$/', trim($name), $matches)) {
            return $matches[1];
        }
        return null;
    }
}
?>