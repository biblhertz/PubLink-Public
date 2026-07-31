<?php
namespace Biblhertz\Publink\om;

use Biblhertz\Publink\om\BHObject;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\om\JobQueue;
use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\pages\htmlPage;
use Biblhertz\Publink\Config;
use Biblhertz\Publink\utilities\Utilities;
use Biblhertz\Publink\utilities\PDODatabase;
use Pheanstalk\Pheanstalk;
use DateTime;

/**
 * Job
 *
 * Represents a single unit of work dispatched through the PubLink job queue.
 * A Job is always an instance of a {@see Task} (which defines *what* to run)
 * owned by a {@see User} (who requested it), plus a set of runtime parameters.
 *
 * Jobs move through the following lifecycle:
 *   1. Created and persisted to the `job` table via {@see saveJob()}.
 *   2. Submitted to the Beanstalk queue via {@see putInQueue()}.
 *   3. Picked up by the worker process (`worker.sh`); status updated via
 *      {@see updateStatus()} as work progresses.
 *   4. On completion (success or failure), archived to `job_log` and removed
 *      from `job` via {@see archiveJob()} or {@see archiveError()}.
 *
 * The queue worker process itself is managed through the static helpers
 * {@see queueProcessExists()}, {@see startTask()}, and {@see stopTask()}.
 *
 * @package  Biblhertz\Publink\om
 * @author   Chris Tomlinson
 * @since    March 2023
 */
class Job extends BHObject {

    /****************************************************************/
    /*  INSTANCE VARIABLES                                          */
    /****************************************************************/

    /** @var Task The Task definition that this Job is an execution of */
    private Task $task;

    /** @var array Key/value parameters passed to the task at runtime, decoded from JSON */
    private array $parameters = [];

    /** @var string Timestamp when the job was submitted (Y-m-d H:i:s) */
    private string $submittedAt = "";

    /** @var string Timestamp when the job completed, success or failure (Y-m-d H:i:s) */
    private string $finishedAt = "";

    /** @var string Elapsed processing time in microseconds, computed by TIMESTAMPDIFF */
    private string $timeTaken = "";

    /** @var User The user who submitted this job */
    private User $user;

    /**
     * @var string Current status string, populated while the job is running.
     *             Examples: 'PROCESSING', 'ARCHIVED NEW'. Empty when pending.
     */
    private string $status = "";

    /** @var string Error message written on failure; empty string when no error occurred */
    private string $errorMessage = "";

    /** @var string Absolute path to the plain-text log file generated for this job */
    private string $logFileName = "";

    /**
     * @var int Primary key of the output {@see File} produced by this job, if any.
     *          Zero indicates no output file has been associated.
     */
    private int $outputFileID = 0;


    /****************************************************************/
    /*  CLASS CONSTRUCTOR                                           */
    /****************************************************************/

    /**
     * Constructs a Job, optionally hydrating it from the `job` table.
     *
     * When $id is supplied the constructor fetches the matching row and
     * instantiates the associated Task and User objects. Optional fields
     * (parameters, status, finished_at, etc.) are only set when present in
     * the record to avoid overwriting default empty values.
     *
     * For bulk construction from pre-fetched rows use the factory method
     * {@see makeNewWithSQLRecord()} to avoid redundant per-row DB queries.
     *
     * @param PDODatabase $objDB Active database connection
     * @param int|null    $id    Primary key of the `job` row to load, or null
     *
     */
    public function __construct(PDODatabase $objDB, int $id = null) {
        $this->tableName = "job";
        $this->objDB     = $objDB;

        if (isset($id)) {
            $this->id = $id;
            $rec = $this->fetchItem();

            if (isset($rec['name']))$this->name = $rec['name'];
            if (isset($rec['task_id']))$this->task = new Task($objDB, $rec['task_id']);
            if (isset($rec['submitted_at']))$this->setSubmittedAt($rec['submitted_at']);
            if (isset($rec['user_details_id']))$this->setUser(new User($objDB, $rec['user_details_id']));
            if (isset($rec['parameters']))   $this->setParameters(json_decode($rec['parameters'], true));
            if (isset($rec['status']))       $this->setStatus($rec['status']);
            if (isset($rec['finished_at']))  $this->setFinishedAt($rec['finished_at']);   
            if (isset($rec['time_taken']))   $this->setTimeTaken($rec['time_taken']);    
            if (isset($rec['error_message']))$this->setErrorMessage($rec['error_message']);
            if (isset($rec['log_file']))     $this->setLogFileName($rec['log_file']);
            if (isset($rec['output_file_id'])) $this->outputFileID = (int) $rec['output_file_id'];
        }
        else $this->id=0;
    }


    /****************************************************************/
    /*  FACTORY CONSTRUCTOR                                         */
    /****************************************************************/

    /**
     * Constructs a Job from a pre-fetched database row array.
     *
     * PHP does not support constructor overloading, so this static factory
     * provides an alternative entry point for bulk-loading scenarios (e.g.
     * when a parent query has already retrieved all columns). This avoids the
     * extra per-row SELECT that the standard constructor would issue.
     *
     * @param PDODatabase $objDB Active database connection
     * @param array       $rec   Associative array matching the `job` table columns
     * @return Job               Fully populated Job instance
     */
    public static function makeNewWithSQLRecord(PDODatabase $objDB, array $rec): Job {
        $job  = new Job($objDB);    // Empty shell — no DB fetch

        $job->setID($rec['id']);
        $job->setName($rec['name']);
        $job->setTask(new Task($objDB, $rec['task_id']));
        $job->setUser(new User($objDB, $rec['user_details_id']));
        $job->setSubmittedAt($rec['submitted_at']);

        if (isset($rec['parameters']))    $job->setParameters(json_decode($rec['parameters'], true));
        if (isset($rec['status']))        $job->setStatus($rec['status']);
        if (isset($rec['finished_at']))   $job->setFinishedAt($rec['finished_at']);
        if (isset($rec['time_taken']))    $job->setTimeTaken($rec['time_taken']);
        if (isset($rec['error_message'])) $job->setErrorMessage($rec['error_message']);
        if (isset($rec['log_file']))      $job->setLogFileName($rec['log_file']);
        if (isset($rec['output_file_id']))$job->setOutputFileID((int) $rec['output_file_id']);

        return $job;
    }


    /****************************************************************/
    /*  ACCESSOR METHODS                                            */
    /****************************************************************/

    /**
     * Sets the Task definition for this Job.
     *
     * @param Task $t
     */
    public function setTask(Task $t): void {
        $this->task = $t;
    }

    /**
     * Returns the Task definition that this Job executes.
     *
     * @return Task
     */
    public function getTask(): Task {
        return $this->task;
    }

    /**
     * Sets the submission timestamp.
     *
     * @param string $s Timestamp string (Y-m-d H:i:s)
     */
    public function setSubmittedAt(string $s): void {
        $this->submittedAt = $s;
    }

    /**
     * Returns the submission timestamp.
     *
     * @return string Y-m-d H:i:s
     */
    public function getSubmittedAt(): string {
        return $this->submittedAt;
    }

    /**
     * Sets the completion timestamp.
     *
     * @param string $s Timestamp string (Y-m-d H:i:s)
     */
    public function setFinishedAt(string $s): void {
        $this->finishedAt = $s;
    }

    /**
     * Returns the completion timestamp, or an empty string if not yet finished.
     *
     * @return string Y-m-d H:i:s, or ''
     */
    public function getFinishedAt(): string {
        return $this->finishedAt;
    }

    /**
     * Sets the elapsed processing time string (microseconds from TIMESTAMPDIFF).
     *
     * @param string $s
     */
    public function setTimeTaken(string $s): void {
        $this->timeTaken = $s;
    }

    /**
     * Returns the elapsed processing time in microseconds, or '' if not yet set.
     *
     * @return string
     */
    public function getTimeTaken(): string {
        return $this->timeTaken;
    }

    /**
     * Sets the runtime parameters for this Job.
     *
     * Parameters are stored as a JSON-encoded string in the database and
     * decoded back to an array on load.
     *
     * @param array $a Key/value parameter map
     */
    public function setParameters(array $a): void {
        $this->parameters = $a;
    }

    /**
     * Returns the runtime parameter array for this Job.
     *
     * @return array
     */
    public function getParameters(): array {
        return $this->parameters;
    }

    /**
     * Sets the owning User for this Job.
     *
     * @param User $u
     */
    public function setUser(User $u): void {
        $this->user = $u;
    }

    /**
     * Returns the User who submitted this Job.
     *
     * @return User
     */
    public function getUser(): User {
        return $this->user;
    }

    /**
     * Sets the status string for this Job.
     *
     * Status is stored in the `job.status` column and updated in real time by
     * the worker process via {@see updateStatus()}.
     *
     * @param string $s Status label (e.g. 'PROCESSING', 'ARCHIVED NEW')
     */
    public function setStatus(string $s): void {
        $this->status = $s;
    }

    /**
     * Returns the current status string, or '' if no status has been set.
     *
     * @return string
     */
    public function getStatus(): string {
        return $this->status;
    }

    /**
     * Sets the error message for this Job.
     *
     * @param string $s Error description; empty string indicates no error
     */
    public function setErrorMessage(string $s): void {
        $this->errorMessage = $s;
    }

    /**
     * Returns the error message, or '' if the job succeeded.
     *
     * @return string
     */
    public function getErrorMessage(): string {
        return $this->errorMessage;
    }

    /**
     * Generates and stores a timestamped log filename for this Job.
     *
     * The filename is composed of the job name, a formatted datetime string,
     * and a '_log.txt' suffix, placed in the directory defined by
     * Config::$JOB_LOG_DIR. The result is stored in $this->logFileName but
     * not yet written to disk or the database.
     */
    public function generateLogFileName(): void {
        $dt       = new DateTime("now");
        $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $this->name);
        $this->logFileName = Config::$JOB_LOG_DIR
            . DIRECTORY_SEPARATOR
            . $safeName . "_"
            . $dt->format('d-m-Y_H_i_s')
            . '.log';
    }

    /**
     * Returns the path to the log file for this Job.
     *
     * @return string Absolute path, or '' if not yet generated
     */
    public function getLogFileName(): string {
        return $this->logFileName;
    }

    /**
     * Sets the log file path directly (e.g. when loading from the database).
     *
     * @param string $lf Absolute path to the log file
     */
    public function setLogFileName(string $lf): void {
        $this->logFileName = $lf;
    }

    /**
     * Returns the primary key of the output File associated with this Job.
     *
     * @return int File ID, or 0 if no output file has been produced
     */
    public function getOutputFileID(): int {
        return $this->outputFileID;
    }

    /**
     * Associates an output File with this Job and persists the link immediately.
     *
     * Writes the file ID to the `job.output_file_id` column straight away so
     * the worker process and the UI stay in sync without requiring a full
     * {@see saveJob()} call.
     *
     * @param int $fid Primary key of the output `file` record
     */
    public function setOutputFileID(int $fid): void {
        $vals = ['output_file_id' => $fid];
        $this->getObjDB()->update("job", $vals, "id=" . (int) $this->getID());
        $this->outputFileID = $fid;
    }


    /****************************************************************/
    /*  ACCESS-CONTROL METHODS                                      */
    /****************************************************************/

    /**
     * Job deletion is always forbidden through the object model.
     *
     * Removal is handled internally by {@see archiveJob()} and
     * {@see archiveError()}, which move the record to `job_log` before
     * deleting it from `job`. Direct deletion via canDelete() is therefore
     * deliberately blocked.
     *
     * @param int $id User ID (unused)
     * @return bool   Always false
     */
    public function canDelete(int $id): bool {
        return false;
    }

    /**
     * Job editing is always forbidden through the object model.
     *
     * Job state is mutated only through dedicated methods (updateStatus,
     * setOutputFileID, archiveJob) to ensure consistent lifecycle management.
     *
     * @param int $id User ID (unused)
     * @return bool   Always false
     */
    public function canEdit(int $id): bool {
        return false;
    }

    /**
     * Job viewing is always forbidden through the generic access-control path.
     *
     * Access to job data is mediated by higher-level controllers rather than
     * per-object permission checks.
     *
     * @param int $id User ID (unused)
     * @return bool   Always false
     *
     * @todo Review whether owner-based visibility (user_details_id == $id)
     *       should be implemented here for consistency with File and other objects.
     */
    public function canView(int $id): bool {
        return false;
    }


    /****************************************************************/
    /*  INHERITED METHODS (BHObject overrides)                      */
    /****************************************************************/

    /**
     * Returns a minimal Bootstrap-styled HTML table for this Job.
     *
     * @return string HTML table string
     */
    public function getAsTable(): string {
        return "<table class=\"table striped\"><tr><th>ID</th><th>" . $this->getName() . "</th></tr></table>";
    }


    /****************************************************************/
    /*  QUEUE METHODS                                               */
    /****************************************************************/

    /**
     * Submits this Job to the Beanstalk queue for asynchronous processing.
     *
     * Checks whether the queue worker process is already running and starts it
     * if not. Connects to the local Beanstalk server, selects the 'general_tube',
     * and places a JSON-encoded payload containing the task ID, parameters, and
     * user ID. The worker picks this up and executes the corresponding Task.
     *
     * The job must have been persisted via {@see saveJob()} before calling this
     * method so that a valid task_id and user_id are available.
     *
     * @see queueProcessExists()
     * @see startTask()
     */
    public function putInQueue(): void {
        // Ensure the background worker is running before enqueuing
        if (!$this->queueProcessExists()) $this->startTask();

        $pheanstalk = Pheanstalk::create('beanstalkd');
        $pheanstalk->useTube('general_tube');

        $data = [
            'task_id'    => $this->task->getID(),
            'parameters' => $this->parameters,
            'user_id'    => $this->user->getID(),
        ];

        $encodedData = json_encode($data);

        if(Config::$SCHEDULER_DEBUG){
            // Log the enqueued payload for debugging
            error_log(str_repeat('-', 105));
            error_log("Encoded Data");
            error_log($encodedData);
            error_log(str_repeat('-', 105));
        }

        $pheanstalk->put($encodedData);
    }


    /**
     * Returns true if this job has been cancelled by an administrator.
     *
     * Re-queries the database on each call so it reflects real-time state.
     * Long-running job scripts should call this at natural breakpoints (e.g.
     * between loop iterations) and abort early when it returns true.
     *
     * Returns true in two cases:
     *   - The row's status column has been set to 'CANCELLED'.
     *   - The row no longer exists (hard-deleted by an older code path).
     *
     * @return bool
     */
    public function isCancelled(): bool {
        $status = $this->objDB->preparedGetOne(
            "SELECT status FROM job WHERE id = ?",
            [$this->id]
        );
        return $status === false || $status === 'CANCELLED';
    }


    /****************************************************************/
    /*  STATIC QUEUE PROCESS MANAGEMENT                             */
    /****************************************************************/

    /**
     * job queue process name
     */
    private static string $processName = "/var/www/job_queue/worker.php";
    /**
     * Checks whether the queue worker process is currently running in this VM.
     *
     * Uses `pgrep -f` to search the full command line for the process name.
     * Unlike `ps | grep`, pgrep never includes itself in the results, so any
     * non-empty output means the worker is running.
     *
     * @return bool True if the worker process appears to be running
     */
    public static function queueProcessExists(): bool {
        $pn = self::$processName;
        // Use proc_open with an array to avoid exec()'s sh -c wrapper.
        // exec() spawns: sh -c "pgrep -f '...'" — the shell process has the
        // search string in its own command line, so pgrep always finds a match.
        // proc_open with an array bypasses the shell entirely.
        $proc = proc_open(
            ['pgrep', '-f', $pn],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) return false;
        $stdout   = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        $result   = $exitCode === 0;
        error_log("$pn :: " . ($result ? 'running' : 'not found') . " :: " . trim($stdout));
        return $result;
    }

    /**
     * Starts the queue worker process as a background daemon.
     *
     * Executes `worker.sh` in the background, redirecting stdout to a shared
     * output log. Returns immediately; the process runs independently.
     *
     * @return bool Always true (system() does not return a useful exit code here)
     */
    public static function startTask(): bool {
        $log = Config::$JOB_LOG_DIR . "/job_output_log.txt";
        $cmd = 'nohup ' . Config::$SCHEDULER_DAEMON . ' >> ' . $log . ' 2>&1 &';
        error_log("Starting queue :: $cmd");
        exec($cmd);
        return true;
    }

    /**
     * Stops the queue worker process after draining the job queue.
     *
     * Verifies the process is running, deletes all pending jobs from the queue
     * via {@see JobQueue::deleteAllJobs()}, then kills the worker by name.
     *
     * Uses proc_open with an array (no shell) to match the approach used by
     * queueProcessExists() — this avoids the path-character validation in
     * killProcessByName() which would silently reject the process name.
     *
     * !! This is a destructive operation — any in-flight jobs will be lost.
     *
     * @param PDODatabase $objDB Database connection passed to JobQueue::deleteAllJobs()
     */
    public static function stopTask(PDODatabase $objDB): void {
        if (Job::queueProcessExists()) {
            JobQueue::deleteAllJobs($objDB);
            $proc = proc_open(
                ['pkill', '-9', '-f', self::$processName],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (is_resource($proc)) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
            }
        }
    }


    /****************************************************************/
    /*  SAVE / PERSISTENCE METHODS                                  */
    /****************************************************************/

    /**
     * Persists this Job to the `job` table (insert or update).
     *
     * On first save a unique name is generated via uniqid() and the current
     * timestamp is recorded as submitted_at. On subsequent calls (when $this->id
     * is already set) the existing row is updated in place.
     *
     * @return int The primary key of the saved `job` row
     */
    public function saveJob(): int {
        $vals = [];

        // Generate a unique identifier for the job name on first save
        $this->name        = $vals['name']         = uniqid();
        $vals['task_name']                          = $this->task->getName();
        $vals['task_id']                            = $this->task->getID();
        $vals['user_details_id']                    = $this->user->getID();
        $this->submittedAt = $vals['submitted_at']  = htmlPage::getNowAsSQLTimeStamp();
        $vals['parameters']                         = json_encode($this->parameters);

        if ($this->id === 0)
            $this->id = $this->objDB->insert("job", $vals);
        else
            $this->objDB->update("job", $vals, "id=" . (int) $this->id);

        return $this->id;
    }

    /**
     * Writes a status message to the `job.status` column in the database.
     *
     * Called by the worker process to report progress without triggering a
     * full saveJob() round-trip.
     *
     * @param string $message New status string to store
     */
    public function updateStatus(string $message): void {
        $vals = ['status' => $message];
        $this->objDB->update("job", $vals, "id=" . (int) $this->id);
    }

    /**
     * Archives a completed (or failed) Job to `job_log` and removes it from `job`.
     *
     * Copies all relevant fields to `job_log`, records the finish time and
     * microsecond duration via TIMESTAMPDIFF, then deletes the source row from
     * `job`. A guard on the job name prevents archiving an uninitialised object.
     *
     * @param bool|string $error False on success, or an error description string
     *                           on failure. When truthy, an 'ERROR :: ...' message
     *                           is stored in the error_message column.
     * @return bool              Always true if execution reaches the end
     *
     */
    public function archiveJob(bool|string $error = false): bool {
        $vals = [];
        //$vals['id']              = $this->id;
        $vals['name']            = $this->name;
        if(isset($this->task)){
            $vals['task_name']       = $this->task->getName();
            $vals['task_id']         = $this->task->getID();
        }
        if(isset($this->user))$vals['user_details_id'] = $this->user->getID();
        $vals['submitted_at']    = $this->submittedAt;
        $vals['parameters']      = json_encode($this->parameters);
        $vals['finished_at']     = htmlPage::getNowAsSQLTimeStamp();
        $vals['status']          = "ARCHIVED NEW";
        $vals['log_file']        = $this->logFileName;

        if ($error) $vals['error_message'] = "ERROR :: $error";

        // Compute elapsed time in microseconds between submission and completion
        $vals['time_taken'] = $this->objDB->preparedGetOne(
            "select TIMESTAMPDIFF(MICROSECOND,?,?)",
            [$this->submittedAt, $vals['finished_at']]
        );

        if ($this->outputFileID > 0) $vals['output_file_id'] = $this->outputFileID;

        // Only archive if we have a valid name (guard against uninitialised jobs)
        if (!empty($vals['name'])) $this->objDB->insert("job_log", $vals);

        $this->objDB->preparedSelect("delete from job where id = ?", [$this->id]);

        return true;
    }

    /**
     * Removes this Job from the active queue and archives it as a failed job.
     *
     * Deletes the `job` row first, then delegates to {@see archiveJob()} with
     * the error message. This order ensures the job cannot be re-picked by the
     * worker after the delete but before the archive completes.
     *
     * @param string $message Human-readable description of the error
     */
    public function archiveError(string $message): void {
        $this->objDB->startTransaction();
        try {
            $this->archiveJob($message);
            $this->objDB->commit();
        } catch (\Exception $e) {
            $this->objDB->rollBack();
            throw $e;
        }
    }

    /**
     * Placeholder for direct job deletion.
     *
     * @todo Implement or remove. Lifecycle removal should go through
     *       {@see archiveJob()} or {@see archiveError()} instead.
     
    public function deleteJob(): void {
        // Not yet implemented
    }
	*/
}
?>