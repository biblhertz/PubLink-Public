<?php
namespace Biblhertz\Publink\om\presentation;

use Biblhertz\Publink\om\Job;
use Biblhertz\Publink\pages\Bibliotheca_Page;
use Biblhertz\Publink\pages\htmlPage;

/**
 * JobPresentation
 *
 * Presentation layer for Job objects in the PubLink job queue system.
 * Wraps a single Job instance and provides HTML rendering methods for
 * displaying job information in the queue management UI.
 *
 * A Job represents an individual queued or completed processing task,
 * sourced from either the job_queue or job_queue_status table.
 *
 * Rendering methods provided:
 * - getAsTable()          — minimal ID/name summary table.
 * - getJobAsTableRow()    — full <tr> for the job queue DataTable.
 * - getJobTableHeader()   — matching <thead> for the job queue table.
 * - getJobStatusString()  — completion summary with optional download link.
 * - getSubmitMessage()    — confirmation message shown after job submission.
 *
 * @package Biblhertz\Publink\om\presentation
 * @author  Chris Tomlinson
 * @since   March 2023
 */
class JobPresentation
{

    /********************************************************************/
    /*  INSTANCE VARIABLES                                              */
    /********************************************************************/

    /** @var Job The job instance this presentation object wraps. */
    private Job $job;


    /********************************************************************/
    /*  CONSTRUCTOR                                                     */
    /********************************************************************/

    /**
     * Construct a JobPresentation for the given Job.
     *
     * @param Job $j The job to present.
     */
    public function __construct(Job $j)
    {
        $this->job = $j;
    }


    /********************************************************************/
    /*  SUMMARY METHODS                                                 */
    /********************************************************************/

    /**
     * Render the job as a minimal Bootstrap striped table showing its ID and name.
     *
     * @return string HTML table string.
     */
    public function getAsTable(): string
    {
        return "<table class=\"table striped\"><tr><th>ID</th><th>" . $this->job->getName() . "</th></tr></table>";
    }


    /********************************************************************/
    /*  TABLE ROW METHODS                                               */
    /********************************************************************/

    /**
     * Render this job as a <tr> for the job queue management table.
     *
     * Columns rendered (matching getJobTableHeader()):
     * Job Name, Task Name, User Name, Parameters (serialised), Submitted At,
     * Finished At, Time Taken, Error Message, Log File link, Delete form.
     *
     * The Log File column shows a "View" link to logViewer.html only when
     * the job has an associated log file. The Delete column shows a form
     * with a "Del" button only when the job has a non-empty time taken value
     * (i.e. the job has completed); in-progress jobs show an empty cell.
     *
     * @return string HTML <tr> string.
     */
    public function getJobAsTableRow(): string
    {
        $str = "<tr>
                    <td>" . $this->job->getName() . "</td>
                    <td>" . $this->job->getTask()->getName() . "</td>
                    <td>" . $this->job->getUser()->getName() . "</td>
                    <td>" . serialize($this->job->getParameters()) . "</td>
                    <td>" . $this->job->getSubmittedAt() . "</td>
                    <td>" . $this->job->getFinishedAt() . "</td>
                    <td>" . $this->job->getTimeTaken() . "</td>
                    <td>" . $this->job->getErrorMessage() . "</td>";

        // Log file column — only linked when a log file exists for this job.
        $log = $this->job->getLogFileName();
        if (isset($log)) {
            $str .= "<td><a href=\"logViewer.html?jobId=" . $this->job->getID() . "&&logFile=true\">View</a></td>";
        } else {
            $str .= "<td></td>";
        }

        // Delete column — only shown for completed jobs (timeTaken is populated).
        $time = $this->job->getTimeTaken();
        if (!empty($time)) {
            $str .= "<td><form action=\"logViewer.html\">"
                  . htmlPage::makeHiddenInput("jobId", $this->job->getID())
                  . htmlPage::makeButton("removeJob", "Del")
                  . "</form></td></tr>";
        } else {
            $str .= "<td></td></tr>";
        }

        return $str;
    }

    /**
     * Render the <thead> row for the job queue management table.
     *
     * Column order matches getJobAsTableRow():
     * Job Name, Task Name, User Name, Parameters, Submitted At, Finished At,
     * Time Taken, Error Message, Log File, Del.
     *
     * @return string HTML <thead> string.
     */
    public static function getJobTableHeader(): string
    {
        return "<thead><tr>
                    <th>Job Name</th>
                    <th>Task Name</th>
                    <th>User Name</th>
                    <th>Parameters</th>
                    <th>Submitted At</th>
                    <th>Finished At</th>
                    <th>Time Taken</th>
                    <th>Error Message</th>
                    <th>Log File</th>
                    <th>Del</th>
                </tr></thead>";
    }


    /********************************************************************/
    /*  STATUS MESSAGE METHODS                                          */
    /********************************************************************/

    /**
     * Render an HTML completion summary for this job.
     *
     * Displays job name, task name, submitting user, submission time,
     * completion time, and time taken as a formatted list. When the job
     * produced an output file, a download link is appended using the
     * site root from Bibliotheca_Page and the user's profile download URL.
     *
     * @return string HTML string of the completion summary (opens a <ul> but
     *                does not close it — the caller must append </ul> if needed).
     */
    public function getJobStatusString(): string
    {
        $str = "Job <b>" . $this->job->getName() . "</b> of the task <b>"
             . $this->job->getTask()->getName() . "</b> has completed successfully"
             . "<ul>
                    <li>User<b>: " . $this->job->getUser()->getName() . "</b></li>
                    <li>Submitted at <b>: " . $this->job->getSubmittedAt() . "</b></li>
                    <li>Completed at : <b>" . $this->job->getFinishedAt() . "</b></li>
                    <li>Time Taken : <b>" . $this->job->getTimeTaken() . "</b></li>";

        // Append a download link if the job produced an output file.
        if ($this->job->getOutputFileID()) {
            $str .= "<li>Output File : <b><a href=\""
                  . Bibliotheca_Page::getSiteRoot()
                  . "/profile.html?uid=" . $this->job->getUser()->getID()
                  . "&&fileDownload=" . $this->job->getOutputFileID()
                  . "\">Download</a></li>";
        }

        return $str . "</ul>";
    }

    /**
     * Render an HTML confirmation message shown immediately after a job is queued.
     *
     * Displays the job name, task name, submitting user, and submission timestamp.
     *
     * @return string HTML string of the submission confirmation message.
     */
    public function getSubmitMessage(): string
    {
        return "Job <b>" . $this->job->getName() . "</b> of the task <b>"
             . $this->job->getTask()->getName() . "</b> has been put into the job queue"
             . "<ul>"
             . "<li>User<b>: " . $this->job->getUser()->getName() . "</b></li>"
             . "<li>Submitted at <b>: " . $this->job->getSubmittedAt() . "</b></li>"
             . "</ul>";
    }
}
?>