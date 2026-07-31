# Adding a New Task to PubLink

This guide walks through every step required to add a new background processing task to the PubLink system. A "task" is a unit of work (e.g. convert a file, generate a manifest) that is submitted by a user via the web interface, queued in Beanstalkd, and executed asynchronously by the worker process. SQL commands can be performed from the mysql client that needs to be installed alongside Publink.

---

## Overview of the Architecture

```
Browser (form POST)
  └─► html/handlers/myTask.php        — validates input, enqueues job
        └─► Beanstalkd queue
              └─► job_queue/worker.php (daemon)
                    └─► job_queue/myTask.php   — does the actual work
```

Three layers are involved:

| Layer | File | Context |
|---|---|---|
| **Handler** | `html/handlers/myTask.php` | Web request (Apache) |
| **Job script** | `job_queue/myTask.php` | Worker daemon |
| **Database** | `task`, `task_file_type`, `intranet_scripts` rows | MySQL |

---

## Step 1 — Insert the Task Row

Insert a row into the `task` table. Every task needs:

| Column | Type | Description |
|---|---|---|
| `name` | varchar(100) | Human-readable name shown in the UI |
| `description` | text | Longer description of what the task does |
| `action_handler` | varchar(255) | Path to the handler, relative to `html/` (e.g. `./handlers/myTask.php`) |
| `action_text` | varchar(50) | Label for the submit button (e.g. `Run Task`) |
| `input_type` | enum | One of: `Single File`, `Multiple File`, `Object`, `Single File or Single Object`, `Single File and Single Object` |
| `public` | tinyint | `1` = visible in task list, `0` = hidden |

**Example:**

```sql
INSERT INTO `task` (`name`, `description`, `action_handler`, `action_text`, `input_type`, `public`)
VALUES (
    'My New Task',
    'Processes a JATS XML file and produces a result.',
    './handlers/myTask.php',
    'Run Task',
    'Single File',
    1
);
```

Note the ID that MySQL assigns — you will need it in the next steps.

---

## Step 2 — Link the Permitted Input File Types

The `task_file_type` table is a many-to-many join between `task` and `file_type`. It controls which file types the user is allowed to select as input when running the task.

First, find the `file_type.id` values for the types you need:

```sql
SELECT id, name FROM file_type;
```

Common values in this system:

| id | name |
|---|---|
| 1 | pdf |
| 17 | JATS xml |
| 19 | bib |
| 20 | json |

Then insert one row per permitted type:

```sql
INSERT INTO `task_file_type` (`file_type_id`, `task_id`) VALUES (17, <your_task_id>);
-- Add more rows for each additional permitted file type
```

---

## Step 3 — Grant User Permission

The `user_details_task` table controls which users may execute each task. Without a row here `Task::canExecute()` will return `false` and the handler will reject the submission. This can also be done on a user by user basis from the GUI by the super user account.

```sql
INSERT INTO `user_details_task` (`user_details_id`, `task_id`)
VALUES (<user_id>, <your_task_id>);
```

To grant access to all existing users at once:

```sql
INSERT INTO `user_details_task` (`user_details_id`, `task_id`)
SELECT id, <your_task_id> FROM user_details;
```

---

## Step 4 — Register the Handler in `intranet_scripts`

The `Bibliotheca_Intranet_Page` constructor looks up the calling script's basename in the `intranet_scripts` table to determine the minimum user group required to access it. If no row is found, access defaults to group 21 (very restrictive). Add a row with a permissive group ID (typically `1`): ID 1 allows any user to use the handler, ID 20 allows only the super user.

```sql
INSERT IGNORE INTO `intranet_scripts` (`id`, `name`, `user_group_id`)
VALUES (NULL, 'myTask.php', 1);
```

Omitting this row causes the handler to return an HTML error page instead of JSON, which will appear as a `SyntaxError` in the browser console if the handler is called via `fetch()`.

---

## Step 5 — Write the Handler

Create `html/handlers/myTask.php`. The handler runs in the Apache request context. Its job is to validate input, create a `Job` record, and put it in the Beanstalkd queue.

```php
<?php
require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\Task;
use Biblhertz\Publink\om\Job;
use Biblhertz\Publink\om\presentation\JobPresentation;

$page = new Bibliotheca_Content_Page();

try {

    // 1. Load and authorise the task
    if (!isset($_POST['task_id']) || !is_numeric($_POST['task_id'])) {
        throw new Exception("Task ID not defined");
    }
    $task = new Task($page->getObjDB(), (int) $_POST['task_id']);
    if (!$task->canExecute($page->getUser())) {
        throw new Exception("User does not have permission to execute this task");
    }

    // 2. Validate the article/object ID used for redirect
    if (!isset($_POST['oid']) || !is_numeric($_POST['oid'])) {
        throw new Exception("Object ID not defined");
    }
    $oid = (int) $_POST['oid'];

    // 3. Load and authorise the input file
    if (!isset($_POST['file_id']) || !is_numeric($_POST['file_id'])) {
        throw new Exception("File ID not defined");
    }
    $file = new File($page->getObjDB(), (int) $_POST['file_id']);
    if (!$file->canExecute($page->getUser()->getID())) {
        throw new Exception("User does not have permission to use this file");
    }

    // 4. Validate any required task-specific parameters
    foreach (['my_required_param'] as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Required field '$field' is missing");
        }
    }

    // 5. Create the job — two-phase save
    $job = new Job($page->getObjDB());
    $job->setTask($task);
    $job->setUser($page->getUser());
    $jobID = $job->saveJob();   // Phase 1: insert and obtain ID

    // 6. Build the parameters array
    $parameters = [
        "script"           => "myTask.php",          // Worker script filename
        "file_id"          => $file->getID(),
        "user_details_id"  => $page->getUser()->getID(),
        "task_id"          => $task->getID(),
        "job_id"           => $jobID,                // Must be included
        "my_required_param" => $_POST['my_required_param'],
    ];

    // 7. Save parameters and enqueue
    $job->setParameters($parameters);
    $jobID = $job->saveJob();   // Phase 2: update with full parameters
    $job->putInQueue();

    // 8. Redirect with flash message
    $_SESSION['flash_message'] = (new JobPresentation($job))->getSubmitMessage();
    header("Location: ../article.html?oid=$oid&tab=1");

} catch (Exception $e) {
    $page->handleException($e);
}
?>
```

### Why two saves?

The `job_id` parameter must be included in the parameters so that the worker can re-hydrate the `Job` object from the database. But `job_id` is not known until after the first `INSERT`. The two-phase pattern solves this:

1. **Phase 1** — `saveJob()` inserts the row and returns the auto-generated ID.
2. **Phase 2** — `saveJob()` updates the same row with the complete parameters JSON (which now includes `job_id`).

---

## Step 6 — Write the Job Script

Create `job_queue/myTask.php`. This script is **included** (not called) by `worker.php`, so it shares the worker's variable scope rather than receiving arguments. The following variables are always available:

| Variable | Type | Description |
|---|---|---|
| `$objDB` | PDODatabase | Fresh database connection for this job |
| `$job` | Job | Hydrated Job instance |
| `$params` | array | The parameters array stored in the job record |
| `$logger` | Logger | For writing output lines to the job log |

```php
<?php
use Biblhertz\Publink\om\File;
use Biblhertz\Publink\om\User;
use Biblhertz\Publink\pages\htmlPage;

try {

    // 1. Load domain objects
    $user = new User($objDB, $params['user_details_id']);
    $file = new File($objDB, $params['file_id']);

    // 2. Determine output path
    $outputPath = $user->getMyFileStoreDirectoryPath()
        . DIRECTORY_SEPARATOR . 'output_' . uniqid() . '.json';

    // 3. Perform the work
    $cmd = 'my-tool '
        . escapeshellarg($file->getPath()) . ' '
        . escapeshellarg($outputPath)      . ' 2>&1';

    $output     = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    $logger->print("my-tool :: " . implode("\n", $output));

    if ($returnCode !== 0) {
        throw new Exception("my-tool failed with code $returnCode");
    }

    if (!file_exists($outputPath)) {
        throw new Exception("Output file was not created: $outputPath");
    }

    // 4. Register the output file in the database
    $typeRow = $objDB->preparedSelect(
        "SELECT id FROM file_type WHERE name = ?", ["json"]
    )->fetch();

    $fileId = $objDB->insert("file", [
        'name'            => basename($outputPath),
        'size'            => filesize($outputPath),
        'type'            => 'application/json',
        'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
        'user_details_id' => $params['user_details_id'],
        'path'            => $outputPath,
        'file_type_id'    => $typeRow['id'] ?? null,
    ]);

    // 5. Mark job complete
    $job->setOutputFileID($fileId);
    $job->updateStatus("FINISHED");
    $logger->writeOutUserLogFile("myTask", $user);

} catch (Exception $e) {
    $job->updateStatus("FAILED");
    $logger->print("!!! Exception in myTask :: " . $e->getMessage());
    throw $e;   // Re-throw so worker.php archives the error
}
?>
```

### Job script contract

- **Must** re-throw any exception so `worker.php` can call `archiveError()` and write the failure to `job_log`.
- **Must** call `$job->setOutputFileID(int)` if the task produces a file — this is what other parts of the system query to find the result.
- **Must** call `$job->updateStatus("FINISHED")` on success.
- **Must not** call `$job->archiveJob()` directly — `worker.php` handles archiving after the script returns.

---

## Step 7 — Update the SQL Init File

Add the `task`, `task_file_type`, `intranet_scripts`, and `user_details_task` inserts to:

- `docker/mysql/bibliotheca.sql` (used when building a fresh container)

These rows are **not** applied automatically to a running container. For a running environment, apply the inserts manually via the MySQL CLI or a database client.

---

## Complete Checklist

- [ ] `task` row inserted with correct `action_handler` and `input_type`
- [ ] `task_file_type` rows inserted for every permitted input file type
- [ ] `user_details_task` rows inserted to grant access to the relevant users
- [ ] `intranet_scripts` row inserted for the handler filename (group `1`)
- [ ] `html/handlers/myTask.php` created — validates input, two-phase Job save, `putInQueue()`
- [ ] `job_queue/myTask.php` created — performs work, registers output file, calls `setOutputFileID()` and `updateStatus("FINISHED")`, re-throws on error
- [ ] Both files added to `bibliotheca.sql` init script for fresh container builds

---

## Reference: Key Method Signatures

```php
// Task
$task = new Task(PDODatabase $objDB, int $id);
$task->canExecute(User $user): bool
$task->getID(): int
$task->getName(): string
$task->getCodeName(): string       // snake_case version of name

// Job
$job = new Job(PDODatabase $objDB);
$job->setTask(Task $t): void
$job->setUser(User $u): void
$job->saveJob(): int               // returns job ID
$job->setParameters(array $a): void
$job->putInQueue(): void
$job->updateStatus(string $s): void
$job->setOutputFileID(int $fid): void

// File
$file = new File(PDODatabase $objDB, int $id);
$file->canExecute(int $userId): bool
$file->getID(): int
$file->getPath(): string
$file->getName(): string
```
