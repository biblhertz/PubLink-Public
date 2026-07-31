<?php

namespace Biblhertz\Article\om\presentation;

use Biblhertz\Article\om\Article;
use Biblhertz\Article\om\Keyword;
use Biblhertz\Article\om\GalleyFile;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\om\presentation\FilePresentation;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Article\om\presentation\ObjectPresentation;
use Biblhertz\Article\om\presentation\GalleyFilePresentation;
use Biblhertz\Article\om\presentation\ReferenceCollectionPresentation;
use Biblhertz\Publink\components\BootstrapTabbedPanel;
use Biblhertz\Publink\om\presentation\SerializedObjectPresentation;
use Biblhertz\Publink\utilities\PDODatabase;
use Biblhertz\Publink\Config;


/********************************************************************/
/*  ArticlePresentation                                             */
/*                                                                  */
/*  Author  :   Chris Tomlinson                                     */
/*  Date    :   10th July 2023                                      */
/*                                                                  */
/*  Presentation class for journal Article objects.                 */
/*  Responsible for rendering article data as HTML components       */
/*  including tabbed editor panels, author/abstract editors,        */
/*  galley file management, keyword management, and reference       */
/*  lists. Extends ObjectPresentation.                              */
/********************************************************************/

/**
 * Renders an Article object as HTML UI components for the PubLink
 * editorial interface.
 *
 * Produces a multi-tab editor covering article metadata, abstract
 * paragraphs, authors, references, keywords, and galley files
 * (including JATS XML, cover images, and dependent files).
 *
 * @package Biblhertz\Article\om\presentation
 */
class ArticlePresentation extends ObjectPresentation {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var Article The article object rendered by this class. */
    private Article $article;

    /** @var PDODatabase Active database connection handle. */
    private PDODatabase $objDB;


    /****************************************************************/
    /*  CONSTRUCTOR                                                 */
    /****************************************************************/

    /**
     * Initialises the presentation with the article to be rendered
     * and a database handle used for reference checks and file queries.
     *
     * @param Article     $article The article object to present.
     * @param PDODatabase $objDB   Database connection handle.
     */
    public function __construct(Article $article, PDODatabase $objDB) {
        $this->article = $article;
        $this->referenceCollection = $article->getReferences();
        $this->objDB = $objDB;
    }


    /****************************************************************/
    /*  PRESENTATION METHODS                                        */
    /****************************************************************/

    /**
     * Builds and returns the full article editor as a Bootstrap tabbed panel.
     *
     * Tabs produced (in order):
     *  - Information   – read-only summary table of title, authors, keywords, reference count.
     *  - Metadata      – serialized object form for core article fields.
     *  - Abstract      – editable paragraphs from the article's abstract.
     *  - Authors       – inline forms for each author record.
     *  - References    – full reference list with optional reference-checker button.
     *  - Keywords      – nested tab panel for deleting existing / adding new keywords.
     *  - Galley Files  – nested tab panel for JATS, cover image, and supplementary files.
     *
     * @param string      $target Page URL to which forms in the panel post back.
     * @param int         $id     Serializable object ID posted back with each form.
     * @param User        $user   The user who owns this article (used for file listings).
     * @param int         $tab    Zero-based index of the tab to open by default.
     * @param PDODatabase $objDB  Database handle for reference-task and file queries.
     *
     * @return string HTML markup for the complete article editor panel.
     */
    public function getArticlePanel(string $target, int $id, User $user, int $tab, PDODatabase $objDB, int $galleySubTab = 0): string {

        $readOnly = $this->article->isReadOnly();
        $content  = array();
        $headings = array();
        $disabled = array();

        $panel = new BootstrapTabbedPanel();
        $panel->setTitle("Article Editor");
        $panel->setName("article_panel_" . uniqid());

        $c = 0;

        // Tab: Information — summary table (always read-only)
        $content[$c]  = $this->getInfoPanel();
        $headings[$c] = "Information";
        $c++;

        // Tab: Metadata — serialized form for core article fields
        $content[$c]  = SerializedObjectPresentation::getAsForm($this->article, $target, $id, false, $readOnly);
        $headings[$c] = "Metadata";
        $c++;

        // Tab: Abstract — editable paragraph list
        $content[$c]  = $this->getAbstractPanel($readOnly, $target, $id, $c);
        $headings[$c] = "Abstract";
        $c++;

        // Tab: Authors — inline edit form per author
        $content[$c]  = $this->getAuthorsPanel($readOnly, $target, $id, $c);
        $headings[$c] = "Authors";
        $c++;

        // Tab: References — full list with optional automated reference-checker button
        $presentation = new ReferenceCollectionPresentation($this->referenceCollection);
        $button = "";
        if (!$this->article->getReferenceCheck()) {
            $task = $objDB->preparedSelect("select * from task where name = ?", array('New Reference Checker'));
            if ($objDB->numRows() == 1) {
                $task   = $task->fetch();
                $button = $presentation->getReferenceCheckButton($task['action_handler'], $id, $task['id']) . "<hr/>";
            }
        }
        $content[$c]  = $button . $presentation->getAllReferencePanel($id);
        $headings[$c] = "References (" . count($this->referenceCollection) . ")";
        $c++;

        // Tab: Keywords — inline delete list + add form
        $headings[$c] = "Keywords (" . count($this->article->getKeywords()) . ")";
        $safeTarget   = htmlspecialchars($target . '?tab=' . $c, ENT_QUOTES, 'UTF-8');
        $kwStr        = '<div class="p-2">';

        // Existing keywords — each as a badge with a delete button
        foreach ($this->article->getKeywords() as $kw) {
            $kwId   = htmlspecialchars((string)$kw->getJatsID(), ENT_QUOTES, 'UTF-8');
            $kwName = htmlspecialchars($kw->getName(), ENT_QUOTES, 'UTF-8');
            $kwStr .= '<form method="POST" action="' . $safeTarget . '" class="d-inline-flex align-items-center me-2 mb-1">'
                    . '<input type="hidden" name="oid"       value="' . $id . '">'
                    . '<input type="hidden" name="itemid"    value="' . $kwId . '">'
                    . '<input type="hidden" name="classType" value="Keyword">'
                    . '<span class="badge bg-secondary fs-4 me-1">' . $kwName . '</span>'
                    . ($readOnly ? '' : '<button type="submit" name="removeObject" class="btn btn-sm btn-link text-danger p-0" title="Remove">&times;</button>')
                    . '</form>';
        }

        // Add keyword form
        if (!$readOnly) {
            $kwStr .= '<hr class="my-2">'
                    . '<form method="POST" action="' . $safeTarget . '" class="d-flex align-items-center gap-2">'
                    . '<input type="hidden" name="oid"       value="' . $id . '">'
                    . '<input type="hidden" name="classType" value="Keyword">'
                    . '<input type="text"   name="name" class="form-control form-control-sm" style="max-width:20em" placeholder="New keyword">'
                    . '<button type="submit" name="addObject" class="btn btn-sm btn-primary">Add</button>'
                    . '</form>';
        }

        $kwStr       .= '</div>';
        $content[$c]  = $kwStr;
        $c++;

        // Tab: Galley Files — JATS, cover image, supplementary and dependent files
        $content[$c]  = $this->getGalleyPanel($this->article->getNonJatsGalleyFiles(), $target . "?tab=$c", $id, $user, $readOnly, $galleySubTab);
        $headings[$c] = "Galley Files (" . count($this->article->getGalleyFiles()) . ")";
        $c++;

        // Tab: Generate IIIF Manifest — only shown when the task is registered in the database
        $taskRow = $objDB->preparedSelect("SELECT id FROM task WHERE name = ?", ["XML to IIIF Manifest"])->fetch();
        if ($taskRow) {
            $jatsGalley = $this->article->getJATSXMLFile();
            $jatsFileId = $jatsGalley ? $jatsGalley->getFileID() : 0;
            $manifestTaskId = (int) $taskRow['id'];

            // Check for an active job row for this specific article's JATS file.
            // The file_id is stored as an integer in the parameters JSON column,
            // so a LIKE search on the serialized value is safe and unambiguous.
            $runningJob = $objDB->preparedSelect(
                "SELECT id FROM job WHERE task_id = ? AND user_details_id = ? AND parameters LIKE ? LIMIT 1",
                [$manifestTaskId, $user->getID(), '%"file_id":' . $jatsFileId . '%']
            )->fetch();

            $formHtml = $this->getManifestPanel($id, $manifestTaskId, $jatsFileId, $user->getID(), $c);

            if ($runningJob) {
                $disabled[$c] = true;
                // JS polls jobRunningService every 4 s; when the job row is gone it
                // reloads the page to this tab so PHP re-renders the form directly.
                $content[$c] = '<div class="manifest-spinner alert alert-info m-2 small">'
                    .   '<i class="fas fa-spinner fa-spin mr-2"></i>'
                    .   'A manifest generation job is currently running. '
                    .   'This tab will re-enable automatically when the job completes.'
                    . '</div>'
                    . '<script>(function(){'
                    .   'var taskId = ' . $manifestTaskId . ';'
                    .   'var fileId = ' . $jatsFileId . ';'
                    .   'var errors = 0;'
                    .   'var interval = setInterval(function(){'
                    .     'fetch("services/jobRunningService.php?task_id="+taskId+"&file_id="+fileId+"&_t="+Date.now(),{cache:"no-store"})'
                    .       '.then(function(r){return r.json();})'
                    .       '.then(function(d){'
                    .         'console.log("[manifest poll] running="+d.running);'
                    .         'errors=0;'
                    .         'if(!d.running){clearInterval(interval);location.reload();}'
                    .       '})'
                    .       '.catch(function(e){'
                    .         'console.warn("[manifest poll] error ("+( ++errors)+"):",e);'
                    .         'if(errors>=3){clearInterval(interval);location.reload();}'
                    .       '});'
                    .   '},4000);'
                    . '})();</script>';
            } else {
                $content[$c] = $formHtml;
            }

            $headings[$c] = "Generate IIIF Manifest";
            $c++;
        }

        $panel->setDisabled($disabled);
        $panel->setTabNames($headings);
        $panel->setTabContent($content);
        $panel->setOpenTab($tab);

        return "<div class=\"w-100\">" . $panel->getComponent() . "</div>";
    }


    /**
     * Builds an HTML summary table for the article.
     *
     * Displays article title, comma-separated author names, comma-separated
     * keywords, and the total reference count. Used as the content for the
     * read-only "Information" tab.
     *
     * @return string HTML <table> markup.
     */
    private function getInfoPanel(): string {
        $str  = "<table class=\"table table-bordered table-sm small\">";
        $str .= "<tr><td style=\"width:1%;font-weight:bold\" class=\"text-nowrap align-middle\">Article Name</td><td>"       . htmlspecialchars((string)$this->article->getTitle(), ENT_QUOTES, 'UTF-8') . "</td></tr>";
        $str .= "<tr><td style=\"width:1%;font-weight:bold\" class=\"text-nowrap align-middle\">Authors</td><td>"             . htmlspecialchars($this->getAuthorString(), ENT_QUOTES, 'UTF-8')           . "</td></tr>";
        $str .= "<tr><td style=\"width:1%;font-weight:bold\" class=\"text-nowrap align-middle\">Keywords</td><td>"            . htmlspecialchars($this->getKeywordString(), ENT_QUOTES, 'UTF-8')          . "</td></tr>";
        $str .= "<tr><td style=\"width:1%;font-weight:bold\" class=\"text-nowrap align-middle\">Total References</td><td>" . count($this->article->getReferences())                                    . "</td></tr>";
        return $str . "</table>";
    }


    /**
     * Returns a comma-separated string of all author names (first + last).
     *
     * @return string e.g. "Jane Smith, John Doe"
     */
    private function getAuthorString(): string {
        $str = "";
        foreach ($this->article->getAuthors() as $a) {
            $str .= $a->getFirstName() . " " . $a->getLastName() . ", ";
        }
        return rtrim($str, ', ');
    }


    /**
     * Returns a comma-separated string of all keyword names.
     *
     * @return string e.g. "baroque, architecture, Rome" or "" if no keywords.
     */
    private function getKeywordString(): string {
        if (!count($this->article->getKeywords())) return "";
        $str = "";
        foreach ($this->article->getKeywords() as $a) {
            $str .= $a->getName() . ", ";
        }
        return rtrim($str, ', ');
    }


    /**
     * Renders a flat list of galley files as sequential edit/delete forms.
     *
     * Accepts either an array of GalleyFile objects or a single GalleyFile.
     * Each file is rendered via GalleyFilePresentation::getEditOrDeleteForm(),
     * separated by a horizontal rule.
     *
     * @param mixed       $galleys  Array of GalleyFile objects, a single GalleyFile,
     *                              or any falsy value when no files are available.
     * @param string      $target   Form post-back URL.
     * @param int         $oid      Serialized object ID to include in each form.
     * @param bool        $readOnly When true, forms are rendered without edit controls.
     *
     * @return string HTML markup, or "No files available" if $galleys is empty/invalid.
     */
    public function getGalleyListTable(mixed $galleys, string $target, int $oid, bool $readOnly): string {
        $str = "";
        if (!$galleys) return "No files available";

        if (is_array($galleys)) {
            foreach ($galleys as $galley) {
                $gfp  = new GalleyFilePresentation($galley);
                $str .= $gfp->getEditOrDeleteForm($target, $oid, $readOnly) . "<hr/>";
            }
        } elseif (is_a($galleys, "Biblhertz\Article\om\GalleyFile")) {
            $gfp  = new GalleyFilePresentation($galleys);
            $str .= $gfp->getEditOrDeleteForm($target, $oid, $readOnly) . "<hr/>";
        } else {
            return "No files available";
        }

        return $str;
    }


    /**
     * Builds a Bootstrap tabbed panel for all galley file types attached to the article.
     *
     * Tabs produced (in order):
     *  - Attached JATS File      – current JATS XML file with options to regenerate or swap.
     *  - Cover Image File        – shown only when a cover image is attached.
     *  - [Per supplementary file] – one tab per non-JATS galley; dependent files are
     *                               labelled with a "(D)" suffix.
     *  - Add Galley File         – form to attach a new galley from the user's file store.
     *  - Add Dependant Files     – form to attach dependent files under a parent galley.
     *
     * @param mixed       $galleys  Array of non-JATS GalleyFile objects or a single GalleyFile.
     * @param string      $target   Form post-back URL.
     * @param int         $oid      Serialized object ID to include in each form.
     * @param User        $user     Current user; used to query available JATS and image files.
     * @param bool        $readOnly When true, edit controls are suppressed in file forms.
     *
     * @return string HTML markup for the galley tabbed panel.
     */
    public function getGalleyPanel(mixed $galleys, string $target, int $oid, User $user, bool $readOnly, int $subTab = 0): string {
        $panel    = new BootstrapTabbedPanel();
        $panel->setTitle("Galley Files");
        $panel->setOpenTab($subTab);
        $tabs = $headings = array();

        $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');

        // --- Tab: Attached JATS File ---
        $jatsFile    = $this->article->getJATSXMLFile();
        $jatsExclude = [$jatsFile->getFileID()];
        $buttonid    = "changeJATSButton";
        $funcid      = "changeJATSFunc()";

        $gfp      = new GalleyFilePresentation($jatsFile);
        $headings[] = "JATS File";
        $tabs[]     = '<div class="small">'
                    . '<div class="mb-2">'
                    .   '<a href="' . $safeTarget . '&oid=' . $oid . '&replaceJATS=true" class="btn btn-sm btn-outline-secondary">'
                    .     '<i class="fas fa-sync-alt me-1"></i> Regenerate JATS from data</a>'
                    . '</div>'
                    . '<div class="card mb-3"><div class="card-body p-2">' . $gfp->getAsTable() . '</div></div>'
                    . '<p class="text-muted mb-1">Swap to a different uploaded JATS file:</p>'
                    . '<form action="' . $safeTarget . '&changeJATS=true" method="POST">'
                    .   '<input type="hidden" name="oid" value="' . $oid . '">'
                    .   FilePresentation::getFileListAsRBTable($user->getMyJATSFilesAsResultSet($jatsExclude), "galley_file", $funcid)
                    .   '<button class="btn btn-sm btn-outline-primary mt-1" name="' . $buttonid . '" id="' . $buttonid . '" type="submit">'
                    .     'Use selected file</button>'
                    . '</form>'
                    . '</div>'
                    . '<script>$(function(){$("#' . $buttonid . '").hide();});'
                    . 'function ' . $funcid . '{$("#' . $buttonid . '").show();}</script>';

        // --- Tab: Galley Files (all non-JATS: cover image + supplementary + dependent) + Add form ---
        $headings[] = 'Galley Files';
        $addFormId  = 'addGalleyCollapse_' . uniqid();
        $gallayListHtml = '';

        // Add galley accordion panel at the top
        if (!$readOnly) {
            $gallayListHtml .= '<div class="card mb-3 border-dark">'
                             . '<div class="card-header py-1 px-2 small bg-dark text-white d-flex justify-content-between align-items-center">'
                             .   '<span>Add Galley File</span>'
                             .   '<a href="#' . $addFormId . '" data-toggle="collapse" class="text-white"><i class="fas fa-chevron-down"></i></a>'
                             . '</div>'
                             . '<div id="' . $addFormId . '" class="collapse">'
                             .   '<div class="card-body p-2">'
                             .     GalleyFilePresentation::getGalleyFileAttachForm($this->article, $user->getID(), $target, $oid)
                             .   '</div>'
                             . '</div>'
                             . '</div>';
        }

        $coverImage = $this->article->getCoverImageFile();
        if ($coverImage) {
            $gfp = new GalleyFilePresentation($coverImage);
            $gallayListHtml .= '<div class="card mb-2 border-success">'
                             . '<div class="card-header py-1 px-2 small text-white bg-success">Cover Image</div>'
                             . '<div class="card-body p-2">' . $gfp->getEditOrDeleteForm($target, $oid, $readOnly) . '</div>'
                             . '</div>';
        }

        $galleyArray = is_array($galleys) ? $galleys
                     : (is_a($galleys, "Biblhertz\Article\om\GalleyFile") ? [$galleys] : []);

        foreach ($galleyArray as $galley) {
            $galley->setArticle($this->article);
            $gfp     = new GalleyFilePresentation($galley);
            $isDep   = $galley->getGenre() === GalleyFile::$DEPENDANT_GENRE;
            $name    = htmlspecialchars($galley->getName() ?: $galley->getGalleyFileName(), ENT_QUOTES, 'UTF-8');
            $genre   = htmlspecialchars(ucfirst($galley->getGenre()), ENT_QUOTES, 'UTF-8');
            if ($isDep) {
                $headerClass = 'bg-secondary text-white';
                $heading     = $name . ' <span class="badge bg-light text-dark ms-1">Dependant</span>';
            } else {
                $headerClass = 'bg-primary text-white';
                $heading     = $name . ' <span class="badge bg-light text-dark ms-1">' . $genre . '</span>';
            }
            $gallayListHtml .= '<div class="card mb-2">'
                             . '<div class="card-header py-1 px-2 small ' . $headerClass . '">' . $heading . '</div>'
                             . '<div class="card-body p-2">' . $gfp->getEditOrDeleteForm($target, $oid, $readOnly) . '</div>'
                             . '</div>';
        }

        if (!$gallayListHtml) {
            $gallayListHtml = '<p class="text-muted small">No galley files attached.</p>';
        }

        $tabs[] = $gallayListHtml;

        // --- Tab: Add Dependant Files ---
        $headings[]  = 'Add Dependant';
        $depButtonId = "add_dependent_button";
        $tabs[]      = '<form action="' . $safeTarget . '&addDependantGalleys=true" method="POST">'
                     . '<input type="hidden" name="oid" value="' . $oid . '">'
                     . '<div class="card mb-2">'
                     .   '<div class="card-header py-1 px-2 small bg-primary text-white fw-bold">Parent file</div>'
                     .   '<div class="card-body p-2">'
                     .     FilePresentation::getFileListAsRBTableFromArray($this->article->getPotentialParentGalleyFiles(), "parent_file")
                     .   '</div>'
                     . '</div>'
                     . '<div class="card mb-2">'
                     .   '<div class="card-header py-1 px-2 small bg-primary text-white fw-bold d-flex align-items-center gap-2">'
                     .     '<span>Select dependent image files</span>'
                     .     '<div class="form-check form-check-inline mb-0 ms-3">'
                     .       '<input class="form-check-input" type="checkbox" id="dep_select_all">'
                     .       '<label class="form-check-label" for="dep_select_all">Select all</label>'
                     .     '</div>'
                     .   '</div>'
                     .   '<div class="card-body p-2">'
                     .     FilePresentation::getImageFileListAsCheckBoxTable($this->objDB, $user->getMyImageFilesAsResultSet(), "dependant_cb")
                     .   '</div>'
                     . '</div>'
                     . '<button class="btn btn-sm btn-outline-primary" name="' . $depButtonId . '" type="submit">Add dependent files</button>'
                     . '<script>'
                     . '$("#dep_select_all").on("change",function(){'
                     .   'var checked=$(this).is(":checked");'
                     .   '$("input[name^=\'dependant_cb\']").prop("checked",checked);'
                     . '});'
                     . '</script>'
                     . '</form>';

        $panel->setTabNames($headings);
        $panel->setTabContent($tabs);

        return $panel->getComponent();
    }


    /**
     * Builds an HTML table of inline author edit forms, one row per author.
     *
     * Each form posts back to $target with updateAuthor=true, the author's
     * JATS ID, and the current tab index so the UI returns to the correct tab.
     *
     * @param bool   $readOnly When true, forms are rendered without edit controls.
     * @param string $target   Form post-back URL.
     * @param int    $oid      Serialized object ID to include in each form.
     * @param int    $tab      Current tab index, appended to the post-back URL.
     *
     * @return string HTML <table> containing one author form per row.
     */
    private function getAuthorsPanel(bool $readOnly, string $target, int $oid, int $tab): string {
        $authors = $this->article->getAuthors();
        $str     = '';
        $i       = 1;

        foreach ($authors as $author) {
            $action   = htmlspecialchars(
                $target . '?updateAuthor=true&aid=' . urlencode($author->getJatsID()) . '&tab=' . $tab,
                ENT_QUOTES, 'UTF-8'
            );
            $dis = $readOnly ? ' disabled' : '';

            $f = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $input = fn(string $name, string $val, string $placeholder = '') =>
                '<input type="text" class="form-control form-control-sm" name="' . $name . '"'
                . ' value="' . $f($val) . '"'
                . ($placeholder ? ' placeholder="' . $placeholder . '"' : '')
                . $dis . '>';

            $checkbox = fn(string $name, bool $checked, string $label) =>
                '<div class="form-check form-check-inline">'
                . '<input type="hidden" name="' . $name . '" value="false">'
                . '<input class="form-check-input" type="checkbox" name="' . $name . '" value="true"'
                . ($checked ? ' checked' : '') . $dis . '>'
                . '<label class="form-check-label small">' . $label . '</label>'
                . '</div>';

            $label = fn(string $text) =>
                '<label class="form-label small text-muted mb-0">' . $text . '</label>';

            $heading = trim($f($author->getFirstName()) . ' ' . $f($author->getLastName()))
                     ?: ('Author ' . $i);

            $str .= '<div class="card mb-2">'
                  .   '<div class="card-header py-1 px-2 small bg-primary text-white fw-bold">Author ' . $i . ' &mdash; ' . $heading . '</div>'
                  .   '<div class="card-body p-2">'
                  .     '<form method="POST" action="' . $action . '">'
                  .       '<input type="hidden" name="oid" value="' . $oid . '">'

                  .       '<div class="row g-2 mb-2">'
                  .         '<div class="col">' . $label('First name') . $input('firstName', $author->getFirstName()) . '</div>'
                  .         '<div class="col">' . $label('Last name')  . $input('lastName',  $author->getLastName())  . '</div>'
                  .         '<div class="col-2">' . $label('Von') . $input('von', $author->getVon(), 'von') . '</div>'
                  .         '<div class="col-2">' . $label('Jr')  . $input('jr',  $author->getJr(),  'jr')  . '</div>'
                  .       '</div>'

                  .       '<div class="row g-2 mb-2">'
                  .         '<div class="col">' . $label('Full name override') . $input('fullName', (string)$author->getFullName()) . '</div>'
                  .         '<div class="col">' . $label('Email')  . $input('email',  $author->getEmail())  . '</div>'
                  .         '<div class="col">' . $label('ORCID')  . $input('orcID',  $author->getOrcID())  . '</div>'
                  .       '</div>'

                  .       '<div class="row g-2 mb-2">'
                  .         '<div class="col-4">' . $label('Unique ID') . $input('uniqueID', $author->getUniqueID()) . '</div>'
                  .       '</div>'

                  .       '<div class="mb-2">'
                  .         $label('Biography')
                  .         '<textarea name="biography" class="form-control form-control-sm" rows="3"' . $dis . '>'
                  .         $f($author->getBiography())
                  .         '</textarea>'
                  .       '</div>'

                  .       '<div class="mb-2">'
                  .         $checkbox('deceased',            $author->getDeceased(),            'Deceased')
                  .         $checkbox('equalContrib',        $author->getEqualContrib(),        'Equal contribution')
                  .         $checkbox('correspondingAuthor', $author->getCorrespondingAuthor(), 'Corresponding author')
                  .       '</div>'

                  .       '<button type="submit" class="btn btn-sm btn-primary"' . $dis . '>Save</button>'
                  .     '</form>'
                  .   '</div>'
                  . '</div>';
            $i++;
        }

        return $str ?: '<p class="text-muted">No authors.</p>';
    }


    /**
     * Builds an HTML table of inline abstract-paragraph edit forms, one row per paragraph.
     *
     * Each form posts back to $target with updateParagraph=true, the paragraph's
     * JATS ID, and the current tab index. An anchor element keyed to the paragraph's
     * JATS ID is prepended to each row for deep-linking.
     *
     * @param bool   $readOnly When true, forms are rendered without edit controls.
     * @param string $target   Form post-back URL.
     * @param int    $oid      Serialized object ID to include in each form.
     * @param int    $tab      Current tab index, appended to the post-back URL.
     *
     * @return string HTML <table> containing one abstract paragraph form per row.
     */
    private function getAbstractPanel(bool $readOnly, string $target, int $oid, int $tab): string {
        $paras = $this->article->getAbstract()->getParagraphs();
        $str   = '';
        $i     = 1;

        foreach ($paras as $para) {
            $pid        = htmlspecialchars($para->getJatsID(), ENT_QUOTES, 'UTF-8');
            $text       = htmlspecialchars($para->getText(), ENT_QUOTES, 'UTF-8');
            $action     = htmlspecialchars(
                $target . '?updateParagraph=true&pid=' . urlencode($para->getJatsID()) . '&tab=' . $tab,
                ENT_QUOTES, 'UTF-8'
            );
            $disabled   = $readOnly ? ' disabled' : '';
            $btnDisabled = $readOnly ? ' disabled' : '';

            $str .= '<div class="card mb-2" id="' . $pid . '">'
                  .   '<div class="card-header py-1 px-2 small bg-primary text-white fw-bold">Paragraph ' . $i . '</div>'
                  .   '<div class="card-body p-2">'
                  .     '<form method="POST" action="' . $action . '">'
                  .       '<input type="hidden" name="oid" value="' . $oid . '" />'
                  .       '<textarea name="text" class="form-control form-control-sm mb-2" rows="4"'
                  .           $disabled . '>' . $text . '</textarea>'
                  .       '<button type="submit" class="btn btn-sm btn-primary"'
                  .           $btnDisabled . '>Save</button>'
                  .     '</form>'
                  .   '</div>'
                  . '</div>';
            $i++;
        }

        return $str ?: '<p class="text-muted">No abstract paragraphs.</p>';
    }


    /**
     * Builds the IIIF Manifest generation and publication tab.
     *
     * Renders the manifest generation form (posts to handlers/xmlToManifest.php).
     * When a previously completed manifest job exists for this JATS file, a
     * "Publish to Server" section is also shown above the generation form,
     * allowing the user to upload the generated manifest to the annotation server.
     * Both the publish and remove-from-server forms require the user to type in
     * the annotation server API key on each submission — Publink holds no
     * shared key of its own to pre-fill it with.
     *
     * If no JATS file is attached to the article, an alert is shown instead.
     *
     * @param int $oid         Serialized object ID of the article.
     * @param int $taskId      ID of the "XML to IIIF Manifest" task record.
     * @param int $jatsFileId  File table ID of the attached JATS XML file, or 0 if none.
     * @param int $userId      ID of the current user (for scoping the job_log query).
     * @return string HTML markup for the manifest tab content.
     */
    private function getManifestPanel(int $oid, int $taskId, int $jatsFileId, int $userId, int $tabIndex = 7): string {
        if (!$jatsFileId) {
            return '<div class="alert alert-warning small m-2">No JATS XML file is attached to this article. '
                 . 'A JATS XML file is required to generate a manifest.</div>';
        }

        $f     = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $title = $f($this->article->getTitle());

        $titleEn = $f($this->article->getTransTitle());

        $field = function(string $name, string $label, string $value = '', string $type = 'text', string $placeholder = '', bool $required = true) use ($f): string {
            $req = $required ? ' required' : '';
            return '<div class="mb-3">'
                 . '<label class="form-label fw-bold small">' . $f($label) . '</label>'
                 . '<input type="' . $type . '" name="' . $f($name) . '" class="form-control form-control-sm"'
                 . ($value       ? ' value="'       . $value       . '"' : '')
                 . ($placeholder ? ' placeholder="' . $f($placeholder) . '"' : '')
                 . $req . '>'
                 . '</div>';
        };

        $str = '<div class="p-2">';

        $str .= '<div class="alert alert-light border small mb-3" style="line-height:1.6">'
              . '<strong>Generate IIIF Manifest</strong> — creates a IIIF Presentation API&nbsp;3 manifest from this '
              . 'article\'s attached JATS XML file. The job reads every image referenced in the JATS, fetches its '
              . 'dimensions from the corresponding IIIF Image API (<code>info.json</code>), and writes a '
              . 'standards-compliant manifest JSON file to your file store.'
              . '<ul class="mb-0 mt-2">'
              . '<li>Fill in the <strong>Manifest ID</strong> (the public URL the manifest will be served from) and the '
              . '<strong>Base Canvas URL</strong> (the root URL for canvas identifiers inside the manifest).</li>'
              . '<li>Labels are pre-filled from the article\'s Italian and English titles; edit them here to override.</li>'
              . '<li>The job runs in the background — this tab will show a spinner while it is running and reload '
              . 'automatically when it finishes.</li>'
              . '<li>Once generated, the manifest can be downloaded as JSON. If the Simple Manifest Server integration '
              . 'is enabled, a <strong>Publish</strong> section will also appear, allowing the manifest to be uploaded '
              . 'to the annotation server so it can be viewed publicly.</li>'
              . '</ul>'
              . '</div>';

        // ---------------------------------------------------------------
        // Publication section: shown when a completed manifest job exists.
        // Query job_log for the most recent xml2manifest job (success or failure)
        // for this JATS file and user, so we can surface errors when generation fails.
        // ---------------------------------------------------------------
        $logRow = $this->objDB->preparedSelect(
            "SELECT output_file_id, parameters, error_message FROM job_log
              WHERE task_id = ? AND user_details_id = ? AND parameters LIKE ?
              ORDER BY id DESC LIMIT 1",
            [$taskId, $userId, '%"file_id":' . $jatsFileId . '%']
        )->fetch();

        // Decode persisted job parameters to pre-fill the generation form.
        $prevParams = [];
        if ($logRow && !empty($logRow['parameters'])) {
            $prevParams = json_decode($logRow['parameters'], true) ?? [];
        }

        // Show a warning if the most recent job failed (no output file recorded).
        if ($logRow && empty($logRow['output_file_id']) && !empty($logRow['error_message'])) {
            $errMsg = preg_replace('/^ERROR ::\s*/i', '', $logRow['error_message']);
            $str .= '<div class="alert alert-danger small mb-3">'
                  . '<strong><i class="fas fa-exclamation-triangle me-1"></i>Manifest generation failed.</strong> '
                  . htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8')
                  . '</div>';
        }

        if ($logRow && !empty($logRow['output_file_id'])) {
            $manifestFileId = (int) $logRow['output_file_id'];

            // Try to read and parse the manifest JSON to pre-fill routing fields.
            $series       = '';
            $volume       = '';
            $manifestName = '';
            $manifestIdUrl = '';

            $fileRow = $this->objDB->preparedSelect(
                "SELECT path, name, published_url FROM file WHERE id = ?",
                [$manifestFileId]
            )->fetch();

            // If the file record no longer exists (deleted), suppress the whole card.
            if (!$fileRow) {
                $manifestFileId = null;
            }
        }

        if (isset($manifestFileId)) {
            $alreadyPublished = !empty($fileRow['published_url']) ? $fileRow['published_url'] : '';

            if ($fileRow && file_exists($fileRow['path'])) {
                $jsonContent = @file_get_contents($fileRow['path']);
                if ($jsonContent) {
                    $parsed = @json_decode($jsonContent, true);
                    $manifestIdUrl = $parsed['id'] ?? '';
                }

                // Parse the manifest URL to derive series, volume, manifest_name.
                // Expects path segments after any root prefix, e.g.:
                //   /iiif_manifests/journals/2024/article.json
                //   → series=journals, volume=2024, manifest_name=article.json
                if ($manifestIdUrl) {
                    $path  = parse_url($manifestIdUrl, PHP_URL_PATH) ?? '';
                    $parts = array_values(array_filter(explode('/', $path)));
                    if (count($parts) >= 3) {
                        $manifestName = array_pop($parts);
                        $volume       = array_pop($parts);
                        // Drop the first segment (e.g. 'iiif_manifests') if there are ≥3 remaining.
                        if (count($parts) > 1) array_shift($parts);
                        $series = implode('/', $parts);
                    } elseif (count($parts) === 2) {
                        $manifestName = array_pop($parts);
                        $volume       = array_pop($parts);
                    } elseif (count($parts) === 1) {
                        $manifestName = $parts[0];
                    }
                }

                if (!$manifestName) {
                    $manifestName = $fileRow['name'];
                }
            }

            $str .= '<div class="card mb-3">'
                  . '<div class="card-header bg-success text-white small fw-bold">'
                  .   '<i class="fas fa-check-circle me-1"></i>Generated Manifest'
                  . '</div>'
                  . '<div class="card-body">';

            $downloadUrl = 'user.html?uid=' . $userId . '&fileDownload=' . $manifestFileId;
            $str .= '<p class="small mb-2">'
                  . '<a href="' . $downloadUrl . '" class="btn btn-outline-secondary btn-sm">'
                  .   '<i class="fas fa-download me-1"></i> Download manifest JSON'
                  . '</a>'
                  . '</p>';

            if ($manifestIdUrl) {
                $str .= '<p class="small mb-2">'
                      . 'Manifest ID: <code>' . $f($manifestIdUrl) . '</code>'
                      . '</p>';
            }

            if (Config::$PUBLICATION) {
                if ($alreadyPublished) {
                    $safePublishedUrl = $f($alreadyPublished);
                    $str .= '<div class="alert alert-success small py-2 mb-2">'
                          .   '<div class="d-flex align-items-center justify-content-between gap-2 mb-2">'
                          .     '<span><i class="fas fa-check-circle me-1"></i>'
                          .     'Published: <a href="' . $safePublishedUrl . '" target="_blank">' . $safePublishedUrl . '</a></span>'
                          .   '</div>'
                          .   '<form method="POST" action="./handlers/removeManifest.php" class="mb-0"'
                          .     ' onsubmit="return confirm(\'Remove this manifest from the annotation server?\')">'
                          .   '<input type="hidden" name="file_id"      value="' . $manifestFileId . '">'
                          .   '<input type="hidden" name="oid"          value="' . $oid . '">'
                          .   '<input type="hidden" name="tab"          value="' . $tabIndex . '">'
                          .   '<input type="hidden" name="published_url" value="' . $safePublishedUrl . '">'
                          .   '<div class="input-group input-group-sm">'
                          .     '<input type="password" name="api_key" class="form-control form-control-sm"'
                          .       ' placeholder="Annotation server API key" autocomplete="off" required>'
                          .     '<button type="submit" class="btn btn-sm btn-danger">'
                          .       '<i class="fas fa-trash me-1"></i>Delete from server</button>'
                          .   '</div>'
                          .   '</form>'
                          . '</div>';
                } else {
                    $str .= '<p class="small text-muted mb-2">Publish this manifest to the annotation server:</p>'
                          . '<form method="POST" action="./handlers/publishManifest.php">'
                          . '<input type="hidden" name="file_id" value="' . $manifestFileId . '">'
                          . '<input type="hidden" name="oid"     value="' . $oid . '">'
                          . '<input type="hidden" name="tab"     value="' . $tabIndex . '">'
                          . '<div class="row g-2 mb-3">'
                          . '<div class="col-sm-4">'
                          .   '<label class="form-label small fw-bold">Series</label>'
                          .   '<input type="text" name="series" class="form-control form-control-sm"'
                          .     ($series ? ' value="' . $f($series) . '"' : '') . ' placeholder="e.g. journals" required>'
                          . '</div>'
                          . '<div class="col-sm-4">'
                          .   '<label class="form-label small fw-bold">Volume</label>'
                          .   '<input type="text" name="volume" class="form-control form-control-sm"'
                          .     ($volume ? ' value="' . $f($volume) . '"' : '') . ' placeholder="e.g. 2024" required>'
                          . '</div>'
                          . '<div class="col-sm-4">'
                          .   '<label class="form-label small fw-bold">Manifest filename</label>'
                          .   '<input type="text" name="manifest_name" class="form-control form-control-sm"'
                          .     ($manifestName ? ' value="' . $f($manifestName) . '"' : '') . ' placeholder="article.json" required>'
                          . '</div>'
                          . '</div>'
                          . '<div class="mb-3">'
                          .   '<label class="form-label small fw-bold">API Key</label>'
                          .   '<input type="password" name="api_key" class="form-control form-control-sm"'
                          .     ' placeholder="Annotation server API key" autocomplete="off" required>'
                          . '</div>'
                          . '<div class="form-check mb-3">'
                          .   '<input class="form-check-input" type="checkbox" name="overwrite" value="1" id="overwriteManifest">'
                          .   '<label class="form-check-label small" for="overwriteManifest">Overwrite if manifest already exists</label>'
                          . '</div>'
                          . '<button type="submit" class="btn btn-success btn-sm">'
                          .   '<i class="fas fa-upload me-1"></i> Publish Manifest</button>'
                          . '</form>';
                }
            }

            $str .= '</div></div>';
        }

        // ---------------------------------------------------------------
        // Generation form (always shown)
        // ---------------------------------------------------------------
        $str .= '<form method="POST" action="./handlers/xmlToManifest.php">';
        $str .= '<input type="hidden" name="task_id"      value="' . $taskId    . '">';
        $str .= '<input type="hidden" name="oid"          value="' . $oid       . '">';
        $str .= '<input type="hidden" name="jats_file_id" value="' . $jatsFileId . '">';
        $str .= '<input type="hidden" name="tab"          value="' . $tabIndex  . '">';

        $p = fn(string $key, string $default = '') => $f($prevParams[$key] ?? $default);

        $str .= $field('manifest_id',  'Manifest ID',
                    $p('manifest_id'), 'text', 'https://annotation.biblhertz.it/iiif_manifests/…/article.json');
        $str .= $field('base_canvas',  'Base Canvas URL',
                    $p('base_canvas'), 'text', 'https://annotation.biblhertz.it/iiif_manifests/…/article');
        $str .= $field('label_it',     'Label (Italian)',  $p('label_it', $title ?: $this->article->getTitle()));
        $str .= $field('label_en',     'Label (English)',  $p('label_en', $titleEn ?: $this->article->getTransTitle()));
        $str .= $field('rights',       'Rights URL',
                    $p('rights', 'https://creativecommons.org/licenses/by/4.0/'), 'text');
        $str .= $field('required_stmt_it', 'Attribution (Italian)',
                    $p('required_stmt_it', 'Bibliotheca Hertziana – Istituto Max Planck per la storia dell\'arte'));
        $str .= $field('required_stmt_en', 'Attribution (English)',
                    $p('required_stmt_en', 'Bibliotheca Hertziana – Max Planck Institute for Art History'));

        $prevFetchDelay    = $prevParams['fetch_delay']    ?? '';
        $prevFallbackW     = $prevParams['fallback_width'] ?? '';
        $prevFallbackH     = $prevParams['fallback_height'] ?? '';
        $prevForceHttp     = isset($prevParams['force_http_hosts']) && is_array($prevParams['force_http_hosts'])
                           ? implode(', ', $prevParams['force_http_hosts']) : '';

        $str .= '<details class="mb-3"><summary class="text-muted small" style="cursor:pointer">Optional settings</summary>'
              . '<div class="row g-2 mt-1">'
              . '<div class="col">'
              .   '<label class="form-label small">Fetch delay (s)</label>'
              .   '<input type="number" step="0.1" min="0" name="fetch_delay" class="form-control form-control-sm" placeholder="0.3"'
              .   ($prevFetchDelay !== '' ? ' value="' . $f((string)$prevFetchDelay) . '"' : '') . '>'
              . '</div>'
              . '<div class="col">'
              .   '<label class="form-label small">Fallback width (px)</label>'
              .   '<input type="number" min="1" name="fallback_width" class="form-control form-control-sm" placeholder="1000"'
              .   ($prevFallbackW !== '' ? ' value="' . $f((string)$prevFallbackW) . '"' : '') . '>'
              . '</div>'
              . '<div class="col">'
              .   '<label class="form-label small">Fallback height (px)</label>'
              .   '<input type="number" min="1" name="fallback_height" class="form-control form-control-sm" placeholder="1000"'
              .   ($prevFallbackH !== '' ? ' value="' . $f((string)$prevFallbackH) . '"' : '') . '>'
              . '</div>'
              . '</div>'
              . '<div class="mt-2">'
              .   '<label class="form-label small">Force HTTP hosts (comma-separated)</label>'
              .   '<input type="text" name="force_http_hosts" class="form-control form-control-sm" placeholder="e.g. fotothek.biblhertz.it"'
              .   ($prevForceHttp !== '' ? ' value="' . $f($prevForceHttp) . '"' : '') . '>'
              . '</div>'
              . '</details>';

        $str .= '<button type="submit" class="btn btn-primary btn-sm">'
              . '<i class="fas fa-cog me-1"></i> Generate Manifest</button>';
        $str .= '</form>';
        $str .= '</div>';

        return $str;
    }

}
?>