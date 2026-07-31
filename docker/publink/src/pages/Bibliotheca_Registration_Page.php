<?php
namespace Biblhertz\Publink\pages;

use Biblhertz\Publink\pages\Bibliotheca_Page;
use Biblhertz\Publink\pages\Bibliotheca_Content_Page;

/**
 * Bibliotheca_Registration_Page
 *
 * Renders the user registration page for the PubLink/Bibliotheca application.
 * Extends Bibliotheca_Page to inherit shared layout helpers (logo, modal support,
 * AdminLTE asset paths, etc.).
 *
 * The page offers two registration routes:
 * - ORCiD login/registration (recommended), rendered via
 *   Bibliotheca_Content_Page::getOrcidLogin().
 * - Local email/password registration via a POST form with fields:
 *   email, first_name, last_name, password1, password2.
 *
 * Usage:
 *   $page = new Bibliotheca_Registration_Page();
 *   $page->setRegisterTarget('/register');
 *   $page->setOrcidTarget('/orcid-auth');
 *   echo $page->getPage();
 *
 * @package Biblhertz\Publink\pages
 * @author  Chris Tomlinson
 * @since   March 2023
 */
class Bibliotheca_Registration_Page extends Bibliotheca_Page
{

	/****************************************************************/
	/*	INSTANCE VARIABLES											*/
	/****************************************************************/

	/** @var string Form action URL for the local email/password registration POST. */
	private string $registerTarget = '';

	/** @var string Form action URL for the ORCiD authentication redirect. */
	private string $orcidTarget = '';

	/** @var string URL for the "Back to Login" link. Defaults to index.html. */
	private string $loginTarget = 'index.html';


	/****************************************************************/
	/*	INTERFACE METHODS											*/
	/****************************************************************/

	/**
	 * Set the form action URL for the local email/password registration form.
	 *
	 * @param string $target URL to POST the registration form to.
	 */
	public function setRegisterTarget(string $target): void
	{
		$this->registerTarget = $target;
	}

	/**
	 * Set the form action URL used for the ORCiD authentication flow.
	 *
	 * @param string $target URL to redirect the user to for ORCiD login/registration.
	 */
	public function setOrcidTarget(string $target): void
	{
		$this->orcidTarget = $target;
	}

	/**
	 * Set the URL for the "Back to Login" link.
	 *
	 * @param string $target URL of the login page.
	 */
	public function setLoginTarget(string $target): void
	{
		$this->loginTarget = $target;
	}


	/****************************************************************/
	/*	OTHER METHODS												*/
	/****************************************************************/

	/**
	 * Render the full registration page as an HTML string.
	 *
	 * Outputs a standalone HTML document using the AdminLTE login-page layout.
	 * Includes:
	 * - ORCiD registration button (via Bibliotheca_Content_Page::getOrcidLogin()).
	 * - A local registration form (email, first name, last name, two password fields).
	 * - Modal support (head scripts + message placeholder from parent).
	 * - A hidden login_type=local input to distinguish local registrations from
	 *   ORCiD registrations on the receiving controller.
	 *
	 * @return string Complete HTML document as a string.
	 */
	public function getPage(): string
	{
		$adminLTE       = $this->getAdminLTEPath();
		$imageRoot      = Bibliotheca_Page::getImageRoot();
		$longTitle      = htmlspecialchars($this->getLongTitle(), ENT_QUOTES);
		$registerTarget = htmlspecialchars($this->registerTarget, ENT_QUOTES, 'UTF-8');
		$loginTarget    = htmlspecialchars($this->loginTarget, ENT_QUOTES, 'UTF-8');
		$orcidLogin     = Bibliotheca_Content_Page::getOrcidLogin($this->orcidTarget, 'Register');
		$hiddenInput    = self::makeHiddenInput('login_type', 'local');
		$logo           = $this->getLogo();
		$modalHead      = $this->getModalHead();
		$modalMessage   = $this->getModalMessage();
		$centralContent = $this->getCentralContent();

		return <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		  <meta charset="utf-8">
		  <meta name="viewport" content="width=device-width, initial-scale=1">
		  <title>{$longTitle}</title>

		  <!-- Google Font: Source Sans Pro -->
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
		  {$modalHead}
		</head>
		<body class="hold-transition login-page">

		<!-- Modals -->
		{$modalMessage}

		<div class="login-box">
		  <div class="login-logo"></div>
		  <!-- /.login-logo -->
		  <div class="card">
		    <div class="card-body login-card-body">
		      <p>{$logo}</p>
		      <p class="login-box-msg">Using an ORCiD is our recommended way of accessing PubLink</p>

		      {$orcidLogin}

		      <hr>
		      <p class="login-box-msg">OR Register using your email address</p>
		      <form action="{$registerTarget}" method="post" class="card-body login-card-body">
		        <div class="input-group mb-3">
		          <input type="email" class="form-control" placeholder="Email" id="email" name="email">
		          <div class="input-group-append">
		            <div class="input-group-text"><span class="fas fa-envelope"></span></div>
		          </div>
		        </div>

		        <div class="input-group mb-3">
		          <input type="text" class="form-control" placeholder="First name" id="first_name" name="first_name">
		          <div class="input-group-append">
		            <div class="input-group-text"><span class="fas fa-user"></span></div>
		          </div>
		        </div>

		        <div class="input-group mb-3">
		          <input type="text" class="form-control" placeholder="Last name" id="last_name" name="last_name">
		          <div class="input-group-append">
		            <div class="input-group-text"><span class="fas fa-user"></span></div>
		          </div>
		        </div>

		        <div class="input-group mb-3">
		          <input type="password" class="form-control" placeholder="Password" id="password1" name="password1">
		          <div class="input-group-append">
		            <div class="input-group-text"><span class="fas fa-key"></span></div>
		          </div>
		        </div>

		        <div class="input-group mb-3">
		          <input type="password" class="form-control" placeholder="Repeat Password" id="password2" name="password2">
		          <div class="input-group-append">
		            <div class="input-group-text"><span class="fas fa-key"></span></div>
		          </div>
		        </div>

		        <div class="input-group mb-3">
		          <button type="submit" class="btn btn-outline-secondary btn-block float-right"
		                  id="registerButton" name="registerButton">
		            <span class="fas fa-envelope"></span> Register with email
		          </button>
		          {$hiddenInput}
		        </div>
		      </form>

		      <div class="row">
		        <p class="login-box-msg">
		          <a href="{$loginTarget}" class="text-center">Back to Login</a>
		        </p>
		      </div>
		    </div>
		    <!-- /.login-card-body -->
		  </div>
		</div>
		<!-- /.login-box -->

		<div>{$centralContent}</div>

		<!-- Bootstrap 4 -->
		<script src="{$adminLTE}/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
		<!-- AdminLTE core -->
		<script src="{$adminLTE}/dist/js/adminlte.js"></script>
		</body>
		</html>
		HTML;
	}
}
