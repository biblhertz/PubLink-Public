<?php

namespace Biblhertz\Article\om\presentation;

use Biblhertz\Article\om\presentation\ReferencePresentation;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Article\om\ReferenceCollection;
use Biblhertz\Publink\Config;


/********************************************************************/
/*  ReferenceCollectionPresentation                                 */
/*                                                                  */
/*  Author  :   Chris Tomlinson                                     */
/*  Date    :   10th July 2023                                      */
/*                                                                  */
/*  Presentation class for ReferenceCollection objects.            */
/*  Renders a collection of references as HTML tables and           */
/*  interactive panels, including CSL-driven citation formatting    */
/*  via an external citation service and a batch reference-         */
/*  checker trigger form.                                           */
/********************************************************************/

/**
 * Renders a ReferenceCollection as HTML UI components.
 *
 * Extends ObjectPresentation, populating the inherited
 * $referenceCollection from the constructor argument.
 *
 * Key features:
 *  - Plain table list of all references.
 *  - Interactive tabbed panel with a CSL style pulldown that
 *    dynamically reformats citations via citeService.php.
 *  - A batch "Reference Check All References" button form.
 *
 * @package Biblhertz\Article\om\presentation
 */
class ReferenceCollectionPresentation extends ObjectPresentation {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /****************************************************************/
    /*  CONSTRUCTOR                                                 */
    /****************************************************************/

    /**
     * @param ReferenceCollection $collection The reference collection to present.
     */
    public function __construct(ReferenceCollection $collection) {
        $this->referenceCollection = $collection;
    }


    /****************************************************************/
    /*  PRESENTATION METHODS                                        */
    /****************************************************************/

    /**
     * Renders all references in the collection as a Bootstrap-styled
     * HTML table, delegating each row to ReferencePresentation::getAsTableRow().
     *
     * @return string HTML <table> markup containing one row per reference.
     */
    public function getAsTableList(): string {
        $str = "<table class=\"table table-bordered table-sm small\" style=\"table-layout:fixed; width:100%; word-wrap:break-word; overflow-wrap:break-word;\">"
             . "<thead><tr>"
             . "<th style=\"width:8%\">Year</th>"
             . "<th style=\"width:25%\">Authors</th>"
             . "<th>Title</th>"
             . "<th style=\"width:8%\" class=\"text-center\">Match</th>"
             . "</tr></thead>";
        $refs = [];
        foreach ($this->referenceCollection as $ref) {
            $refs[] = $ref;
        }
        usort($refs, fn($a, $b) => $b->getMatchPercent() <=> $a->getMatchPercent());

        foreach ($refs as $ref) {
            $presentation = new ReferencePresentation($ref);
            $str .= $presentation->getAsTableRow();
        }
        return $str . "</table>";
    }


    /**
     * Builds a Bootstrap tabbed panel containing an interactive reference list
     * with live CSL citation formatting.
     *
     * The panel contains a single "References" tab with:
     *  - A CSL style pulldown populated from Config::$CITATIONS_LIST.
     *  - A #csl_presentation div whose contents are replaced asynchronously
     *    whenever the pulldown selection changes or on initial page load.
     *
     * The JavaScript driving the dynamic formatting is appended after the panel
     * via getCiteScript(), targeting citeService.php with the given $oid and
     * the key "all" to request the full collection.
     *
     * @param int $oid Serialized object ID passed to the citation service so it
     *                 can retrieve the correct reference collection.
     *
     * @return string HTML markup for the tabbed panel followed by the inline
     *                <script> block.
     */
    public function getAllReferencePanel(int $oid): string {

        // CSL style pulldown — options sourced from Config::$CITATIONS_LIST
        $pulldown = htmlPage::makeOptionFromArray("citebarpulldown", Config::$CITATIONS_LIST);

        $content = "<div id=\"citebar\" class=\"d-flex align-items-center mb-1\">"
                 . "<span class=\"small text-muted me-1\">CSL&nbsp;</span>$pulldown"
                 . "</div>"
                 . "<div id=\"csl_presentation\"></div>";

        return "<div class=\"w-100\">$content</div>"
             . ReferenceCollectionPresentation::getCiteScript($oid, "all");
    }


    /**
     * Returns an inline JavaScript block that drives the CSL citation
     * formatting panel produced by getAllReferencePanel().
     *
     * Behaviour:
     *  - On document ready and on every change of #citebarpulldown, calls
     *    citeBarPullDown() which:
     *      1. Reads the selected CSL style from the pulldown.
     *      2. Makes an async GET request to citeService.php, passing $oid,
     *         $key, and the selected style.
     *      3. Replaces the contents of #csl_presentation with the returned HTML.
     *
     * The three async steps (fetchData, updateData, scrollFunc) are chained
     * with await inside citeBarPullDown() to ensure sequential execution.
     *
     * @param int    $oid Serialized object ID; embedded directly in the service URL.
     * @param string $key Reference key to format, or "all" for the full collection.
     *
     * @return string A <script type="text/javascript"> block as a string.
     */
    public static function getCiteScript(int $oid, string $key): string {
        return "<script type=\"text/javascript\">

        // Trigger reformatting when the CSL style selection changes or on initial load
        $('#citebarpulldown').change(function() { citeBarPullDown(); });
        $(document).ready(function() { citeBarPullDown(); });

        /**
         * Orchestrates the citation fetch → DOM update sequence.
         * Awaits each step to ensure the panel is updated before scrolling.
         */
        async function citeBarPullDown() {
            var selected = $('#citebarpulldown').val();
            var values   = await fetchData(selected);
            var updated  = await updateData(values);
            var scroll   = await scrollFunc();
        }

        /**
         * Fetches formatted citation HTML from citeService.php.
         *
         * @param {string} selected - The CSL style identifier chosen in the pulldown.
         * @returns {Promise<string>} The formatted HTML returned by the service.
         */
        async function fetchData(selected) {
            try {
                const result = await $.ajax({
                    url:      './services/reference/citeService.php?oid=$oid&&key=" . urlencode($key) . "&&style=' + selected,
                    type:     'GET',
                    dataType: 'html'
                });
                return result;
            } catch (error) {
                console.error('Request failed:', error);
            }
        }

        /**
         * Replaces the contents of #csl_presentation with freshly formatted HTML,
         * then initialises DataTables on the injected reference table.
         *
         * @param {string} values - HTML string returned by fetchData().
         */
        async function updateData(values) {
            if ($.fn.DataTable.isDataTable('#csl_ref_table')) {
                $('#csl_ref_table').DataTable().destroy();
            }
            $('#csl_presentation').empty();
            if (!values) return;
            let newDiv = $('<div id=\"newDiv\">' + values + '</div>');
            $('#csl_presentation').append(newDiv);
            if ($('#csl_ref_table').length) {
                $('#csl_ref_table').DataTable({
                    paging:    false,
                    destroy:   true,
                    autoWidth: false,
                    order:     [[2, 'desc']],
                    columnDefs: [
                        { orderable: false, targets: 3 },
                        { width: '12%',  targets: 0 },
                        { width: '78%',  targets: 1 },
                        { width: '7%',   targets: 2 },
                        { width: '3%',   targets: 3 }
                    ]
                });
            }
        }

        </script>";
    }


    /**
     * Renders a form containing a single button that triggers batch reference
     * checking for the entire collection.
     *
     * The form posts to $target with:
     *  - `oid`     — the serialized object ID identifying the article/collection.
     *  - `task_id` — the database ID of the reference-checker task to execute.
     *
     * The receiving action handler is responsible for iterating the collection
     * and performing DOI/metadata lookups on each reference.
     *
     * @param string $target  Form action URL (the task's action handler).
     * @param int    $oid     Serialized object ID of the owning article.
     * @param int    $task_id Database ID of the reference-checker task record.
     *
     * @return string HTML <form> markup containing the submit button.
     */
    public function getReferenceCheckButton(string $target, int $oid, int $task_id): string {
        $buttonid = "referenceChecker";
        $form     = "<form action=\"" . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . "\" method=\"post\">"
                  . htmlPage::makeHiddenInput("oid", $oid)
                  . htmlPage::makeHiddenInput("task_id", $task_id)
                  . "<button class=\"btn btn-outline-primary\" name=\"$buttonid\" id=\"$buttonid\" type=\"SUBMIT\">"
                  . "Reference Check All References"
                  . "</button>"
                  . "</form>";
        return $form;
    }

}
?>