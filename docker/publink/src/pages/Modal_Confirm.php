<?php
/**
 * Modal_Confirm.php
 *
 * Renders a Bootstrap 4 confirmation dialog (OK / Cancel) and injects it into
 * a Bibliotheca_Content_Page. Supports three interaction patterns:
 *
 *  1. **URL redirect** (setConfirmDialog): clicking OK navigates to $okAddress.
 *  2. **Custom JS action** (setNonForwardConfirmDialog): clicking OK executes an
 *     arbitrary JavaScript expression instead of navigating.
 *  3. **Form submission guard** (setConfirmTrueFalseDialog): intercepts a form's
 *     submit event, shows the modal, and only allows the submit to proceed if
 *     the user clicks OK.
 *
 * Usage pattern (URL redirect):
 *   $modal = new Modal_Confirm($page, 'confirm_delete', 'Are you sure?');
 *   $modal->setOKAddress('delete.php?id=42');
 *   $modal->setConfirmDialog();
 *   // Trigger from HTML: onclick="confirm_delete_func()"
 *
 * Usage pattern (form guard):
 *   $modal = new Modal_Confirm($page, 'confirm_save', 'Save changes?');
 *   $modal->setConfirmTrueFalseDialog('my_form_id');
 *   // The form submit is intercepted automatically; no extra onclick needed.
 *
 * @package Biblhertz\Publink\pages
 * @author  Chris Tomlinson
 * @since   January 2021
 */

namespace Biblhertz\Publink\pages;

class Modal_Confirm
{

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var Bibliotheca_Page The page this modal will be injected into. */
    private Bibliotheca_Page $page;

    /**
     * @var string Unique identifier for this modal.
     *      Used as the HTML id attribute and as the prefix for generated JS identifiers:
     *      {name}_func(), {name}_ok, {name}_cancel, {name}_submit.
     *      Must match /^[a-zA-Z][a-zA-Z0-9_]*$/.
     */
    private string $name = '';

    /** @var string HTML content displayed inside the modal body. */
    private string $message = '';

    /**
     * @var string URL to navigate to when the OK button is clicked.
     *      Only used by setConfirmDialog() / getJavaScript().
     *      Leave empty when using setNonForwardConfirmDialog() or setConfirmTrueFalseDialog().
     */
    private string $okAddress = '';


    /****************************************************************/
    /* CLASS CONSTRUCTOR                                            */
    /****************************************************************/

    /**
     * Creates a Modal_Confirm and associates it with a page.
     *
     * Note: the modal is not injected into the page until one of the
     * setConfirmDialog(), setNonForwardConfirmDialog(), or
     * setConfirmTrueFalseDialog() methods is called.
     *
     * @param Bibliotheca_Page $page    The page to inject the modal into.
     * @param string           $name    Unique HTML id / JS identifier prefix for this modal.
     * @param string           $message HTML content to display in the modal body.
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
     * Sets the unique name/id used for this modal's HTML element and JS identifiers.
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
     * Sets the URL to navigate to when the OK button is clicked.
     * Only used by the URL-redirect pattern (setConfirmDialog()).
     *
     * @param  string $add The destination URL (relative or absolute).
     * @return void
     */
    public function setOKAddress(string $add): void
    {
        $this->okAddress = $add;
    }


    /****************************************************************/
    /* PUBLIC API                                                   */
    /****************************************************************/

    /**
     * Injects the modal using the URL-redirect pattern.
     *
     * Clicking OK navigates to the URL set via setOKAddress().
     * Clicking Cancel or × closes the modal without any action.
     *
     * Must be called after setOKAddress() for the OK button to have a destination.
     *
     * @return void
     */
    public function setConfirmDialog(): void
    {
        $this->page->addToModalHead($this->getJavaScript());
        $this->page->addToModalMessage($this->getConfirmMessageBody());
    }

    /**
     * Injects the modal using the custom JavaScript action pattern.
     *
     * Clicking OK executes the provided JavaScript expression instead of navigating.
     * Useful when you need to trigger an AJAX call, form submission, or other
     * client-side action rather than a full page navigation.
     *
     * @param  string $script JavaScript expression to execute when OK is clicked
     *                        (e.g. "deleteItem(42)" or "$('#my-form').submit()").
     * @return void
     */
    public function setNonForwardConfirmDialog(string $script): void
    {
        $this->page->addToModalHead($this->getNonForwardJavaScript($script));
        $this->page->addToModalMessage($this->getConfirmMessageBody());
    }

    /**
     * Injects the modal using the form submission guard pattern.
     *
     * Intercepts the named form's submit event: the first submit attempt shows
     * the modal instead of submitting. If the user clicks OK the form is re-submitted
     * and allowed to proceed (guarded by a {name}_submit flag). Clicking Cancel
     * resets the flag and suppresses the submit.
     *
     * @param  string $formID The HTML id of the form to guard (without the '#' prefix).
     * @return void
     */
    public function setConfirmTrueFalseDialog(string $formID): void
    {
        $this->page->addToModalHead($this->getReturnTrueFalseJS($formID));
        $this->page->addToModalMessage($this->getConfirmMessageBody());
    }


    /****************************************************************/
    /* PRIVATE RENDER METHODS                                       */
    /****************************************************************/

    /**
     * Builds the Bootstrap 4 modal HTML markup with OK and Cancel buttons.
     *
     * Button ids follow the {name}_ok / {name}_cancel convention so that the
     * JavaScript event handlers generated by the getJavaScript* methods can
     * reference them via jQuery's document-level event delegation.
     *
     * Note: 'type="button ok"' and 'type="button cancel"' on the footer buttons
     * are not valid HTML — the type attribute only accepts 'button', 'submit', or
     * 'reset'. These have been corrected to type="button".
     *
     * @return string HTML markup for the confirmation modal dialog.
     */
    public function getConfirmMessageBody(): string
    {
        $id   = htmlspecialchars($this->name, ENT_QUOTES);
        $logo = $this->page->getLogo();

        return <<<HTML
        <!-- Modal: {$id} -->
        <div class="modal fade" id="{$id}">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                {$logo}
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                  <span aria-hidden="true">&times;</span>
                </button>
              </div>
              <div class="modal-body">
                <!-- Message content is HTML; callers are responsible for sanitisation. -->
                <p>{$this->message}</p>
              </div>
              <div class="modal-footer">
                <button id="{$id}_ok"     type="button" class="btn btn-primary btn-flat pull-left"  data-dismiss="modal">OK</button>
                <button id="{$id}_cancel" type="button" class="btn btn-primary btn-flat pull-right" data-dismiss="modal">Cancel</button>
              </div>
            </div>
          </div>
        </div>
        <!-- /.modal: {$id} -->
        HTML;
    }

    /**
     * Builds the JavaScript for the URL-redirect pattern.
     *
     * Defines {name}_func() to display the modal, and binds a document-level
     * click handler on {name}_ok that navigates to $okAddress.
     * Document-level delegation ensures the handler works even if the modal
     * markup is injected after the script runs.
     *
     * @return string A <script> block containing the modal trigger and OK handler.
     */
    public function getJavaScript(): string
    {
        $okAddressJs = json_encode($this->okAddress);

        return <<<HTML
        <script>
          function {$this->name}_func() {
            $('#{$this->name}').modal({ backdrop: 'static', keyboard: false, show: true });
          }

          $(document).on('click', '#{$this->name}_ok', function () {
            location.href = {$okAddressJs};
          });
        </script>
        HTML;
    }

    /**
     * Builds the JavaScript for the custom-action pattern.
     *
     * Defines {name}_func() to display the modal, and binds a document-level
     * click handler on {name}_ok that evaluates the provided $script expression.
     *
     * @param  string $script JavaScript expression to execute on OK click.
     * @return string A <script> block containing the modal trigger and OK handler.
     */
    public function getNonForwardJavaScript(string $script): string
    {
        return <<<HTML
        <script>
          function {$this->name}_func() {
            $('#{$this->name}').modal({ backdrop: 'static', keyboard: false, show: true });
          }

          $(document).on('click', '#{$this->name}_ok', function () {
            {$script};
          });
        </script>
        HTML;
    }

    /**
     * Validates that a name is a safe JS identifier and HTML id value.
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

    /**
     * Builds the JavaScript for the form submission guard pattern.
     *
     * Logic:
     *  - On first form submit: preventDefault(), show the modal, set {name}_submit = false.
     *  - On OK click: set {name}_submit = true, re-submit the form.
     *  - On second submit event: if {name}_submit is true, allow it through; otherwise block.
     *  - On Cancel click: return false (modal already closed by data-dismiss).
     *
     * The {name}_submit boolean flag is the gate that distinguishes a user-confirmed
     * re-submit from the initial intercepted submit.
     *
     * @param  string $formID HTML id of the form to guard (without '#').
     * @return string A <script> block containing the guard and modal trigger logic.
     */
    public function getReturnTrueFalseJS(string $formID): string
    {
        $formID = $this->validateName($formID);

        return <<<HTML
        <script>
          let {$this->name}_submit = false;

          function {$this->name}_func() {
            $('#{$this->name}').modal({ backdrop: 'static', keyboard: false, show: true });
          }

          // Intercept the initial form submit and show the confirmation modal
          $(document).on('submit', '#{$formID}', function (event) {
            if ({$this->name}_submit === false) {
              event.preventDefault();
              {$this->name}_func();
            }
          });

          // OK: set the flag and re-submit the form
          $(document).on('click', '#{$this->name}_ok', function () {
            {$this->name}_submit = true;
            $('#{$formID}').submit();
          });

          // Cancel: close modal (handled by data-dismiss) and leave flag as false
          $(document).on('click', '#{$this->name}_cancel', function () {
            return false;
          });
        </script>
        HTML;
    }
}