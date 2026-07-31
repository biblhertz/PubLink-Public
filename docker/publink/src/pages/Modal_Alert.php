<?php
/**
 * Modal_Alert.php
 *
 * Renders a Bootstrap 4 modal dialog and injects it into a Bibliotheca_Content_Page.
 *
 * Usage pattern:
 *   1. Instantiate with the target page, a unique name, and the message HTML.
 *   2. Optionally call setOnPageLoad(true) to auto-show on render.
 *   3. Call setConfirmDialog() to inject the modal markup and its JS trigger
 *      function into the page's modal slots.
 *   4. From JavaScript on the page, invoke {name}_func() to display the modal
 *      programmatically at any time.
 *
 * The modal is Bootstrap 4 static-backdrop (cannot be dismissed by clicking
 * outside) with keyboard dismissal disabled, ensuring the user must explicitly
 * close it via the × button.
 *
 * @package Biblhertz\Publink\pages
 * @author  Chris Tomlinson
 * @since   March 2023
 */

namespace Biblhertz\Publink\pages;

class Modal_Alert
{

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var Bibliotheca_Page The page this modal will be injected into. 
     *  using superclass of Bibliotheca_Content_Page because Registration_Page also uses this class
    */
    private Bibliotheca_Page $page;

    /**
     * @var string Unique identifier for this modal.
     *             Used as the HTML id attribute and as the prefix for the JS trigger function
     *             ({name}_func()) and the body element ({name}_body).
     *             Must be a valid HTML id — no spaces or special characters.
     */
    private string $name = '';

    /** @var string HTML content displayed inside the modal body. */
    private string $message = '';

    /**
     * @var bool When true, the modal is shown automatically as soon as the page
     *           finishes loading via a $(document).ready() call.
     *           Defaults to false (modal is only shown when {name}_func() is called).
     */
    private bool $onPageLoad = false;


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Creates a Modal_Alert and associates it with a page.
     *
     * Note: the modal is not injected into the page until setConfirmDialog() is called.
     *
     * @param Bibliotheca_Page         $page    The page to inject the modal into.
     * @param string                   $name    Unique HTML id / JS function prefix for this modal.
     * @param string                   $message HTML content to display in the modal body.
     */
    public function __construct(Bibliotheca_Page $page, string $name, string $message)
    {
        $this->page    = $page;
        $this->name    = $this->validateName($name);
        $this->message = $message;
    }


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Replaces the target page for this modal.
     *
     * @param  Bibliotheca_Page $p The new target page.
     * @return void
     */
    public function setPage(Bibliotheca_Page $p): void
    {
        $this->page = $p;
    }

    /**
     * Sets the unique name / id used for this modal's HTML element and JS function.
     *
     * @param  string $name A valid HTML id string (no spaces or special characters).
     * @return void
     */
    public function setName(string $name): void
    {
        $this->name = $this->validateName($name);
    }

    /**
     * Sets the HTML content displayed inside the modal body.
     *
     * @param  string $message HTML string for the modal body.
     * @return void
     */
    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    /**
     * Controls whether the modal is shown automatically on page load.
     *
     * When set to true, a $(document).ready() block is included in the injected
     * JavaScript that calls {name}_func() as soon as the DOM is ready.
     *
     * @param  bool $bool True to auto-show on load, false for manual trigger only.
     * @return void
     */
    public function setOnPageLoad(bool $bool): void
    {
        $this->onPageLoad = $bool;
    }


    /****************************************************************/
    /* PUBLIC API                                                   */
    /****************************************************************/

    /**
     * Injects the modal markup and JavaScript into the associated page.
     *
     * Must be called for the modal to appear. Appends:
     *   - The JS trigger function to the page's modal head slot (rendered in <head>).
     *   - The Bootstrap modal HTML to the page's modal message slot (rendered in <body>).
     *
     * Typical usage:
     *   $modal = new Modal_Alert($page, 'confirm_delete', '<p>Are you sure?</p>');
     *   $modal->setConfirmDialog();
     *   // Later in JS: confirm_delete_func();
     *
     * @return void
     */
    public function setConfirmDialog(): void
    {
        $this->page->addToModalHead($this->getJavaScript());
        $this->page->addToModalMessage($this->getConfirmMessageBody());
    }


    /****************************************************************/
    /* PRIVATE RENDER METHODS                                       */
    /****************************************************************/

    /**
     * Builds the Bootstrap 4 modal HTML markup.
     *
     * Structure:
     *   .modal > .modal-dialog > .modal-content
     *     > .modal-header (site logo + close button)
     *     > .modal-body#{name}_body (message content)
     *
     * The body element id ({name}_body) allows JavaScript to dynamically
     * replace the modal's content before calling {name}_func() — used by
     * the job notification polling pattern in Bibliotheca_Content_Page.
     *
     * @return string HTML markup for the modal dialog.
     */
    private function getConfirmMessageBody(): string
    {
        $id   = htmlspecialchars($this->name, ENT_QUOTES);
        $logo = $this->page->getLogo();

        return <<<HTML
        <div class="modal fade" id="{$id}" role="dialog">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                {$logo}
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                  <span aria-hidden="true">&times;</span>
                </button>
              </div>
              <div class="modal-body" id="{$id}_body">
                <!-- Message content is HTML; callers are responsible for sanitisation. -->
                <div>{$this->message}</div>
              </div>
            </div>
            <!-- /.modal-content -->
          </div>
          <!-- /.modal-dialog -->
        </div>
        <!-- /.modal -->
        HTML;
    }

    /**
     * Builds the JavaScript block for this modal.
     *
     * Defines a global function {name}_func() that displays the modal using
     * Bootstrap's jQuery plugin with a static backdrop and keyboard dismissal
     * disabled, so the user must explicitly click the close button.
     *
     * If $onPageLoad is true, a $(document).ready() block is appended that
     * calls {name}_func() automatically once the DOM is ready.
     *
     * @return string A <script> block containing the modal trigger function.
     */
    /**
     * Validates that $name is a safe JS identifier and HTML id value.
     * Must start with a letter and contain only letters, digits, and underscores.
     *
     * @param  string $name Candidate name.
     * @return string The validated name.
     * @throws \InvalidArgumentException If the name does not match the required pattern.
     */
    private function validateName(string $name): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Modal name '{$name}' is invalid. Must start with a letter and contain only letters, digits, and underscores."
            );
        }
        return $name;
    }

    private function getJavaScript(): string
    {
        // $this->name is already validated as /^[a-zA-Z][a-zA-Z0-9_]*$/ — safe as a JS identifier and selector.
        $onLoad = $this->onPageLoad
            ? "$(document).ready(function () { {$this->name}_func(); });"
            : '';

        return <<<HTML
        <script>
          function {$this->name}_func() {
            $('#{$this->name}').modal({ backdrop: 'static', keyboard: false, show: true });
          }
          {$onLoad}
        </script>
        HTML;
    }
}