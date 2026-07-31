<?php
/**
 * Bibliotheca_Page.php
 *
 * Concrete base page class for the Bibliotheca Hertziana PubLink intranet.
 * Extends htmlPage to add PubLink-specific state: page titles, headings,
 * central content, modal slots, AdminLTE path configuration, a shared
 * database connection, and a logo helper.
 *
 * This class sits at the top of the concrete page hierarchy:
 *   htmlPage  ←  Bibliotheca_Page  ←  Bibliotheca_Intranet_Page  ←  Bibliotheca_Content_Page
 *
 * The constructor bootstraps the application environment (Config::setup()),
 * sets the timezone to UTC, and opens the shared PDO database connection
 * that all subclasses share via the static $objDB property.
 *
 * @package Biblhertz\Publink\pages
 * @author  Chris Tomlinson
 * @since   March 2023
 */

namespace Biblhertz\Publink\pages;

use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Publink\utilities\PDODatabase;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\Encryption;
use Exception;
use PDOStatement;

abstract class Bibliotheca_Page extends htmlPage
{

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string HTML content injected into the main content area of the page template. */
    private string $centralContent = '';

    /** @var string Long-form page title shown in the browser tab (<title>) and page text. */
    private string $longTitle = 'Bibliotheca Hertziana : PubLink';

    /** @var string Standard page title used in the <title> tag and general text. */
    private string $title = 'Bibliotheca Hertziana : PubLink';

    /** @var string Abbreviated title used in compact UI locations such as the sidebar brand. */
    private string $shortTitle = 'BH PubLink';

    /** @var string Primary heading displayed at the top of the content area (e.g. in <h1>). */
    private string $heading = '';

    /**
     * @var PDODatabase Shared database connection instance.
     *                  Static so a single connection is reused across the page object hierarchy.
     */
    protected static PDODatabase $objDB;

    /**
     * @var string Accumulated HTML for any modal dialogs to be rendered in the page body.
     *             Modal components append to this string via addToModalMessage().
     */
    private string $modalMessage = '';

    /**
     * @var string Accumulated HTML/JS for modal component initialisers to be injected into <head>.
     *             Modal components append to this string via addToModalHead() / setModalHead().
     */
    private string $modalHead = '';

    /** @var string Slot for an error message string, accessible to subclasses. */
    protected string $errorMessage = '';

    /**
     * @var string Filesystem or URL path to the AdminLTE distribution directory.
     *             Set once at application bootstrap via setAdminLTEPath().
     *             Static so it is available to all page instances without re-configuration.
     */
    private static string $adminLTEPath = '';


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Bootstraps the application environment and opens the shared database connection.
     *
     * - Sets the PHP timezone to UTC for consistent date handling.
     * - Calls Config::setup() to load environment-specific configuration.
     * - Instantiates the shared PDODatabase connection used by all page subclasses.
     */
    public function __construct()
    {
        date_default_timezone_set('UTC');
        Config::setup();
        if (!isset(self::$objDB)) {
            self::$objDB = new PDODatabase();
        }
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Sets the error message string.
     * Typically populated by exception handlers or validation routines
     * before rendering so the template can display it to the user.
     *
     * @param  string $s Error message text or HTML.
     * @return void
     */
    public function setErrorMessage(string $s): void
    {
        $this->errorMessage = $s;
    }

    /**
     * Returns the current error message string.
     *
     * @return string Error message text or HTML, or an empty string if none is set.
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Replaces the entire central content area with the given HTML string.
     * Use addToCentralContent() if you need to append rather than replace.
     *
     * @param  string $s HTML string for the main content area.
     * @return void
     */
    public function setCentralContent(string $s): void
    {
        $this->centralContent = $s;
    }

    /**
     * Returns the HTML currently queued for the central content area.
     *
     * @return string HTML string, or empty string if no content has been set.
     */
    public function getCentralContent(): string
    {
        return $this->centralContent;
    }

    /**
     * Returns the shared PDO database connection.
     * Static so it can be accessed from static methods without a page instance.
     *
     * @return PDODatabase The active database connection.
     */
    public static function getObjDB(): PDODatabase
    {
        return self::$objDB;
    }

    /**
     * Sets the standard page title used in the <title> tag.
     *
     * @param  string $s Page title string.
     * @return void
     */
    public function setTitle(string $s): void
    {
        $this->title = $s;
    }

    /**
     * Returns the standard page title.
     *
     * @return string Page title string.
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Returns the long-form page title (e.g. for use in the login page <title>).
     * This is not currently configurable via a setter; it reflects the application default.
     *
     * @return string Long title string.
     */
    public function getLongTitle(): string
    {
        return $this->longTitle;
    }

    /**
     * Returns the abbreviated page title used in compact UI locations (e.g. sidebar brand).
     *
     * @return string Short title string.
     */
    public function getShortTitle(): string
    {
        return $this->shortTitle;
    }

    /**
     * Sets the primary heading text displayed at the top of the content area.
     *
     * @param  string $s Heading text (plain text or safe HTML).
     * @return void
     */
    public function setHeading(string $s): void
    {
        $this->heading = $s;
    }

    /**
     * Returns the primary heading text for this page.
     *
     * @return string Heading text, or empty string if not set.
     */
    public function getHeading(): string
    {
        return $this->heading;
    }

    /**
     * Returns the accumulated modal head HTML/JS string for injection into <head>.
     *
     * @return string HTML/JS string, or empty string if no modal head content has been added.
     */
    public function getModalHead(): string
    {
        return $this->modalHead;
    }

    /**
     * Replaces the modal head content entirely with the given string.
     * Prefer addToModalHead() when multiple modal components may be present.
     *
     * @param  string $s HTML or JavaScript to place in the <head> for modal initialisation.
     * @return void
     */
    public function setModalHead(string $s): void
    {
        $this->modalHead = $s;
    }

    /**
     * Appends to the modal head content string.
     * Called by Modal components to register their <head> scripts without
     * overwriting scripts registered by other modals on the same page.
     *
     * @param  string $s HTML or JavaScript to append.
     * @return void
     */
    public function addToModalHead(string $s): void
    {
        $this->modalHead .= $s;
    }

    /**
     * Appends to the modal message HTML string rendered in the page body.
     * Called by Modal components to register their markup.
     *
     * @param  string $s HTML markup for a modal dialog.
     * @return void
     */
    public function addToModalMessage(string $s): void
    {
        $this->modalMessage .= $s;
    }

    /**
     * Returns the accumulated modal message HTML for rendering in the page body.
     *
     * @return string HTML string containing all registered modal dialogs.
     */
    public function getModalMessage(): string
    {
        return $this->modalMessage;
    }

    /**
     * Sets the base path to the AdminLTE distribution directory.
     * Should be called once during application bootstrap before any pages are rendered.
     * Static so it only needs to be configured once regardless of how many page objects exist.
     *
     * @param  string $path Filesystem path or public URL to the AdminLTE root
     *                      (e.g. '/assets/adminlte' or '/vendor/adminlte/dist/..').
     * @return void
     */
    public static function setAdminLTEPath(string $path): void
    {
        self::$adminLTEPath = $path;
    }

    /**
     * Returns the configured AdminLTE base path.
     *
     * @return string The AdminLTE path, or an empty string if not yet configured.
     */
    public function getAdminLTEPath(): string
    {
        return self::$adminLTEPath;
    }

    /**
     * Renders the site logo as an HTML <img> element.
     * The image source is built from the configured image root and the logo filename
     * defined in Config::$LOGO.
     *
     * @return string HTML <img> element for the site logo (256×55 px).
     */
    public function getLogo(): string
    {
        return '<img src="' . Bibliotheca_Page::getImageRoot() . Config::$LOGO . '" width="256" height="55" alt="' . htmlspecialchars($this->shortTitle, ENT_QUOTES) . '">';
    }


    /****************************************************************/
    /* OTHER METHODS                                                */
    /****************************************************************/

    /**
     * Centralised exception handler for page-level errors.
     *
     * Sets the page heading to an error indicator, populates the central content
     * area with the exception message, renders the page immediately, and halts
     * execution. This ensures the user always sees a rendered page rather than
     * a PHP fatal error screen.
     *
     * @param  Exception $e The exception to display.
     * @return string       Never actually returns — exits after echoing the page.
     *                      Return type declared for interface compatibility.
     */
    public function handleException(Exception $e): never
    {
        $this->setHeading('!! An Error has occurred');
        $this->setCentralContent(htmlspecialchars($e->getMessage(), ENT_QUOTES));
        try {
            echo $this->getPage();
        } catch (\Throwable $t) {
            // getPage() failed — object is partially initialised (e.g. exception thrown
            // in a parent constructor before all properties were assigned). Fall back to
            // a minimal HTML error page so the user sees something useful.
            $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES);
            echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>Error</title></head>"
               . "<body><h1>An error has occurred</h1><p>{$msg}</p></body></html>";
        }
        exit;
    }


    /****************************************************************/
    /* UTILITY METHODS                                              */
    /****************************************************************/

    /**
     * Returns the symmetric encryption key used by the Encryption utility.
     * Read from Config::$PUBLINK_ENCRYPTION_KEY, which is loaded from config.ini
     * during application bootstrap (Config::setup()).
     *
     * @return string Base64-encoded AES encryption key.
     * @throws \RuntimeException If Config::setup() has not been called yet.
     */
    public static function getKey(): string
    {
        if (Config::$PUBLINK_ENCRYPTION_KEY === '') {
            throw new \RuntimeException('Encryption key is not configured. Ensure Config::setup() has been called.');
        }
        return Config::$PUBLINK_ENCRYPTION_KEY;
    }

    /**
     * Searches an encrypted result set for a row whose decrypted column value matches $value.
     *
     * Iterates through all rows in $resultSet, decrypting the specified column using AES-128-CTR
     * and comparing the result against the plaintext $value. Returns the row's `id` on the
     * first match, or false if no match is found.
     *
     * @param  PDOStatement $resultSet  An executed PDO statement ready for fetching.
     * @param  string       $columnName Name of the encrypted column to compare.
     * @param  string       $value      Plaintext value to search for.
     * @return mixed                    The integer `id` of the matching row, or false if not found.
     */
    public function valueExists(PDOStatement $resultSet, string $columnName, string $value): int|false
    {
        $e = new Encryption('aes-128-ctr', $this->getKey());

        while ($row = $resultSet->fetch()) {
            if ($e->decrypt($row[$columnName]) === $value) {
                return $row['id'];
            }
        }

        return false;
    }
}