<?php
/**
 * Config.php
 *
 * Central application configuration class for PubLink.
 *
 * Acts as a singleton store of environment-specific settings, loaded once at
 * bootstrap time via setup() from a `config.ini` file. All properties are
 * public static so they can be read directly throughout the codebase without
 * passing a config object around.
 *
 * Deployment environments are represented by integer constants (LOCALHOST,
 * LOCALDOCKER, REMOTEDOCKER, etc.). The active environment is set in config.ini
 * and read by setup(); the inline $ENVIRONMENT assignment in this file acts only
 * as a fallback default for local development.
 *
 * Usage:
 *   Config::setup();           // called once in the page constructor
 *   $path = Config::get('key'); // raw ini value access
 *   Config::$FILE_STORE_PATH;   // typed property access after setup()
 *
 * @package Biblhertz\Publink
 * @author  Chris Tomlinson
 * @since   March 2023
 */

namespace Biblhertz\Publink;

use Biblhertz\Publink\pages\Bibliotheca_Page;
use Biblhertz\Publink\utilities\PDODatabase;

class Config
{

    /****************************************************************/
    /* DEPLOYMENT ENVIRONMENT CONSTANTS                             */
    /****************************************************************/

    /** @var int Run on local host on bare metal (no Docker). */
    const LOCALHOST = 0;

    /** @var int Run on local host inside Docker containers. */
    const LOCALDOCKER = 1;

    /** @var int Run on a remote host inside Docker containers (max_planck). */
    const REMOTEDOCKER = 2;

    /** @var int Run on the Bibliotheca Hertziana VM inside Docker containers. */
    const REMOTEBIBLHERTZDOCKER = 3;

    /** @var int Run on the MPCDF VM inside Docker containers. */
    const REMOTEMPCDFDOCKER = 4;


    /****************************************************************/
    /* STATIC PROPERTIES                                            */
    /****************************************************************/

    /*** Presentation ***/

    /** @var string Filename of the site logo image (resolved against the image root URL). */
    public static string $LOGO = 'BH_LOGO_EN_3C_P_ORIZ.jpg';

    /**
     * @var array<int,string> Characters to strip from imported strings (e.g. non-printable
     *                         bytes introduced by BibTeX or XML converters).
     */
    public static $BADCHARS=['','']; 

    /*** Active deployment environment ***/

    /**
     * @var string AES symmetric encryption key used by the Encryption utility to
     *             protect sensitive column values stored in the database.
     *
     *             Set via the `publink_encryption_key` entry in config.ini.
     *             The value must be a base64-encoded 128-bit (16-byte) key, e.g.:
     *               publink_encryption_key = "base64encodedkeyhere=="
     *
     *             Never hard-code this value in source. Keep config.ini out of
     *             version control and restrict its file permissions (chmod 600).
     */
    public static string $PUBLINK_ENCRYPTION_KEY = '';

    /**
     * @var int The currently active deployment environment.
     *          Overridden by setup() from config.ini. The default here is LOCALHOST
     *          for local bare-metal development; comment/uncomment as needed.
     */
    public static int $ENVIRONMENT = self::REMOTEMPCDFDOCKER;
    //public static int $ENVIRONMENT = self::LOCALHOST;

    /*** ORCiD OAuth integration ***/

    /** @var string|null Redirect URI registered with ORCiD (the page that receives the code). */
    public static ?string $LANDING_PAGE = null;

    /** @var string|null ORCiD token endpoint URL for exchanging authorisation codes. */
    public static bool $ORCID_INTEGRATION = false;
    /**
     * @var string|null Full ORCiD OAuth authorisation URL, assembled in setup() from
     *                   $ORCID_CLIENT_ID and $LANDING_PAGE.
     */
    public static ?string $ORCID_HTTP_ADDRESS = null;

    /** @var string|null ORCiD token endpoint URL for exchanging authorisation codes. */
    public static ?string $ORCID_OATH_ADDRESS = null;

    /** @var string|null ORCiD Member API base URL (e.g. 'https://api.orcid.org/v3.0/'). */
    public static ?string $ORCID_API_ADDRESS = null;

    /** @var string ORCiD OAuth client ID (loaded from config.ini). */
    public static string $ORCID_CLIENT_ID = '';

    /** @var string ORCiD OAuth client secret (loaded from config.ini). */
    public static string $ORCID_CLIENT_SECRET = '';

    /** @var string|null ORCiD OpenID Connect address (currently unused). */
    public static ?string $ORCID_OPEN_ID_ADDRESS = null;

    /*** Simple Manifest Server integration ***/

    /** @var bool When true, IIIF manifests are stored via the Simple Manifest Server. */
    public static bool $MANIFEST_SERVER_INTEGRATION = false;

    /** @var string Base URI of the Simple Manifest Server (e.g. https://manifests.example.com). */
    public static string $MANIFEST_SERVER_URI = '';

    /*** CrossRef integration ***/

    /** @var bool When false the CrossRef adapter is disabled. Default true. */
    public static bool $CROSSREF_INTEGRATION = true;

    /** @var string Contact email sent to CrossRef/PubMed APIs for polite-pool access. */
    public static string $CROSSREF_EMAIL = '';

    /*** Google Books API ***/

    /**
     * @var string|null Google Books API key. When set, appended to all Books API
     *                  requests to use the project's authenticated quota (1,000+ req/day).
     *                  Set via `google_books_api_key` in config.ini.
     */
    public static ?string $GOOGLE_BOOKS_API_KEY = null;

    /** PRIMO integration */

    /** @var string|null ORCiD token endpoint URL for exchanging authorisation codes. */
    public static bool $PRIMO_INTEGRATION = false;
    /**
     * @var string|null  PRIMO API URI, assembled in setup()
     */
    public static ?string $PRIMO_URI = null;

    /** @var string|null PRIMO API KEY. */
    public static ?string $PRIMO_API_KEY = null;

    /** @var string|null PRIMO VID Value. */
    public static ?string $PRIMO_VID = null;

    /*** CSL citation rendering ***/

    /**
     * @var array<int,array{0:string,1:string}> List of available citation styles as
     *      [filename, display-name] pairs, populated from config.ini by setup().
     */
    public static array $CITATIONS_LIST = [];

    /** @var string Filesystem path to the directory containing CSL style files. */
    public static string $CSL_LOCATION = '';

    /*** File management ***/

    /** @var string|null Filesystem path to the user file store root. Set by setup(). */
    public static ?string $FILE_STORE_PATH = null;

    /** @var string|null Filesystem path to the application log directory. Set by setup(). */
    public static ?string $LOG_DIR = null;

    /** @var string|null Filesystem path to the job queue log directory. Set by setup(). */
    public static ?string $JOB_LOG_DIR = null;

    /** @var int Maximum number of log lines to display in the admin console. */
    public static int $LOG_LINES_DISPLAY = 500;

    /** @var bool When true, the registration form (register.html) allows new users to self-register. */
    public static bool $REGISTRATION_ENABLED = true;

    /*** Session / user management ***/

    /** @var int Number of recent sessions to display in the admin sessions view. */
    public static int $NUM_SESSIONS = 50;

    /*** User group IDs (must match values in the `user_group` table) ***/

    /** @var int Minimum user_group_id for super-user privileges. */
    public static int $SUPER_USER = 20;

    /** @var int Minimum user_group_id for administrator privileges (currently same as SUPER_USER). */
    public static int $ADMINISTRATOR = 20;

    /** @var int user_group_id for a standard authenticated user. */
    public static int $USER = 1;

    /*** Job queue ***/

    /** @var string Filesystem path to the directory containing async job queue scripts. */
    public static string $JOB_QUEUE_DIR = '';

    /** @var bool When true the job scheduler emits debug messages to the log. */
    public static bool $SCHEDULER_DEBUG = true;

    /** @var string scheduler daemon location. */
    public static string $SCHEDULER_DAEMON = "/var/www/job_queue/worker.sh";

    /*** XSD validation files ***/

    /** @var array<int,string> Filesystem paths to OJS XSD files. Populated from config.ini. */
    public static array $OJS_XSD = [];

    /** @var array<int,string> Filesystem paths to JATS XSD files. Populated from config.ini. */
    public static array $JATS_XSD = [];

    /*** Image annotation ***/

    /** @var string Base URL for the Mirador IIIF viewer client (e.g. 'http://localhost:3000'). */
    public static string $MIRADOR_CLIENT = '';

    /** @var string URL of the internal PubLink annotation service endpoint. */
    public static string $ANNOTATION_ENDPOINT = '';

    /**
     * @var bool When true, annotation publication to the external simple_annotation_server
     *           integration is enabled.
     */
    public static bool $PUBLICATION = false;

    /** @var string API endpoint for publishing annotations to the external server. */
    public static string $PUBLICATION_PUBLISH_API = '';

    /** @var string API endpoint for removing annotations from the external server. */
    public static string $PUBLICATION_REMOVE_API = '';

    /** @var string Base URL of the external annotation publication server. */
    public static string $PUBLICATION_ENDPOINT = '';


    /** @var string URL of the service that checks whether a user is currently logged in. */
    public static string $USER_CHECK_SERVICE = '';

    /*** TEI Publisher integration ***/

    /** @var bool When true, TEI Publisher integration is enabled. Set from config.ini. */
    public static bool $TEI_PUBLISHER_INTEGRATION = false;

    /** @var string|null Base URL of the TEI Publisher instance. Set from config.ini. */
    public static ?string $TEI_PUBLISHER_URI = null;

    /** @var string Base URL of the TEI Publisher API. */
    public static string $TEI_ENDPOINT = 'http://tei-publisher:8080/exist/apps/tei-publisher/api';


    /****************************************************************/
    /* ENVIRONMENT DETECTION HELPERS                                */
    /****************************************************************/

    /**
     * Returns true when running in the remote Docker environment (max_planck).
     * @return bool
     */
    public static function isRemoteDocker(): bool
    {
        return self::$ENVIRONMENT === self::REMOTEDOCKER;
    }

    /**
     * Returns true when running in the Bibliotheca Hertziana remote Docker environment.
     * @return bool
     */
    public static function isRemoteBiblhertzDocker(): bool
    {
        return self::$ENVIRONMENT === self::REMOTEBIBLHERTZDOCKER;
    }

    /**
     * Returns true when running in local Docker containers.
     * @return bool
     */
    public static function isLocalDocker(): bool
    {
        return self::$ENVIRONMENT === self::LOCALDOCKER;
    }

    /**
     * Returns true when running in the MPCDF remote Docker environment.
     * @return bool
     */
    public static function isRemoteMPCDFDocker(): bool
    {
        return self::$ENVIRONMENT === self::REMOTEMPCDFDOCKER;
    }

    /**
     * Returns true when running directly on the local host (no Docker).
     * @return bool
     */
    public static function isLocalHost(): bool
    {
        return self::$ENVIRONMENT === self::LOCALHOST;
    }


    /****************************************************************/
    /* BOOTSTRAP                                                    */
    /****************************************************************/

    /**
     * Loads config.ini and initialises all static properties.
     *
     * Must be called once during application bootstrap (e.g. from the
     * Bibliotheca_Page constructor) before any Config properties are accessed.
     *
     * Responsibilities:
     *  - Reads all key/value pairs from config.ini via load().
     *  - Sets the active deployment environment.
     *  - Configures ORCiD OAuth URLs from client ID and landing page.
     *  - Sets Bibliotheca_Page static roots (site, image, CSS, JS, AdminLTE).
     *  - Configures the PDO database host.
     *  - Builds the $CITATIONS_LIST array from paired ini values.
     *  - Sets file store, log, and job queue paths.
     *  - Sets annotation and publication service URLs.
     *
     * @return void
     */
    public static function setup(): void
    {
        if(self::isLocalHost()) self::load("baremetal-config.ini");
        else self::load('config.ini');

        // Deployment environment
        self::$ENVIRONMENT = (int) self::get('environment');

        // Encryption key — must be set in config.ini; fatal if absent
        $key = (string) self::get('publink_encryption_key');
        if ($key === '') {
            throw new \RuntimeException('Config: publink_encryption_key is not set in config.ini');
        }
        self::$PUBLINK_ENCRYPTION_KEY = $key;

         // Registration form availability
        self::$REGISTRATION_ENABLED = (bool) self::get('registration_enabled');

        // Site URL roots — used to build asset URLs throughout the system
        $siteRoot = (string) self::get('site_root');
        Bibliotheca_Page::setSiteRoot($siteRoot);
        Bibliotheca_Page::setImageRoot($siteRoot . '/img/');
        Bibliotheca_Page::setCssRoot($siteRoot . '/css/');
        Bibliotheca_Page::setJSRoot($siteRoot . '/js/');
        Bibliotheca_Page::setAdminLTEPath($siteRoot . '/adminLTE/');

        // XSD validation file locations
        self::$JATS_XSD = (array) self::get('jats_xsd');
        self::$OJS_XSD  = (array) self::get('ojs_xsd');

        // ORCiD OAuth settings
        self::$ORCID_INTEGRATION    = (bool) self::get('orcid_integration');
        if(self::$ORCID_INTEGRATION){
            self::$ORCID_CLIENT_ID    = (string) self::get('orcid_client_id');
            self::$ORCID_CLIENT_SECRET = (string) self::get('orcid_client_secret');
            self::$ORCID_OATH_ADDRESS  = (string) self::get('orcid_oath_address');
            self::$ORCID_API_ADDRESS   = (string) self::get('orcid_api_address');
            self::$LANDING_PAGE        = (string) $siteRoot."/services/loginService.php";

            // Assemble the full ORCiD OAuth authorisation URL
            self::$ORCID_HTTP_ADDRESS = 'https://orcid.org/oauth/authorize'
            . '?client_id='    . urlencode(self::$ORCID_CLIENT_ID)
            . '&response_type=code'
            . '&scope=openid'
            . '&redirect_uri=' . urlencode(self::$LANDING_PAGE);
        }

        // Simple Manifest Server integration
        self::$MANIFEST_SERVER_INTEGRATION = (bool) self::get('manifest_server_integration');
        if (self::$MANIFEST_SERVER_INTEGRATION) {
            self::$MANIFEST_SERVER_URI = (string) self::get('manifest_server_uri');
        }

        // CrossRef integration flag and contact email
        self::$CROSSREF_INTEGRATION = (bool) self::get('crossref_integration');
        self::$CROSSREF_EMAIL       = (string) self::get('crossref_email');

        // Google Books API key (optional — unauthenticated requests have near-zero quota)
        $googleKey = (string) self::get('google_books_api_key');
        self::$GOOGLE_BOOKS_API_KEY = $googleKey !== '' ? $googleKey : null;

         // Primo integration settings
        self::$PRIMO_INTEGRATION    = (bool) self::get('primo_integration');
        if(self::$PRIMO_INTEGRATION){
            self::$PRIMO_API_KEY    = (string) self::get('primo_api_key');
            self::$PRIMO_VID = (string) self::get('primo_vid');
            self::$PRIMO_URI  = (string) self::get('primo_uri');
        }

        // TEI Publisher integration settings
        self::$TEI_PUBLISHER_INTEGRATION = (bool) self::get('teipublisher_integration');
        if (self::$TEI_PUBLISHER_INTEGRATION) {
            self::$TEI_PUBLISHER_URI = (string) self::get('teipublisher_uri');
        }

        // Service URLs derived from the site root
        self::$USER_CHECK_SERVICE  = $siteRoot . '/services/userCheckService.php';
        self::$ANNOTATION_ENDPOINT = $siteRoot . '/services/annotation.php';
        $mirador=(string) self::get('mirador_client');
        if(empty($mirador))self::$MIRADOR_CLIENT = "$siteRoot:3000";
        else self::$MIRADOR_CLIENT = $mirador;

        // File system paths
        self::$FILE_STORE_PATH = (string) self::get('file_store_path');
        self::$LOG_DIR         = (string) self::get('log_dir');
        self::$JOB_LOG_DIR     = (string) self::get('job_log_dir');
        self::$JOB_QUEUE_DIR   = (string) self::get('job_queue_dir');

        // Database host
        PDODatabase::setHost((string) self::get('pdo_host'));
        PDODatabase::setDatabaseName((string) self::get('database_name'));
        PDODatabase::setUser((string) self::get('database_user'));
        PDODatabase::setPassword((string) self::get('database_password'));

        // Annotation publication — derives all endpoints from the manifest server URI
        self::$PUBLICATION = (bool) self::get('publication');
        if (self::$PUBLICATION && self::$MANIFEST_SERVER_INTEGRATION) {
            $base = rtrim(self::$MANIFEST_SERVER_URI, '/');
            self::$PUBLICATION_PUBLISH_API = $base . '/api/v1/putManifest';
            self::$PUBLICATION_REMOVE_API  = $base . '/api/v1/removeManifest';
            self::$PUBLICATION_ENDPOINT    = $base . '/iiif_manifests/annotations/01/';
        }

        // CSL citation styles — config.ini stores pairs: [filename, display_name, filename, display_name, ...]
        $cites = (array) self::get('citations');
        $count = (int) (count($cites) / 2);
        self::$CITATIONS_LIST = [];
        for ($i = 0; $i < $count; $i++) {
            self::$CITATIONS_LIST[] = [$cites[$i * 2], $cites[$i * 2 + 1]];
        }
        self::$CSL_LOCATION = (string) self::get('csl_location');
    }


    /****************************************************************/
    /* INI FILE ACCESS                                              */
    /****************************************************************/

    /** @var array<string,mixed> Parsed ini data, populated by load(). */
    private static array $data = [];

    /**
     * Parses a PHP ini file and stores its key/value pairs in $data.
     * Must be called before any get() calls. Called internally by setup().
     *
     * @param  string $configFile Path to the ini file (relative to the PHP working directory).
     * @return void
     */
    public static function load(string $configFile): void
    {
        $result = parse_ini_file($configFile);
        if ($result === false) {
            throw new \RuntimeException("Config: failed to parse ini file: $configFile");
        }
        self::$data = $result;
    }

    /**
     * Returns a value from the loaded ini data by key.
     *
     * @param  string $key The ini key to look up.
     * @return mixed       The raw ini value, or null if the key does not exist.
     */
    public static function get(string $key): mixed
    {
        return self::$data[$key] ?? null;
    }
}