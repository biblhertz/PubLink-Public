<?php
namespace Biblhertz\Publink\om\presentation;

use Biblhertz\Publink\om\presentation\JobPresentation;
use Biblhertz\Publink\om\JobQueue;

/**
 * JobQueuePresentation
 *
 * Presentation layer for the JobQueue object. Provides HTML rendering of
 * the full job queue as a DataTable-enhanced Bootstrap table.
 *
 * Each row in the table is rendered by JobPresentation::getJobAsTableRow(),
 * with the column headers supplied by JobPresentation::getJobTableHeader().
 *
 * @package Biblhertz\Publink\om\presentation
 * @author  Chris Tomlinson
 * @since   March 2023
 */
class JobQueuePresentation
{

    /********************************************************************/
    /*  INSTANCE VARIABLES                                              */
    /********************************************************************/

    /**
     * @var JobQueue The job queue instance this presentation object wraps.
     */
    private $queue;


    /********************************************************************/
    /*  CONSTRUCTOR                                                     */
    /********************************************************************/

    /**
     * Construct a JobQueuePresentation for the given JobQueue.
     *
     * @param JobQueue $queue The job queue to present.
     */
    public function __construct(JobQueue $queue)
    {
        $this->queue = $queue;
    }


    /********************************************************************/
    /*  PRESENTATION METHODS                                            */
    /********************************************************************/

    /**
     * Render an array of Job objects as a DataTable-enhanced Bootstrap table.
     *
     * Iterates $jobItems, rendering each as a <tr> via JobPresentation::getJobAsTableRow().
     * The <thead> is prepended on the first iteration using JobPresentation::getJobTableHeader().
     * Returns an empty string if $jobItems is empty.
     *
     * Initialises a jQuery DataTable (paging enabled, destroy:true to allow
     * re-initialisation) and Bootstrap tooltips on DOM ready.
     *
     * Note: The <table> element has two `class` attributes — the second
     * (`class="col-sm-12"`) silently overrides the first in all browsers.
     * This should be merged into a single class attribute.
     *
     * @param array $jobItems Array of Job objects to render.
     * @return string HTML string containing the table and inline <script>,
     *                wrapped in a Bootstrap container div. Empty string if no jobs.
     * @todo Merge duplicate `class` attributes on the <table> element.
     */
    public static function getJobQueueAsTable(array $jobItems): string
    {
        if (count($jobItems) == 0) return "";

        $tableId = uniqid("table_");

        // Note: two class attributes present — second overrides first in browsers.
        $table = "<table class=\"table table-sm responsive col-sm-12\" id=\"$tableId\">";
        $first = true;

        foreach ($jobItems as $job) {
            if ($first) {
                $table .= JobPresentation::getJobTableHeader() . "<tbody>";
                $first  = false;
            }
            $jobpresentation = new JobPresentation($job);
            $table .= $jobpresentation->getJobAsTableRow();
        }

        $table .= "</tbody></table>";

        $script = "<script>
            $(document).ready(function() {
                $('#$tableId').DataTable({paging: true, destroy: true});
                $('[data-toggle=\"tooltip\"]').tooltip();
            });
        </script>";

        return "<div class=\"container\">$table $script</div>";
    }
}
?>