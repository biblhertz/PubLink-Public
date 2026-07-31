<?php
namespace Biblhertz\Publink\om\presentation;

use Biblhertz\Publink\om\User;
use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\PDODatabase;
use Biblhertz\Publink\utilities\Encryption;
use PDO;
use PDOStatement;
use Biblhertz\Publink\pages\htmlPage;


/**
 * UserPresentation
 *
 * Presentation layer (View) for the {@see User} model. Renders user account
 * data as Bootstrap-styled HTML tables, editable/create forms with inline
 * jQuery logic, task-assignment forms, and session-history tables.
 *
 * Follows a passive-View pattern: all business logic lives in User and its
 * supporting services; this class is responsible only for HTML output.
 *
 * @example
 *   $view = new UserPresentation($user);
 *   echo $view->getAsTable();
 *   echo $view->getAsForm('/admin/updateUser.php', $_POST ?? []);
 *
 * @package    Biblhertz\Publink
 * @subpackage om\presentation
 * @author     Chris Tomlinson
 * @since      March 2023
 */
class UserPresentation {

    /********************************************************************/
    /* INSTANCE VARIABLES                                               */
    /********************************************************************/

    /** @var User The User model instance this presentation renders. */
    private User $user;


    /********************************************************************/
    /* CLASS CONSTRUCTOR                                                */
    /********************************************************************/

    /**
     * @param User $user The user model to render.
     */
    public function __construct(User $user) {
        $this->user = $user;
    }


    /********************************************************************/
    /* INTERFACE METHODS                                                */
    /********************************************************************/

    /**
     * Return the User model associated with this presentation.
     *
     * @return User
     */
    public function getUser(): User {
        return $this->user;
    }


    /********************************************************************/
    /* TABLE METHODS                                                    */
    /********************************************************************/

    /**
     * Render the user's core account details as a Bootstrap-bordered table.
     *
     * Columns: Username, Name, Email (mailto link), Role, Access Token.
     *
     * @return string HTML table markup.
     */
    public function getAsTable(): string {
        $safeUserName    = htmlspecialchars($this->user->getUserName(),    ENT_QUOTES, 'UTF-8');
        $safeFirstName   = htmlspecialchars($this->user->getFirstName(),   ENT_QUOTES, 'UTF-8');
        $safeLastName    = htmlspecialchars($this->user->getLastName(),    ENT_QUOTES, 'UTF-8');
        $safeEmail       = htmlspecialchars($this->user->getEmail(),       ENT_QUOTES, 'UTF-8');
        $safeRole        = htmlspecialchars($this->user->getRole(),        ENT_QUOTES, 'UTF-8');
        $safeAccessToken = !empty($this->user->getAccessToken())?
                            htmlspecialchars($this->user->getAccessToken(), ENT_QUOTES, 'UTF-8'):"";
        $emailLink       = htmlPage::makeLink("mailto:{$safeEmail}", $safeEmail);

        return <<<HTML
            <table class="table table-bordered" style="word-break:break-all;overflow-wrap:break-word;">
                <tr><th>Username</th>     <td>{$safeUserName}</td></tr>
                <tr><th>Name</th>         <td>{$safeFirstName} {$safeLastName}</td></tr>
                <tr><th>Email</th>        <td>{$emailLink}</td></tr>
                <tr><th>Role</th>         <td>{$safeRole}</td></tr>
                <tr><th>Access Token</th> <td>{$safeAccessToken}</td></tr>
            </table>
            HTML;
    }


    /********************************************************************/
    /* FORM METHODS                                                     */
    /********************************************************************/

    /**
     * Render the user edit / create form as HTML.
     *
     * Field values are resolved in priority order:
     *   1. Values passed in $vals (e.g. a re-posted form after a validation error).
     *   2. Current values stored on the User model.
     *
     * The form includes an inline jQuery snippet (`loginTypeFunc`) that
     * shows/hides the username row (ORCID) or password rows (local) depending
     * on the selected login type, and toggles HTML5 form validation accordingly.
     *
     * The ORCID username field enforces the standard iD format via an HTML
     * pattern attribute: `[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{4}`.
     *
     * A hidden `uid` field carries the user's database ID (0 for a new user).
     *
     * @param string $address  The form POST target URL.
     * @param array  $vals     Previously submitted values, typically $_POST.
     *                         Recognised keys: first_name, last_name, email,
     *                         user_name, user_group, current, login_type,
     *                         password1, password2.
     *
     * @return string HTML form + inline JavaScript markup.
     */
    public function getAsForm(string $address, array $vals): string {
        $u = $this->user;

        // Resolve field values: prefer $vals, fall back to model.
        $fn    = $vals['first_name'] ?? $u->getFirstName();
        $ln    = $vals['last_name']  ?? $u->getLastName();
        $email = $vals['email']      ?? $u->getEmail();
        $lt    = $vals['login_type'] ?? $u->getLoginType();
        $ug    = $vals['user_group'] ?? $u->getUserGroup();
        $cu    = $vals['current']    ?? ($u->getAccountEnabled() ? 't' : 'f');
        //$pw1   = $vals['password1']  ?? $u->getPassword();
        //$pw2   = $vals['password2']  ?? $u->getPassword();

        // Username is only pre-filled for ORCID login; blank otherwise.
        $un = ($lt === 'orcid') ? ($vals['user_name'] ?? $u->getUserName()) : '';
        $un = htmlspecialchars($un, ENT_QUOTES, 'UTF-8');

        $userGroups   = htmlPage::makeOption("user_group_id",
                            $u->getObjDB()->select("select * from user_group"),
                            "id", "name", $ug);
        $accountOpts  = htmlPage::makeOptionFromArray("current",
                            [['t', 'Enabled'], ['f', 'Disabled']], $cu);
        $loginTypeOpts = htmlPage::getEnumAsPullDown($u->getObjDB(),
                            "login_type", "user_details", "login_type", $lt);

        $firstNameInput  = htmlPage::makeInput("first_name", 30, "text",     50, $fn);
        $lastNameInput   = htmlPage::makeInput("last_name",  30, "text",     50, $ln);
        $emailInput      = htmlPage::makeInput("email",     100, "email",    50, $email);
        $password1Input  = htmlPage::makeInput("password1",  30, "PASSWORD", 30, "");
        $password2Input  = htmlPage::makeInput("password2",  30, "PASSWORD", 30, "");
        $saveButton      = htmlPage::makeButton("updateUser", "Save");
        $hiddenUid       = htmlPage::makeHiddenInput("uid", $u->getID());

        $safeAddress = htmlspecialchars($address, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <table class="table table-bordered">
            <form action="{$safeAddress}" id="myForm" method="POST">
                <tr><th>First Name</th>     <td>{$firstNameInput}</td></tr>
                <tr><th>Last Name</th>      <td>{$lastNameInput}</td></tr>
                <tr><th>Email</th>          <td>{$emailInput}</td></tr>
                <tr id="userNameRow"><th>User Name</th><td>
                    <input id="user_name" name="user_name" length=19 type="text"
                           maxlength=19 value="{$un}"
                           pattern="[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{4}" />
                </td></tr>
                <tr><th>User Type</th>      <td>{$userGroups}</td></tr>
                <tr><th>Account Status</th> <td>{$accountOpts}</td></tr>
                <tr><th>Login Type</th>     <td>{$loginTypeOpts}</td></tr>
                <tr id="password1Row"><th>Password</th>        <td>{$password1Input}</td></tr>
                <tr id="password2Row"><th>Repeat Password</th> <td>{$password2Input}</td></tr>
                <tr><th>Save</th><th>{$saveButton}</th></tr>
                {$hiddenUid}
            </form>
            </table>
            <script>
                function loginTypeFunc() {
                    const form   = document.getElementById('myForm');
                    const isOrcid = $('#login_type option:selected').text() === 'orcid';
                    $('#userNameRow').toggle(isOrcid);
                    $('#password1Row, #password2Row').toggle(!isOrcid);
                    form.noValidate = !isOrcid;
                }
                $('#login_type').on('change', loginTypeFunc);
                $(document).ready(loginTypeFunc);
            </script>
            HTML;
    }

    /**
     * Validate submitted user-form values.
     *
     * Rules:
     * - First name and last name are required.
     * - Local login: passwords must match and be ≥ 10 characters; email must
     *   not already exist (creation only — uid > 0 is exempt).
     * - Non-local (ORCID) login: username required and must not already exist.
     * - Email must pass FILTER_VALIDATE_EMAIL.
     *
     * @param array       $vals   Submitted form values (from $_POST).
     * @param PDODatabase $objDB  Database handle for uniqueness checks.
     * @param User        $user   The user being edited (ID = 0 for new users).
     *
     * @return array Human-readable error strings. Empty array = valid.
     */
    public static function validateForm(array $vals, PDODatabase $objDB, User $user): array {
        $errors = [];

        if (empty($vals['first_name'])) $errors[] = "First name must be set";
        if (empty($vals['last_name']))  $errors[] = "Last name must be set";

        if ($vals['login_type'] === 'local') {

            if ($vals['password1'] !== $vals['password2'])
                $errors[] = "The two passwords entered do not match";

            if (strlen($vals['password1']) < 10)
                $errors[] = "Password must have at least 10 characters";

            $exists = $objDB->preparedGetOne(
                "select id from user_details where name = ?", [$vals['email']]
            );
            if (is_numeric($exists) && $exists > 0 && $user->getID() === 0)
                $errors[] = "An account for that email address already exists";

        } else {

            if (empty($vals['user_name']))
                $errors[] = "Username must be set to the orchid id";

            $exists = $objDB->preparedGetOne(
                "select id from user_details where name = ?", [$vals['user_name']]
            );
            if (is_numeric($exists) && $exists > 0)
                $errors[] = "An account for that user ID already exists";
        }

        if (!filter_var($vals['email'], FILTER_VALIDATE_EMAIL))
            $errors[] = "Email address is not in the correct format";

        return $errors;
    }

    /**
     * Render a checkbox form listing all available system tasks.
     *
     * Tasks already assigned to this user (via user_details_task) are
     * pre-checked. Posts to $address with a hidden `uid` field.
     *
     * @param string $address  The form POST target URL.
     *
     * @return string HTML form markup.
     */
    public function getTasksAsForm(string $address): string {
        $rows  = '';
        $tasks = $this->user->getObjDB()->select("select * from task order by name");

        while ($task = $tasks->fetch()) {
            $selected  = $this->user->getObjDB()->preparedGetOne(
                "select id from user_details_task where user_details_id = ? and task_id = ?",
                [$this->user->getID(), $task['id']]
            );
            $checkbox  = htmlPage::makeCheckBox("task[{$task['id']}]", $task['id'], $selected);
            $safeName  = htmlspecialchars($task['name'], ENT_QUOTES, 'UTF-8');
            $rows     .= "<tr><td>{$safeName}</td><td>{$checkbox}</td></tr>";
        }

        $updateButton = htmlPage::makeButton("updateTasks", "Update");
        $hiddenUid    = htmlPage::makeHiddenInput("uid", $this->user->getID());
        $safeAddress  = htmlspecialchars($address, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <table class="table table-bordered">
            <form action="{$safeAddress}" method="POST">
                {$rows}
                <tr><td></td><td>{$updateButton}</td></tr>
                {$hiddenUid}
            </form>
            </table>
            HTML;
    }

    /**
     * Render a table of login sessions for this user.
     *
     * Fetches distinct session IDs from user_intranet_log, then retrieves the
     * most-recent entry per session for its timestamp. Each row links to the
     * session detail page (userSessionStats.html).
     *
     * @return string|false HTML table markup, or false if no sessions exist.
     */
    public function getUserSessionsAsTable(): string|false {
        $data = $this->user->getObjDB()->preparedStatement(
            "select distinct(user_session_id) from user_intranet_log where user_details_id = ?",
            [$this->user->getID()]
        );

        if ($this->user->getObjDB()->numRows() === 0) return false;

        $sql       = "select user_session_id, timestamp from user_intranet_log where user_session_id = ? order by id desc";
        $statement = $this->user->getObjDB()->getConnection()->prepare(
            $sql, [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]
        );

        $rows = '';
        while ($d = $data->fetch()) {
            $statement->execute([$d['user_session_id']]);
            $t          = $statement->fetch();
            $timestamp  = htmlPage::getTimeStampAsDateTimeArray($t['timestamp']);
            $safeDate   = htmlspecialchars((string)$timestamp[0], ENT_QUOTES, 'UTF-8');
            $safeTime   = htmlspecialchars((string)$timestamp[1], ENT_QUOTES, 'UTF-8');
            $safeSid    = htmlspecialchars($t['user_session_id'], ENT_QUOTES, 'UTF-8');
            $encodedSid = urlencode($t['user_session_id']);
            $encodedName = urlencode($this->user->getName());
            $uid        = (int)$this->user->getID();
            $link       = htmlPage::makeLink(
                "userSessionStats.html?sid={$encodedSid}&amp;name={$encodedName}&amp;uid={$uid}",
                $safeSid
            );
            $rows .= "<tr><td>{$safeDate} at {$safeTime}</td><td>{$link}</td></tr>";
        }

        return "<table class=\"table table-bordered\">{$rows}</table>";
    }


    /********************************************************************/
    /* UTILITY METHODS — STATIC                                        */
    /********************************************************************/

    /**
     * Render a list of users as a Bootstrap + DataTables HTML table.
     *
     * Each row links to user.html?uid=…. A unique table ID allows multiple
     * instances per page. DataTables is initialised with paging disabled.
     *
     * @param PDOStatement $users  Result set; rows must include: id, last_name,
     *                             first_name, username, email, user_group.
     *
     * @return string HTML table + inline script in a small-font container div.
     */
    public static function getUserListAsTable(PDOStatement $users): string {
        $tableId = uniqid("table_");
        $rows    = '';

        while ($user = $users->fetch()) {
            $uid           = (int)$user['id'];
            $safeLastName  = htmlspecialchars($user['last_name'],   ENT_QUOTES, 'UTF-8');
            $safeFirstName = htmlspecialchars($user['first_name'],  ENT_QUOTES, 'UTF-8');
            $safeUsername  = htmlspecialchars($user['username'],    ENT_QUOTES, 'UTF-8');
            $safeEmail     = htmlspecialchars($user['email'],       ENT_QUOTES, 'UTF-8');
            $safeGroup     = htmlspecialchars($user['user_group'],  ENT_QUOTES, 'UTF-8');
            $nameLink      = "<a href=\"user.html?uid={$uid}\">{$safeLastName}, {$safeFirstName}</a>";
            $rows         .= "<tr><td>{$nameLink}</td><td>{$safeUsername}</td><td>{$safeEmail}</td><td>{$safeGroup}</td></tr>";
        }

        return <<<HTML
            <div style="font-size:12px;">
                <table class="table table-bordered" id="{$tableId}">
                    <thead><tr class="small"><th>Name</th><th>ID</th><th>Email</th><th>User Group</th></tr></thead>
                    <tbody>{$rows}</tbody>
                </table>
                <script>
                    $(document).ready(function () {
                        $('#{$tableId}').DataTable({ paging: false, destroy: true });
                    });
                </script>
            </div>
            HTML;
    }

    /**
     * Render a single user record as a Bootstrap table from a raw database row.
     *
     * Used when a full User model is not available. The user group name is
     * resolved via a lookup on the user_group table.
     *
     * @param array       $user   Row with keys: last_name, first_name, name,
     *                            email, user_group_id, token.
     * @param PDODatabase $objDB  Database handle for the user_group lookup.
     *
     * @return string HTML table markup.
     */
    public static function getUserAsTable(array $user, PDODatabase $objDB): string {
        $group = $objDB->preparedGetOne(
            "select name from user_group where id = ?", [$user['user_group_id']]
        );

        $safeLastName  = htmlspecialchars($user['last_name'],  ENT_QUOTES, 'UTF-8');
        $safeFirstName = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
        $safeName      = htmlspecialchars($user['name'],       ENT_QUOTES, 'UTF-8');
        $safeEmail     = htmlspecialchars($user['email'],      ENT_QUOTES, 'UTF-8');
        $safeGroup     = htmlspecialchars((string)$group,      ENT_QUOTES, 'UTF-8');
        $safeToken     = htmlspecialchars($user['token'],      ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <table class="table table-bordered">
                <tr><th>Name</th>         <td>{$safeLastName}, {$safeFirstName}</td></tr>
                <tr><th>ID</th>           <td>{$safeName}</td></tr>
                <tr><th>Email</th>        <td>{$safeEmail}</td></tr>
                <tr><th>User Group</th>   <td>{$safeGroup}</td></tr>
                <tr><th>Access Token</th> <td>{$safeToken}</td></tr>
            </table>
            HTML;
    }
}
?>