<?php
/**
 * Login.php
 *
 * Handles all authentication scenarios for the PubLink intranet:
 *  - ORCiD OAuth 2.0 (orchidAuthenticate)
 *  - KeyCloak SSO (keycloakLogin)
 *  - Local email/password (localAuthenticate)
 *
 * All three public methods follow the same contract: they validate or create
 * a user record and then delegate to the private logIn() method, which
 * performs the actual session creation via User_Session::logInExternal().
 *
 * Return values:
 *  - int  : the user ID on successful login (returned by User_Session)
 *  - string: a human-readable error message if login is denied (account locked, unknown user)
 *  - false : authentication failure (bad credentials, network error, missing ORCiD)
 *
 * ORCiD configuration (client ID, secret, API addresses) is read from Config.
 * Passwords for local accounts are stored in an encrypted format and compared
 * via Bibliotheca_Page::valueExists().
 *
 * @package Biblhertz\Publink\utilities
 * @author  Chris Tomlinson
 * @since   May 2024
 */

namespace Biblhertz\Publink\utilities;

use Biblhertz\Publink\Config;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\pages\Bibliotheca_Page;

class Login
{

    /****************************************************************/
    /* PUBLIC AUTHENTICATION METHODS                                */
    /****************************************************************/

    /**
     * Authenticates a user via the ORCiD OAuth 2.0 authorisation code flow.
     *
     * Flow:
     *  1. Exchanges the one-time authorisation $code for an access token via cURL POST
     *     to Config::$ORCID_OATH_ADDRESS.
     *  2. If the user's ORCiD ID already exists in `user_details`, updates their
     *     access token and logs them in.
     *  3. If the user is new, fetches their email address from the ORCiD API,
     *     creates a `user_details` record, grants all default task permissions,
     *     and logs them in.
     *
     * On any failure (network error, missing ORCiD field) the error is written to
     * error_log and false is returned.
     *
     * @param  string           $code  The OAuth authorisation code received from ORCiD's redirect.
     * @param  Bibliotheca_Page $page  Page object providing the DB handle and session.
     * @return int|string|false        User ID on success, a message string if account
     *                                 is locked/unknown, or false on auth failure.
     */
    public static function orchidAuthenticate(string $code, Bibliotheca_Page $page): mixed
    {
        // Exchange authorisation code for an access token
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => Config::$ORCID_OATH_ADDRESS,
            CURLOPT_POST           => 1,
            CURLOPT_VERBOSE        => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id'     => Config::$ORCID_CLIENT_ID,
                'client_secret' => Config::$ORCID_CLIENT_SECRET,
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => Config::$LANDING_PAGE,
            ]),
        ]);

        $jsonResult = curl_exec($ch);
        $result     = json_decode($jsonResult, true);
        curl_close($ch);

        // Auth token exchange failed
        if ($result === false) {
            $page->setErrorMessage("Error :: Could not Log In with ORCiD :: $jsonResult");
            error_log($page->getErrorMessage());
            return false;
        }

        // ORCiD ID missing from response
        if (!isset($result['orcid'])) {
            $page->setErrorMessage("Error :: ORCiD not set in response from ORCiD server :: $jsonResult");
            error_log($page->getErrorMessage());
            return false;
        }

        $orcid = $result['orcid'];

        // Check whether this ORCiD user already has a local account
        $uid = $page->getObjDB()->preparedGetOne(
            'SELECT id FROM user_details WHERE name = ?',
            [$orcid]
        );

        if (isset($uid) && is_numeric($uid) && $uid > 0) {
            // Existing user: refresh their stored access token and log in
            $user = new User($page->getObjDB(), $uid);
            $user->setAccessToken($jsonResult);
            return self::logIn($orcid, $page);
        }

        // New user: fetch their email address from the ORCiD API
        $email = self::fetchOrcidEmail($result['orcid'], $result['access_token']);

        // Parse the display name into first / last name components
        $nameParts = explode(' ', $result['name'] ?? '');
        $firstName  = $nameParts[0] ?? '';
        $lastName   = trim(implode(' ', array_slice($nameParts, 1)));

        // Create the user record
        $vals = [
            'name'          => $orcid,
            'user_group_id' => 1,
            'email'         => $email,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'token'         => $jsonResult,
            'login_type'    => 'orcid',
        ];
        $id = $page->getObjDB()->insert('user_details', $vals);

        // Grant all default task permissions to the new user
        self::grantAllTaskPermissions($id, $page);

        return self::logIn($orcid, $page);
    }

    /**
     * Logs in a user authenticated externally via KeyCloak SSO.
     *
     * Authentication itself is handled upstream (e.g. by a KeyCloak adapter);
     * this method only handles the PubLink side: creating a local user record
     * if one does not exist, then establishing a session.
     *
     * @param  array            $result Decoded token claims. Expected keys:
     *                                  'email', 'given_name', 'family_name'.
     * @param  Bibliotheca_Page $page   Page object providing the DB handle and session.
     * @param  string           $token  The raw KeyCloak JWT token string to store.
     * @return int|string|false         User ID on success, a message string if account
     *                                  is locked/unknown, or false on failure.
     */
    public static function keycloakLogin(array $result, Bibliotheca_Page $page, string $token): mixed
    {
        $email = $result['email'];

        $existing = $page->getObjDB()->preparedSelect(
            'SELECT id FROM user_details WHERE name = ?',
            [$email]
        );

        if ($page->getObjDB()->numRows() === 0) {
            // First-time KeyCloak login: create a local user record
            $vals = [
                'name'          => $email,
                'user_group_id' => 1,
                'email'         => $email,
                'first_name'    => $result['given_name'],
                'last_name'     => $result['family_name'],
                'token'         => $token,
                'login_type'    => 'keycloak',
            ];
            $id = $page->getObjDB()->insert('user_details', $vals);
            self::grantAllTaskPermissions($id, $page);
        }

        return self::logIn($email, $page);
    }

    /**
     * Authenticates a user against locally stored (encrypted) credentials.
     *
     * Looks up the user by email, then uses Bibliotheca_Page::valueExists() to
     * compare the submitted password against the stored encrypted value.
     * All failed attempts are logged to PHP's error_log.
     *
     * @param  array            $vals  Form input array. Expected keys: 'email', 'password'.
     * @param  Bibliotheca_Page $page  Page object providing the DB handle, session,
     *                                 and encryption helpers.
     * @return int|string|false        User ID on success, a message string if account
     *                                 is locked/unknown, or false on credential mismatch.
     */
    public static function localAuthenticate(array $vals, Bibliotheca_Page $page): mixed
    {
        $email = $vals['email'] ?? '';

        $userRecord = $page->getObjDB()->preparedSelect(
            'SELECT id, name, password FROM user_details WHERE name = ?',
            [$email]
        );

        if ($page->getObjDB()->numRows() === 0) {
            error_log("Failed login attempt: unknown email '$email'");
            return false;
        }

        if (empty($vals['password'])) {
            error_log("Failed login attempt: no password supplied for '$email'");
            return false;
        }

        $auth = $page->valueExists($userRecord, 'password', $vals['password']);
        if (is_numeric($auth) && $auth > 0) {
            return self::logIn($email, $page);
        }

        error_log("Failed login attempt: credentials do not match for '$email'");
        return false;
    }


    /****************************************************************/
    /* PRIVATE HELPERS                                              */
    /****************************************************************/

    /**
     * Finalises a login after credentials have been validated.
     *
     * Verifies the user exists in `user_details` and that their account is active
     * (current = 't') before delegating to User_Session::logInExternal().
     *
     * @param  string           $username The username (ORCiD ID or email) to log in.
     * @param  Bibliotheca_Page $page     Page object providing the DB handle and session.
     * @return int|string                 User ID on success, or a human-readable error
     *                                    message string if the account is locked or unknown.
     */
    private static function logIn(string $username, Bibliotheca_Page $page): mixed
    {
        $uid = $page->getObjDB()->preparedGetOne(
            'SELECT id FROM user_details WHERE name = ?',
            [$username]
        );

        if (!isset($uid) || !is_numeric($uid) || $uid <= 0) {
            return 'Your username does not exist in the PubLink database — please register to use this system';
        }

        $active = $page->getObjDB()->preparedGetOne(
            'SELECT current FROM user_details WHERE id = ?',
            [$uid]
        );

        if ($active !== 't') {
            return 'Your account is locked in the PubLink database — please contact the system administrator to unlock it';
        }

        return $page->getUserSession()->logInExternal($username);
    }

    /**
     * Fetches the primary email address for an ORCiD user via the ORCiD Members API.
     *
     * Makes a GET request to {Config::$ORCID_API_ADDRESS}{orcid}/email, parses the
     * XML response, and returns the first string that passes FILTER_VALIDATE_EMAIL.
     * Returns an empty string if no valid address is found.
     *
     * @param  string $orcid       The user's ORCiD identifier (e.g. '0000-0001-2345-6789').
     * @param  string $accessToken A valid ORCiD API access token for this user.
     * @return string              A validated email address, or '' if none could be retrieved.
     */
    private static function fetchOrcidEmail(string $orcid, string $accessToken): string
    {
        $url = Config::$ORCID_API_ADDRESS . $orcid . '/email';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_ENCODING       => '',
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_CONNECTTIMEOUT => 120,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.orcid+xml',
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $xml = simplexml_load_string((string) $response);
        if ($xml === false) {
            return '';
        }

        $addresses = $xml->xpath('//email:email');
        if (is_array($addresses)) {
            foreach ($addresses as $candidate) {
                $candidate = (string) $candidate;
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * Grants all currently defined task permissions to a newly created user.
     *
     * Inserts one row into `user_details_task` for every row in the `task` table,
     * giving the new user the default full set of task permissions.
     *
     * @param  int              $userId The `user_details.id` of the newly created user.
     * @param  Bibliotheca_Page $page   Page object providing the DB handle.
     * @return void
     */
    private static function grantAllTaskPermissions(int $userId, Bibliotheca_Page $page): void
    {
        $tasks = $page->getObjDB()->select('SELECT * FROM task');
        while ($task = $tasks->fetch()) {
            $page->getObjDB()->insert('user_details_task', [
                'user_details_id' => $userId,
                'task_id'         => $task['id'],
            ]);
        }
    }
}

?>