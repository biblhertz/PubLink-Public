<?php
namespace Biblhertz\Publink\components;

use Biblhertz\Publink\components\BootstrapFormComponent;
use Biblhertz\Publink\pages\Modal_Alert;
use Biblhertz\Publink\pages\Bibliotheca_Page;


/**
 * BootstrapFileUpload
 *
 * Renders a Dropzone.js-powered file upload widget inside a Bootstrap container.
 *
 * The component generates an HTML `<form class="dropzone">` and the accompanying
 * Dropzone configuration script. Optionally attaches two {@see Modal_Alert}
 * dialogs (success / error) and calls arbitrary JavaScript functions on upload
 * completion. Extra POST parameters can be injected via {@see addUploadItems()}.
 *
 * @example
 *   $upload = new BootstrapFileUpload();
 *   $upload->setName('imageUpload');
 *   $upload->setTarget('/api/upload.php');
 *   $upload->setPage($page);
 *   $upload->setMaxFiles(5);
 *   $upload->addUploadItems(['file_type' => "$('#typePulldown').val()"]);
 *   $upload->setActionFunctions(['refreshGallery();']);
 *   echo $upload->getComponent();
 *
 * @package    Biblhertz\Publink
 * @subpackage components
 * @author     Chris Tomlinson
 * @since      10th March 2016
 */
class BootstrapFileUpload extends BootstrapFormComponent {

    /****************************************************************/
    /* INSTANCE VARIABLES                                           */
    /****************************************************************/

    /** @var string POST target URL for the Dropzone form. */
    private string $target = '';

    /**
     * @var Bibliotheca_Page|null Page instance used to attach Modal_Alert dialogs.
     *                            When null, no modals are rendered.
     */
    private ?Bibliotheca_Page $page = null;

    /**
     * @var array JavaScript function calls (as strings) invoked after a
     *            successful upload batch, e.g. `['refreshGallery();']`.
     */
    protected array $actionFunctions = [];

    /** @var int Maximum number of files accepted in a single upload session. */
    private int $maxFiles = 50;

    /**
     * @var string Dropzone `init` function body injected into the config block.
     *             Populated by {@see addUploadItems()} to append extra POST data
     *             on the `sending` event.
     */
    private string $initFunction = '';


    /****************************************************************/
    /* INTERFACE METHODS                                            */
    /****************************************************************/

    /**
     * Set the POST target URL for file uploads.
     *
     * @param string $t Absolute or relative URL of the server-side handler.
     */
    public function setTarget(string $t): void {
        $this->target = $t;
    }

    /**
     * Attach a page instance to enable success/error Modal_Alert dialogs.
     *
     * When set, two modals are registered on the page: one for a successful
     * upload and one for an upload error.
     *
     * @param Bibliotheca_Page $page The current page context.
     */
    public function setPage(Bibliotheca_Page $page): void {
        $this->page = $page;
    }

    /**
     * Set JavaScript function calls to invoke after each successful upload.
     *
     * Each entry should be a self-contained JS statement, e.g. `'refreshList();'`.
     * All entries are concatenated and injected into the Dropzone success handler.
     *
     * @param array $funcs Array of JS statement strings.
     */
    public function setActionFunctions(array $funcs): void {
        $this->actionFunctions = $funcs;
    }

    /**
     * Set the maximum number of files accepted per upload session.
     *
     * Defaults to 50 if not called.
     *
     * @param int $files Maximum file count.
     */
    public function setMaxFiles(int $files): void {
        $this->maxFiles = $files;
    }

    /**
     * Inject extra POST parameters into the Dropzone `sending` event.
     *
     * Each key becomes a POST field name; each value should be a JavaScript
     * expression that resolves to the desired value at upload time.
     *
     * @example
     *   $upload->addUploadItems([
     *       'file_type'  => "$('#typePulldown').val()",
     *       'article_id' => '42',
     *   ]);
     *
     * @param array $items Associative array of fieldName => jsExpression pairs.
     */
    public function addUploadItems(array $items): void {
        $appends = '';
        foreach ($items as $key => $value) {
            $safeKey  = addslashes((string) $key);
            $appends .= "data.append('{$safeKey}', {$value});";
        }

        $this->initFunction = <<<JS
            init: function (e) {
                var myDropzone = this;
                myDropzone.on("sending", function(file, xhr, data) {
                    {$appends}
                });
            },
            JS;
    }


    /****************************************************************/
    /* OTHER METHODS                                                */
    /****************************************************************/

    /**
     * Render the file upload widget as HTML.
     *
     * Produces a Bootstrap container with a Dropzone `<form>` and appends
     * the Dropzone configuration script via {@see getJS()}. If a page has
     * been set via {@see setPage()}, two Modal_Alert dialogs (success and
     * error) are also registered on the page.
     *
     * @return string HTML + inline JavaScript markup.
     */
    public function getComponent(): string {
        if ($this->page !== null) {
            $successModal = new Modal_Alert($this->page, $this->getID() . "_fileUploaded", "File(s) uploaded successfully");
            $successModal->setConfirmDialog();

            $errorModal = new Modal_Alert($this->page, $this->getID() . "_fileError", "!!Error :: File(s) upload failed");
            $errorModal->setConfirmDialog();
        }

        $id     = htmlspecialchars($this->getID() ?: $this->getName(), ENT_QUOTES, 'UTF-8');
        $target = htmlspecialchars($this->target, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <div class="container">
                <div class="content">
                    <form action="{$target}" class="dropzone" id="{$id}"></form>
                </div>
            </div>
            HTML
            . $this->getJS();
    }

    /**
     * Build the Dropzone configuration `<script>` block.
     *
     * Configures Dropzone with a 5 GB file size limit, the set max-file count,
     * thumbnail generation, and optional `init` function for extra POST data.
     * The success handler:
     * - Shows a success or error modal (if a page is set).
     * - Logs and displays the server JSON response message.
     * - Removes the file from the queue after a 1-second delay.
     * - Runs any registered action functions once the queue is empty.
     *
     * @return string `<script>` block string.
     */
    private function getJS(): string {
        $id           = $this->getID() ?: $this->getName();
        $successName  = "{$id}_fileUploaded";
        $errorName    = "{$id}_fileError";
        $modalSuccess = $this->page !== null ? "{$successName}_func();" : '';
        $modalError   = $this->page !== null ? "{$errorName}_func();"   : '';
        $funcString   = implode("\n\t\t\t\t\t\t", $this->actionFunctions);

        return <<<JS
            <script type="text/javascript">
                Dropzone.options.{$id} = {
                    maxFilesize: 5000,          // 5 GB
                    maxFiles: {$this->maxFiles},
                    createImageThumbnails: true,

                    {$this->initFunction}

                    success: async function(file, jsonObj) {
                        if (jsonObj.status === 0) {
                            $('#{$successName}_body').html(jsonObj.msg);
                            console.log(jsonObj.msg);
                            {$modalSuccess}

                            await new Promise(r => setTimeout(r, 1000));
                            this.removeFile(file);
                            if (this.files.length === 0) this.removeAllFiles(true);
                            {$funcString}
                        } else if (jsonObj !== null && typeof jsonObj === 'object') {
                            $('#{$errorName}_body').html(jsonObj.msg);
                            console.log(jsonObj.msg);
                            {$modalError}
                        } else {
                            console.log('Non JSON Object Returned :: ' + jsonObj);
                        }
                    }
                };
            </script>
            JS;
    }
}
?>