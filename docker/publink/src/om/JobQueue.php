<?php
namespace Biblhertz\Publink\om;

use Biblhertz\Publink\om\BHObject;
use Biblhertz\Publink\om\Job;
use Biblhertz\Publink\utilities\PDODatabase;

/**
 * JobQueue
 *
 * A general-purpose collection class for querying and managing {@see Job}
 * objects in bulk. Unlike Job, JobQueue does not correspond to a single
 * database row — it is a stateless service object whose static methods act
 * as query factories over the `job` (active) and `job_log` (archived) tables.
 *
 * Typical usage:
 * - Retrieve pending or completed jobs for a user or across all users.
 * - Flush the entire active queue (e.g. before stopping the worker process).
 *
 * All query methods return arrays of fully hydrated {@see Job} instances
 * constructed via {@see Job::makeNewWithSQLRecord()}, avoiding the per-row
 * SELECT overhead of the standard Job constructor.
 *
 * @package  Biblhertz\Publink\om
 * @author   Chris Tomlinson
 * @since    March 2023
 */
class JobQueue extends BHObject {

    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs a JobQueue service object.
     *
     * No $id is accepted because JobQueue has no corresponding database row.
     * $tableName is set to 'job' to satisfy the BHObject contract, though
     * fetchItem() should not be called on this class.
     *
     * @param PDODatabase $objDB Active database connection
     */
    public function __construct(PDODatabase $objDB) {
        $this->tableName = "job";
        $this->objDB     = $objDB;
    }


    /****************************************************************/
    /*  ACCESS-CONTROL METHODS                                      */
    /****************************************************************/

    /**
     * Deletion of the queue object itself is always forbidden.
     *
     * Individual job removal is handled by {@see Job::archiveJob()} and
     * bulk removal by {@see deleteAllJobs()}.
     *
     * @param int $id User ID (unused)
     * @return bool   Always false
     */
    public function canDelete(int $id): bool {
        return false;
    }

    /**
     * Editing of the queue object itself is always forbidden.
     *
     * Queue state is modified only through the dedicated Job persistence methods.
     *
     * @param int $id User ID (unused)
     * @return bool   Always false
     */
    public function canEdit(int $id): bool {
        return false;
    }

    /**
     * Viewing of the queue object itself is always forbidden via generic access control.
     *
     * Job data is exposed through the static query methods below rather than
     * through per-object permission checks.
     *
     * @param int $id User ID (unused)
     * @return bool   Always false
     */
    public function canView(int $id): bool {
        return false;
    }


    /****************************************************************/
    /*  INHERITED METHODS (BHObject overrides)                      */
    /****************************************************************/

    /**
     * Returns a minimal Bootstrap-styled HTML table for this object.
     *
     * @return string HTML table string
     */
    public function getAsTable(): string {
        return "<table class=\"table striped\"><tr><th>ID</th><th>" . $this->getName() . "</th></tr></table>";
    }


    /****************************************************************/
    /*  STATIC QUERY METHODS                                        */
    /****************************************************************/

    /**
     * Returns all active (pending/in-progress) jobs for a specific user.
     *
     * Queries the `job` table, ordered newest-first by ID. Each row is
     * hydrated into a Job via {@see Job::makeNewWithSQLRecord()}.
     *
     * @param int         $uid   ID of the user whose jobs to retrieve
     * @param PDODatabase $objDB Active database connection
     * @return Job[]             Array of Job objects, empty if none found
     */
    public static function getJobsForAUser(int $uid, PDODatabase $objDB): array {
        $jobs     = [];
        $jobfetch = $objDB->preparedSelect(
            "select * from job where user_details_id = ? order by id desc",
            [$uid]
        );
        while ($jobRec = $jobfetch->fetch()) {
            $jobs[] = Job::makeNewWithSQLRecord($objDB, $jobRec);
        }
        return $jobs;
    }

    /**
     * Returns all archived (completed or failed) jobs for a specific user.
     *
     * Queries the `job_log` table, ordered newest-first by ID. Records in
     * `job_log` are written by {@see Job::archiveJob()} on completion.
     *
     * @param int         $uid   ID of the user whose completed jobs to retrieve
     * @param PDODatabase $objDB Active database connection
     * @return Job[]             Array of Job objects, empty if none found
     */
    public static function getCompletedJobsForAUser(int $uid, PDODatabase $objDB): array {
        $jobs     = [];
        $jobfetch = $objDB->preparedSelect(
            "select * from job_log where user_details_id = ? order by id desc",
            [$uid]
        );
        while ($jobRec = $jobfetch->fetch()) {
            $jobs[] = Job::makeNewWithSQLRecord($objDB, $jobRec);
        }
        return $jobs;
    }

    /**
     * Returns all active (pending/in-progress) jobs across all users.
     *
     * Queries the entire `job` table, ordered newest-first. Intended for
     * administrative views and queue monitoring.
     *
     * @param PDODatabase $objDB Active database connection
     * @return Job[]             Array of Job objects, empty if the queue is empty
     */
    public static function getAllJobs(PDODatabase $objDB): array {
        $jobs     = [];
        $jobfetch = $objDB->preparedSelect(
            "select * from job order by id desc",
            []
        );
        while ($jobRec = $jobfetch->fetch()) {
            $jobs[] = Job::makeNewWithSQLRecord($objDB, $jobRec);
        }
        return $jobs;
    }

    /**
     * Returns all archived (completed or failed) jobs across all users.
     *
     * Queries the entire `job_log` table, ordered newest-first. Intended for
     * administrative reporting and audit views.
     *
     * @param PDODatabase $objDB Active database connection
     * @return Job[]             Array of Job objects, empty if no jobs have been archived
     */
    public static function getAllCompletedJobs(PDODatabase $objDB): array {
        $jobs     = [];
        $jobfetch = $objDB->preparedSelect(
            "select * from job_log order by id desc",
            []
        );
        while ($jobRec = $jobfetch->fetch()) {
            $jobs[] = Job::makeNewWithSQLRecord($objDB, $jobRec);
        }
        return $jobs;
    }

    /**
     * Deletes all active jobs from the `job` table unconditionally.
     *
     * Called by {@see Job::stopTask()} before killing the worker process to
     * ensure no orphaned queue entries remain. This operation is irreversible
     * and bypasses the normal archive lifecycle — job_log is NOT updated.
     *
     * !! Use with extreme caution. Any in-flight jobs will be permanently lost.
     *
     * @param PDODatabase $objDB Active database connection
     *
     * @todo Consider archiving jobs as errors before deletion to preserve audit
     *       history, rather than silently dropping them.
     * @todo The return value of preparedSelect() is discarded — use
     *       preparedStatement() instead for a DELETE with no result set.
     */
    public static function deleteAllJobs(PDODatabase $objDB): void {
        $objDB->preparedStatement("delete from job", []);
    }
}
?>