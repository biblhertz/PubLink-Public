<?php
/**
 * Bibliotheca_Content_Page.php
 *
 * Concrete page class for the Bibliotheca Intranet. Extends Bibliotheca_Intranet_Page
 * to provide the full AdminLTE 3 shell — navbar, sidebar, content area, footer,
 * and all required asset includes — as well as a separate login page template.
 *
 * Responsibilities:
 *  - Assembling the complete HTML document for authenticated intranet pages via getPage()
 *  - Rendering the login page (local email + ORCiD OAuth) via getLoginPage()
 *  - Building the AdminLTE sidebar navigation tree from the current user's data
 *  - Providing JS polling helpers for session keep-alive and background job notifications
 *
 * @package Biblhertz\Publink\pages
 * @author  Chris Tomlinson
 * @since   March 2023
 */

namespace Biblhertz\Publink\pages;

use Biblhertz\Publink\pages\Bibliotheca_Intranet_Page;
use Biblhertz\Publink\pages\Modal_Alert;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\om\SerializedObject;
use \Exception;

class Bibliotheca_Content_Page extends Bibliotheca_Intranet_Page
{

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var bool When true, renders the AdminLTE animated shake preloader on page load. */
    private bool $shake = false;

    /****************************************************************/
    /* LOGIN PAGE INSTANCE VARIABLES                                */
    /****************************************************************/

    /** @var string Form action URL that receives the posted local-login credentials. */
    private string $loginTarget = '';

    /** @var string Redirect target URL used after a successful ORCiD OAuth callback. */
    private string $orcidLoginTarget = '';

    /** @var string Redirect target URL used after a successful KeyCloak OAuth callback. */
    private string $keyCloakLoginTarget = '';

    /** @var string Pre-filled email address shown in the login form (e.g. after a failed attempt). */
    private string $loginName = '';


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Initialises the page, delegates authentication setup to the parent,
     * and attaches a hidden re-usable modal alert to the page.
     *
     * @param int $login 0 for a public/login page context; non-zero to enforce authentication.
     */
    public function __construct(int $login = 0)
    {
        try {
            parent::__construct($login);
            $this->getModal();
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Enables the AdminLTE animated shake preloader that displays the site logo
     * with a shake animation while the page is loading.
     *
     * @return void
     */
    public function shakeOnLoad(): void
    {
        $this->shake = true;
    }


    /****************************************************************/
    /* LOGIN PAGE INTERFACE METHODS                                 */
    /****************************************************************/

    /**
     * Sets the form action URL for the local email/password login form.
     *
     * @param  string $target Relative or absolute URL (e.g. 'login.php').
     * @return void
     */
    public function setLoginTarget(string $target): void
    {
        $this->loginTarget = $target;
    }

    /**
     * Sets the redirect target used after a successful ORCiD OAuth callback.
     *
     * @param  string $target Relative URL to redirect to (e.g. 'dashboard.html').
     * @return void
     */
    public function setOrcidLoginTarget(string $target): void
    {
        $this->orcidLoginTarget = $target;
    }

    /**
     * Sets the redirect target used after a successful KeyCloak OAuth callback.
     * Currently unused — KeyCloak support is commented out pending re-enablement.
     *
     * @param  string $target Relative URL to redirect to.
     * @return void
     */
    public function setKeyCloakLoginTarget(string $target): void
    {
        $this->keyCloakLoginTarget = $target;
    }

    /**
     * Pre-fills the email field in the local login form.
     * Typically used to repopulate the form after a failed login attempt.
     *
     * @param  string $name Email address to pre-fill.
     * @return void
     */
    public function setLoginName(string $name): void
    {
        $this->loginName = $name;
    }


    /****************************************************************/
    /* PRIVATE HELPERS                                              */
    /****************************************************************/

    /**
     * Creates and attaches a hidden re-usable Modal_Alert to the page.
     *
     * The modal's message area is an empty div with id="hidden_modal_message".
     * JavaScript on the page injects content into that div at runtime (e.g. job
     * completion notifications via jobMessageCheck()).
     *
     * @return void
     */
    private function getModal(): void
    {
        $modal = new Modal_Alert($this, 'hidden_modal', '<div id="hidden_modal_message"></div>');
        $modal->setConfirmDialog();
    }


    /****************************************************************/
    /* PAGE TEMPLATE — MAIN AUTHENTICATED PAGE                      */
    /****************************************************************/

    /**
     * Assembles and returns the complete HTML document for an authenticated intranet page.
     *
     * The document structure follows the AdminLTE 3 layout:
     *   - <head>: all CSS/JS asset includes, favicon, modal head scripts
     *   - Optional shake preloader (if shakeOnLoad() was called)
     *   - Modals, navbar, sidebar (with dynamic navigation tree), content wrapper
     *   - Footer, control sidebar, all JS assets, session/job polling scripts
     *
     * Page content is injected via getCentralContent() (implemented in subclasses or parent).
     * The sidebar navigation tree is built dynamically from the current user's data.
     *
     * @return string Complete HTML document as a string, ready to echo to the client.
     */
    public function getPage(): string
    {
        $adminLTE    = $this->getAdminLTEPath();
        $imageRoot   = Bibliotheca_Page::getImageRoot();
        $jsRoot      = Bibliotheca_Page::getJSRoot();
        $cssRoot     = Bibliotheca_Page::getCssRoot();
        $logo        = $imageRoot.Config::$LOGO;
        $year        = date('Y');
        $plainHeading = strip_tags($this->getHeading());

        $page = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <base href="/">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>{$this->getTitle()}</title>

          <style>html { font-size: 12px; }</style>

          <!-- Font Awesome -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/fontawesome-free/css/all.min.css">
          <!-- Ionicons -->
          <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
          <!-- Tempusdominus Bootstrap 4 -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
          <!-- iCheck -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/icheck-bootstrap/icheck-bootstrap.min.css">
          <!-- JQVMap -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/jqvmap/jqvmap.min.css">
          <!-- AdminLTE theme -->
          <link rel="stylesheet" href="{$adminLTE}/dist/css/adminlte.min.css">
          <!-- overlayScrollbars -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
          <!-- Daterange picker -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/daterangepicker/daterangepicker.css">
          <!-- Summernote -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/summernote/summernote-bs4.min.css">
          <!-- Dropzone -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/dropzone/dropzone.css">
          <!-- DataTables -->
          <link rel="stylesheet" href="{$cssRoot}/datatables.min.css">
          <!-- Custom scrollbars -->
          <link rel="stylesheet" href="{$cssRoot}/scrollbars.css">
          <!-- Roboto font -->
          <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&display=swap" rel="stylesheet">
          <!-- Favicon -->
          <link rel="icon" type="image/png" href="{$imageRoot}bh_icon.png" sizes="32x32">

          <!-- jQuery (must precede plugins that depend on it) -->
          <script src="{$adminLTE}/plugins/jquery/jquery.min.js"></script>
          <!-- Dropzone -->
          <script src="{$adminLTE}/plugins/dropzone/dropzone.js"></script>
          <!-- DataTables -->
          <script src="{$jsRoot}/datatables.min.js"></script>

          <!-- Modal head scripts -->
          {$this->getModalHead()}
        </head>
        <body class="hold-transition sidebar-mini layout-fixed">
        <div class="wrapper">
        HTML;

        // Optional animated logo preloader
        if ($this->shake) {
            $page .= <<<HTML
            <!-- Preloader -->
            <div class="preloader flex-column justify-content-center align-items-center">
              <img class="animation__shake"
                   src="{$logo}"
                   alt="{$plainHeading}"
                   height="55" width="256">
            </div>
            HTML;
        }

        // Modal markup + top navbar
        $userName    = htmlspecialchars($this->getUser()->getName(), ENT_QUOTES);
        $userLogin   = $this->getUser()->getLoginType();
        $userHandle  = htmlspecialchars($this->getUser()->getUserName(), ENT_QUOTES);
        $shortTitle  = htmlspecialchars($this->getShortTitle(), ENT_QUOTES);

        $page .= $this->getModalMessage();

        $page .= <<<HTML
        <nav class="main-header navbar navbar-expand navbar-blue navbar-light">
          <!-- Left navbar: sidebar toggle -->
          <ul class="navbar-nav">
            <li class="nav-item">
              <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                <i class="fas fa-bars"></i>
              </a>
            </li>
          </ul>

          <!-- Right navbar: fullscreen + logout -->
          <ul class="navbar-nav ml-auto">
            <li class="nav-item">
              <a class="nav-link" data-widget="fullscreen" href="#" role="button">
                <i class="fas fa-expand-arrows-alt"></i>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="profile.html?logout=true" role="button">
                <i class="fas fa-sign-out-alt"></i>
              </a>
            </li>
          </ul>
        </nav>
        <!-- /.navbar -->

        <!-- Main Sidebar -->
        <aside class="main-sidebar sidebar-light-primary elevation-4">
          <!-- Brand logo -->
          <a href="#" class="brand-link">
            <img src="{$imageRoot}bh_icon.png"
                 alt="{$this->getTitle()}"
                 class="brand-image img-circle elevation-3"
                 style="opacity:.8">
            <span class="brand-text font-weight-bold">{$shortTitle}</span>
          </a>

          <div class="sidebar">
            <!-- User name panel -->
            <div class="user-panel mt-3 pb-3 mb-3 d-flex">
              <div class="image">
                <img src="{$imageRoot}user_icon.png" class="img-circle elevation-2" alt="{$userName}">
              </div>
              <div class="info">
                <a href="profile.html" class="d-block">{$userName}</a>
              </div>
            </div>

            <!-- User identity provider panel (ORCiD or email icon) -->
            <div class="user-panel mt-3 pb-3 mb-3 d-flex">
        HTML;

        // Show ORCiD logo or email icon depending on login type
        if (!strcmp('orcid', $userLogin)) {
            $page .= <<<HTML
              <div class="image">
                <img src="{$imageRoot}ORCIDiD_icon24x24.png" alt="ORCiD">
              </div>
            HTML;
        } else {
            $page .= '<div class="info"><i class="fas fa-envelope nav-icon"></i></div>';
        }

        $page .= '<div class="info">';

        // Link to ORCiD profile or local anchor depending on login type
        if (!strcmp('orcid', $userLogin)) {
            $page .= "<a href=\"https://orcid.org/{$userHandle}\" target=\"{$userHandle}\" class=\"d-block\">{$userHandle}</a>";
        } else {
            $page .= "<a href=\"#{$userHandle}\" target=\"{$userHandle}\" class=\"d-block\">{$userHandle}</a>";
        }

        $breadCrumb = strlen($plainHeading) < 30 ? $plainHeading : substr($plainHeading, 0, 30) . " ....";
        $page .= <<<HTML
            </div>
            </div>
            <!-- /.user identity panel -->

            <!-- Sidebar navigation menu -->
            <nav class="mt-2">
              <ul class="nav nav-pills nav-sidebar flex-column menu-open"
                  data-widget="treeview" role="menu" data-accordion="false">
                {$this->getSidebarMenu()}
              </ul>
            </nav>
            <!-- /.sidebar-menu -->
          </div>
          <!-- /.sidebar -->
        </aside>
        <!-- /.main-sidebar -->

        <!-- Content Wrapper -->
        <div class="content-wrapper">
          <!-- Page header / breadcrumb -->
          <div class="content-header">
            <div class="container-fluid">
              <div class="row mb-2">
                <div class="col-sm-10">
                  <div class="h4 m-0">{$this->getHeading()}</div>
                </div>
                <div class="col-sm-2">
                  <ol class="breadcrumb float-sm-right small">
                    <li class="breadcrumb-item"><a href="#">Home</a></li>
                    <li class="breadcrumb-item active">{$breadCrumb}</li>
                  </ol>
                </div>
              </div>
            </div>
          </div>
          <!-- /.content-header -->

          <!-- Main content -->
          <section class="content">
            <div class="container-fluid">
              {$this->getCentralContent()}
            </div>
          </section>
          <!-- /.content -->
        </div>
        <!-- /.content-wrapper -->

        <footer class="main-footer">
          <strong>&copy; {$year}</strong> <b>Version</b> 1.0
          <div class="float-right d-none d-sm-inline-block">
            {$this->getLogo()}
          </div>
        </footer>

        <!-- Control Sidebar (reserved for future use) -->
        <aside class="control-sidebar control-sidebar-dark"></aside>
        <!-- /.control-sidebar -->
        </div>
        <!-- /.wrapper -->

        <!-- jQuery UI -->
        <script src="{$adminLTE}/plugins/jquery-ui/jquery-ui.min.js"></script>
        <!-- Resolve jQuery UI / Bootstrap tooltip conflict -->
        <script>$.widget.bridge('uibutton', $.ui.button);</script>
        <!-- Bootstrap 4 -->
        <script src="{$adminLTE}/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
        <!-- ChartJS -->
        <script src="{$adminLTE}/plugins/chart.js/Chart.min.js"></script>
        <!-- JQVMap -->
        <script src="{$adminLTE}/plugins/jqvmap/jquery.vmap.min.js"></script>
        <script src="{$adminLTE}/plugins/jqvmap/maps/jquery.vmap.usa.js"></script>
        <!-- jQuery Knob -->
        <script src="{$adminLTE}/plugins/jquery-knob/jquery.knob.min.js"></script>
        <!-- Moment.js + Daterange picker -->
        <script src="{$adminLTE}/plugins/moment/moment.min.js"></script>
        <script src="{$adminLTE}/plugins/daterangepicker/daterangepicker.js"></script>
        <!-- Tempusdominus Bootstrap 4 -->
        <script src="{$adminLTE}/plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
        <!-- Summernote -->
        <script src="{$adminLTE}/plugins/summernote/summernote-bs4.min.js"></script>
        <!-- overlayScrollbars -->
        <script src="{$adminLTE}/plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
        <!-- AdminLTE core -->
        <script src="{$adminLTE}/dist/js/adminlte.js"></script>

        {$this->loginCheck()}
        {$this->jobMessageCheck()}
        </body>
        </html>
        HTML;

        return $page;
    }


    /****************************************************************/
    /* PAGE TEMPLATE — LOGIN PAGE                                   */
    /****************************************************************/

    /**
     * Assembles and returns the complete HTML document for the login page.
     *
     * Renders an AdminLTE login-box layout containing:
     *  - ORCiD OAuth button (shown on remote Docker / MPCDF environments)
     *  - Local email + password form
     *  - Registration link
     *
     * Any content added via setCentralContent() is appended below the login box,
     * allowing subclasses to inject status messages or alerts.
     *
     * @return string Complete HTML document as a string, ready to echo to the client.
     */
    public function getLoginPage(): string
    {
        $adminLTE  = $this->getAdminLTEPath();
        $imageRoot = Bibliotheca_Page::getImageRoot();

        $str = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <base href="/">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>{$this->getLongTitle()}</title>

          <!-- Google Font -->
          <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
          <!-- Font Awesome -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/fontawesome-free/css/all.min.css">
          <!-- iCheck Bootstrap -->
          <link rel="stylesheet" href="{$adminLTE}/plugins/icheck-bootstrap/icheck-bootstrap.min.css">
          <!-- AdminLTE theme -->
          <link rel="stylesheet" href="{$adminLTE}/dist/css/adminlte.min.css">
          <!-- Favicon -->
          <link rel="icon" type="image/png" href="{$imageRoot}bh_icon.png" sizes="32x32">

          <!-- jQuery -->
          <script src="{$adminLTE}/plugins/jquery/jquery.min.js"></script>

          <!-- Modal head scripts -->
          {$this->getModalHead()}
        </head>
        <body class="hold-transition login-page">

        <!-- Modals -->
        {$this->getModalMessage()}

        <div class="login-box">
          <div class="login-logo"></div>
          <!-- /.login-logo -->
          <div class="card">
            <div class="card-body login-card-body">
              <p>{$this->getLogo()}</p>
        HTML;

        // ORCiD login button — shown on remote/MPCDF Docker deployments
        if (Config::$ORCID_INTEGRATION) {
            $str .= self::getOrcidLogin($this->orcidLoginTarget);
        }

        $loginTarget = htmlspecialchars($this->loginTarget, ENT_QUOTES);
        $loginName   = htmlspecialchars($this->loginName, ENT_QUOTES);

        $str .= <<<HTML
              <hr>
              <p class="login-box-msg">Sign in with your Email Address</p>
              <form action="{$loginTarget}" method="post" class="card-body login-card-body">
                <div class="input-group mb-3">
                  <input type="email" class="form-control" placeholder="Email Address"
                         id="email" name="email" value="{$loginName}">
                  <div class="input-group-append">
                    <div class="input-group-text">
                      <span class="fas fa-user"></span>
                    </div>
                  </div>
                </div>

                <div class="input-group mb-3">
                  <input type="password" class="form-control" placeholder="Password"
                         id="password" name="password">
                  <div class="input-group-append">
                    <div class="input-group-text">
                      <span class="fas fa-key"></span>
                    </div>
                  </div>
                </div>

                <div class="input-group mb-3">
                  <button type="submit" class="btn btn-outline-secondary btn-block float-right"
                          id="emailLoginButton" name="emailLoginButton">
                    <span class="fas fa-envelope"></span> Sign in
                  </button>
                </div>
              </form>
        HTML;

        // Registration link — hidden entirely when self-registration is disabled
        if (Config::$REGISTRATION_ENABLED) {
            $str .= <<<HTML
              <hr>
              <div class="row">
                <p class="login-box-msg">
                  <a href="register.html" class="text-center">Register</a>
                </p>
              </div>
        HTML;
        }

        $str .= <<<HTML
            </div>
            <!-- /.login-card-body -->
          </div>
        </div>
        <!-- /.login-box -->

        <div>{$this->getCentralContent()}</div>

        <!-- Bootstrap 4 -->
        <script src="{$adminLTE}/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
        <!-- AdminLTE core -->
        <script src="{$adminLTE}/dist/js/adminlte.min.js"></script>
        </body>
        </html>
        HTML;

        return $str;
    }


    /****************************************************************/
    /* STATIC LOGIN COMPONENT TEMPLATES                             */
    /****************************************************************/

    /**
     * Renders the ORCiD OAuth login button and associated popup-window JavaScript.
     *
     * Clicking the button opens the ORCiD authorisation page in a centred popup window.
     * When the popup closes it posts a 'closed' message back to the opener, which then
     * redirects to $target to complete the OAuth flow.
     *
     * @param  string $target   Relative URL to redirect to after a successful ORCiD login.
     * @param  string $register Button label text. Default: 'Sign in'.
     * @return string HTML + inline JavaScript for the ORCiD login section.
     */
    public static function getOrcidLogin(string $target, string $register = 'Sign in'): string
    {
        $imageRoot      = Bibliotheca_Page::getImageRoot();
        $siteRoot       = Bibliotheca_Page::getSiteRoot();
        $orcidUrlJs     = json_encode(Config::$ORCID_HTTP_ADDRESS);
        $redirectUrlJs  = json_encode($siteRoot . '/' . $target);
        $registerEsc    = htmlspecialchars($register, ENT_QUOTES);

        return <<<HTML
        <p class="login-box-msg">{$registerEsc} with your ORCiD</p>
        <div class="input-group mb-3">
          <button type="button" class="btn btn-outline-secondary btn-block float-right" id="loginButton" name="loginButton">
            <img src="{$imageRoot}ORCIDiD_icon24x24.png" alt="ORCiD">
            {$registerEsc}
          </button>
        </div>
        <script>
          $('#loginButton').click(function () {
            var w = 540, h = 700;
            var left = (screen.width  / 2) - (w / 2);
            var top  = (screen.height / 2) - (h / 2);
            window.open(
              {$orcidUrlJs},
              'orcidWindow',
              'toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes,' +
              'width=' + w + ',height=' + h + ',top=' + top + ',left=' + left
            );
          });

          // Called directly by the ORCiD popup via window.opener.messageFromChildWindow('closed')
          function messageFromChildWindow(msg) {
            if (msg === 'closed') {
              window.location.replace({$redirectUrlJs});
            }
          }
        </script>
        HTML;
    }


    /****************************************************************/
    /* SIDEBAR NAVIGATION METHODS                                   */
    /****************************************************************/

    /**
     * Assembles the complete sidebar navigation tree for the current user.
     *
     * Combines the standard user menu sections with the super-user section
     * (if applicable) and appends the menu-activation JavaScript.
     *
     * @return string HTML for all sidebar <li> items plus the activation script.
     */
    protected function getSidebarMenu(): string
    {
        $menu = $this->getMyOptions();
        if ($this->getUser()->getUserGroup() == Config::$SUPER_USER) {
            $menu .= $this->getSuperUserOptions();
        }
        return $menu . $this->getOpenScript();
    }

    /**
     * Returns the inline JavaScript that highlights the active sidebar link
     * by comparing each nav link's href against the current page URL.
     *
     * Runs on DOMContentLoaded via jQuery's $(document).ready().
     *
     * @return string <script> block containing the menuLoad() function.
     */
    public function getOpenScript(): string
    {
        return <<<HTML
        <script>
          $(document).ready(function () {
            menuLoad();
            startMenuPolling();
          });

          function menuLoad() {
            var url = window.location.href;

            // Highlight direct nav links matching the current URL
            $('ul.nav-sidebar a').filter(function () {
              return url.match(new RegExp($(this).attr('href'), 'gi'));
            }).addClass('active');

            // Mark the active child link's parent link as active
            $('ul.nav-treeview a').filter(function () {
              return url.match(new RegExp($(this).attr('href'), 'gi'));
            }).parentsUntil('.nav-sidebar > .nav-treeview')
              .prev('a').addClass('active');

            // Keep all treeview parent items open
            $('.nav-sidebar .nav-item').has('.nav-treeview').addClass('menu-open');
          }

          function startMenuPolling() {
            if ($('#jatsMenu a[data-locked], #bibMenu a[data-locked]').length === 0) return;

            var poll = setInterval(function () {
              var jatsLocked = $('#jatsMenu a[data-locked]').length > 0;
              var bibLocked  = $('#bibMenu a[data-locked]').length > 0;

              if (!jatsLocked && !bibLocked) {
                clearInterval(poll);
                return;
              }

              if (jatsLocked) {
                $.get('services/jatsMenuService.php?menu=jatsMenu', function (html) {
                  $('#jatsMenu').html(html);
                  menuLoad();
                });
              }

              if (bibLocked) {
                $.get('services/jatsMenuService.php?menu=bibMenu', function (html) {
                  $('#bibMenu').html(html);
                  menuLoad();
                });
              }
            }, 5000);
          }
        </script>
        HTML;
    }

    /**
     * Renders the super-user section of the sidebar (user management, logs, job queue).
     * Only appended to the menu when the current user belongs to the SUPER_USER group.
     *
     * @return string HTML <li> tree for the super-user controls section.
     */
    private function getSuperUserOptions(): string
    {
        return <<<HTML
        <li class="nav-item">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>Super User Controls <i class="right fas fa-angle-left"></i></p>
          </a>
          <ul class="nav nav-treeview menu-open">
            <li class="nav-item">
              <a href="userList.html" class="nav-link">
                <i class="nav-icon fas fa-user"></i>
                <p>User Control</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="addUser.html" class="nav-link">
                <i class="nav-icon fas fa-regular fa-user"></i>
                <p>Add User</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="latestSessions.html" class="nav-link">
                <i class="nav-icon fas fa-cog"></i>
                <p>Latest Sessions</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="logViewer.html" class="nav-link">
                <i class="nav-icon fas fa-file"></i>
                <p>Logs</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="superUserControl.html" class="nav-link">
                <i class="nav-icon fas fa-tools"></i>
                <p>Job Queue Controls</p>
              </a>
            </li>
          </ul>
        </li>
        HTML;
    }

    /**
     * Assembles all standard user menu sections into named wrapper divs.
     * Wrappers are used by JavaScript to selectively show/hide menu groups.
     *
     * Sections: JATS files, BibTeX files, image annotation, image management, controls.
     *
     * @return string HTML containing all user-facing sidebar sections.
     */
    private function getMyOptions(): string
    {
        return '<div id="jatsMenu">' . $this->getMyJATSMenu() . '</div>'
             . '<div id="bibMenu">'  . $this->getMyBibtexMenu() . '</div>'
             . '<div id="annoMenu">' . $this->getMyAnnotateMenu() . '</div>'
             . $this->getImageMenu()
             . $this->getControlMenu();
    }

    /**
     * Builds the JATS Files sidebar section.
     *
     * Lists an "Upload and Process JATS File" link followed by one entry per
     * JATS article owned by the current user. Read-only articles are rendered
     * as disabled links.
     *
     * @return string HTML <li> tree for the JATS files section.
     */
    public function getMyJATSMenu(): string
    {
        $html = <<<HTML
        <li class="nav-item">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-file"></i>
            <p>JATS Files <i class="right fas fa-angle-left"></i></p>
          </a>
          <ul class="nav nav-treeview menu-open">
            <li class="nav-item">
              <a href="uploadJATS.html" class="nav-link">
                <i class="fas fa-file nav-icon"></i>
                <p>Upload and Process JATS File</p>
              </a>
            </li>
        HTML;

        $jatsFiles = $this->getUser()->getMyArticlesAsResultSet();
        while ($jats = $jatsFiles->fetch()) {
            $object  = new SerializedObject($this->getObjDB(), $jats['id']);
            $article = unserialize($object->getObject());
            if ($article->isReadOnly()) {
                $link = "<a href='#' class='nav-link disabled' data-locked='true'>";
                $icon = '<i class="fas fa-spinner fa-spin fa-fw nav-icon"></i>';
            } elseif ($article->getReferenceCheck()) {
                $link = "<a href=\"article.html?oid={$jats['id']}\" class=\"nav-link\">";
                $icon = '<i class="fas fa-star nav-icon text-warning"></i>';
            } else {
                $link = "<a href=\"article.html?oid={$jats['id']}\" class=\"nav-link\">";
                $icon = '<i class="fas fa-file nav-icon"></i>';
            }
            $label = htmlspecialchars(substr($jats['name'], 0, 30), ENT_QUOTES);
            $html .= <<<HTML
            <li class="nav-item">
              {$link}
                {$icon}
                <p>{$label}</p>
              </a>
            </li>
            HTML;
        }

        return $html . '</ul></li>';
    }

    /**
     * Builds the BibTeX Files sidebar section.
     *
     * Lists an "Upload and Process BibTex File" link followed by one entry per
     * reference collection owned by the current user. Read-only collections are
     * rendered as disabled links.
     *
     * @return string HTML <li> tree for the BibTeX files section.
     */
    public function getMyBibtexMenu(): string
    {
        $html = <<<HTML
        <li class="nav-item">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-file"></i>
            <p>BibTex Files <i class="right fas fa-angle-left"></i></p>
          </a>
          <ul class="nav nav-treeview menu-open">
            <li class="nav-item">
              <a href="uploadJATS.html?bib=true" class="nav-link">
                <i class="fas fa-file nav-icon"></i>
                <p>Upload and Process BibTex File</p>
              </a>
            </li>
        HTML;

        $bibFiles = $this->getUser()->getMyReferenceCollectionsAsResultSet();
        while ($bib = $bibFiles->fetch()) {
            $object  = new SerializedObject($this->getObjDB(), $bib['id']);
            $article = unserialize($object->getObject());
            if ($article->isReadOnly()) {
                $link = "<a href='#' class='nav-link disabled' data-locked='true'>";
                $icon = '<i class="fas fa-spinner fa-spin fa-fw nav-icon"></i>';
            } elseif ($article->getReferenceCheck()) {
                $link = "<a href=\"referenceCollection.html?oid={$bib['id']}\" class=\"nav-link\">";
                $icon = '<i class="fas fa-star nav-icon text-warning"></i>';
            } else {
                $link = "<a href=\"referenceCollection.html?oid={$bib['id']}\" class=\"nav-link\">";
                $icon = '<i class="fas fa-file nav-icon"></i>';
            }
            $label = htmlspecialchars(substr($bib['name'], 0, 30), ENT_QUOTES);
            $html .= <<<HTML
            <li class="nav-item">
              {$link}
                {$icon}
                <p>{$label}</p>
              </a>
            </li>
            HTML;
        }

        return $html . '</ul></li>';
    }

    /**
     * Builds the Image Annotation sidebar section.
     *
     * Provides links to the Mirador IIIF viewer integration and the user's
     * personal annotation list.
     *
     * @return string HTML <li> tree for the image annotation section.
     */
    public function getMyAnnotateMenu(): string
    {
        $imageRoot = Bibliotheca_Page::getImageRoot();

        return <<<HTML
        <li class="nav-item">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-image"></i>
            <p>Image Annotation <i class="right fas fa-angle-left"></i></p>
          </a>
          <ul class="nav nav-treeview menu-open">
            <li class="nav-item">
              <a href="annotationTools.html" class="nav-link">
                <i class="fas fa-pen nav-icon"></i>
                <p>Mirador Integration</p>
                &nbsp;&nbsp;&nbsp;
                <img src="{$imageRoot}mirador-logo.png" width="24" alt="Mirador">
              </a>
            </li>
            <li class="nav-item">
              <a href="annotationList.html" class="nav-link">
                <i class="fas fa-pen nav-icon"></i>
                <p>My Annotations</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="iiif_generator.html" class="nav-link">
                <i class="fas fa-file-code nav-icon"></i>
                <p>IIIF Generator</p>
              </a>
            </li>
          </ul>
        </li>
        HTML;
    }

    /**
     * Builds the My Controls sidebar section (user profile, task runner).
     *
     * @return string HTML <li> tree for the controls section.
     */
    private function getControlMenu(): string
    {
        return <<<HTML
        <li class="nav-item">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>My Controls <i class="right fas fa-angle-left"></i></p>
          </a>
          <ul class="nav nav-treeview">
            <li class="nav-item">
              <a href="profile.html" class="nav-link">
                <i class="fas fa-file nav-icon"></i>
                <p>My Files</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="myTasks.html" class="nav-link">
                <i class="nav-icon fas fa-puzzle-piece"></i>
                <p>Run Tasks</p>
              </a>
            </li>
          </ul>
        </li>
        HTML;
    }

    /**
     * Builds the Image Management sidebar link, shown only if the current user
     * has at least one image file associated with their account.
     *
     * @return string HTML <li> for the image management link, or an empty string if the user has no images.
     */
    private function getImageMenu(): string
    {
        $images = $this->getUser()->getMyImageFilesAsResultSet();
        if (!$images->fetch()) {
            return '';
        }

        return <<<HTML
        <li class="nav-item">
          <a href="myImages.html" class="nav-link">
            <i class="nav-icon fas fa-image"></i>
            <p>Image Management</p>
          </a>
        </li>
        HTML;
    }


    /****************************************************************/
    /* JAVASCRIPT POLLING HELPERS                                   */
    /****************************************************************/

    /**
     * Returns a <script> block that polls the server every 3 seconds to verify
     * the user's session is still active. If the server returns 'f' (false/logged out),
     * the browser is redirected to the site root, forcing a re-login.
     *
     * Endpoint: services/checkLogInService.php
     *
     * @return string Inline <script> block.
     */
    public function loginCheck(): string
    {
        $siteRootJs = json_encode(Bibliotheca_Page::getSiteRoot());

        return <<<HTML
        <script>
          function loggedIn() {
            $.ajax({ type: 'GET', url: 'services/checkLogInService.php' })
              .done(function (response) {
                if (response === 'f') {
                  window.location.replace({$siteRootJs});
                }
              });
          }
          setInterval(loggedIn, 3000);
        </script>
        HTML;
    }

    /**
     * Returns a <script> block that polls the server every 3 seconds for background
     * job completion messages. When a non-empty message is received it is injected into
     * the hidden modal and displayed to the user. If a loadFiles() function exists on the
     * page it is called to refresh any file listings after the job completes.
     *
     * Endpoint: services/checkJobService.php
     *
     * @return string Inline <script> block.
     */
    public function jobMessageCheck(): string
    {
        return <<<HTML
        <script>
          function jobMessage() {
            $.ajax({ type: 'GET', url: 'services/checkJobService.php' })
              .done(function (message) {
                if (message !== '') {
                  $('#hidden_modal_message').html(message);
                  hidden_modal_func();
                  if (typeof loadFiles === 'function') loadFiles();
                }
              });
          }
          setInterval(jobMessage, 3000);
        </script>
        HTML;
    }
}

?>
