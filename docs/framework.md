# PHP Framework

Underlying all of the actions on the site is a PHP framework which consists of a set of PHP classes that enable the system to render content and to restrict content by user and user type. The classes are tightly coupled to the database tables catalogued in the section Web Framework Tables. The classes are invoked by the scripts which exercise them which in turn are visible to the web server. The archive is designed to be utilised in a docker environment and when docker compose is run, four docker containers will start (mysql, php, nginx web front and a beanstalk job queue).

---

## Composer and Packages

The git repo contains the files and resources required to build and install Publink on any docker enabled machine. When the docker compose file is run, in the php container build file a php composer file is run which in turn builds references to the packages containing the classes. The packages in the Publink system are named as follows:

| Package | Description |
|---|---|
| `Biblhertz\Publink\annotation` | Classes that implement the annotation server and annotation tools |
| `Biblhertz\Article\om` | Classes that implement the object model for an article |
| `Biblhertz\Article\om\presentation` | Presentation classes to render the article object as HTML |
| `Biblhertz\Article\adapters` | Adapters that transform an article from one representation to another |
| `Biblhertz\Article\reference_api_adapters` | Adapters that implement reference searches by querying external APIs |
| `Biblhertz\Publink\components` | Bootstrap HTML components for rendering HTML |
| `Biblhertz\Publink\om` | Object model classes for housekeeping components of the system |
| `Biblhertz\Publink\om\presentation` | Presentation classes to render the objects as HTML |
| `Biblhertz\Publink\pages` | Page template classes for the web interface |
| `Biblhertz\Publink\utilities` | Utility classes for the system |
| `Biblhertz\Publink` | Contains `Config.php` class with system settings |

---

## System Architecture

The docker setup will install 4 docker containers containing the following components:

- A standard MySQL relational database backend
- A PHP container that computes the pages served
- An nginx front end that serves the pages
- A Beanstalk job queue for offline processing

---

## class.Config.php

Global settings and parameters for the system are found in this class as well as the database DSN string. This class is referenced by various other classes used in the system. Config reads the values of the global settings from a config file called `config.ini` held in the same directory — global settings variables can be managed from there.

---

## The Page Object Hierarchy

Integral to the framework is the page class hierarchy which consists of a set of PHP objects that represent web page templates. These classes are located in a subdirectory of the lib folder called `pages`. The root class from which all other pages are derived is `class.htmlPage.php`.

- **`class.htmlPage.php`**

  This is the root class of all the page classes and is declared as abstract so it cannot be instantiated. There are a number of static methods in this class that perform utility tasks involved in the construction of HTML pages. This class is a part of the framework used in the website construction and is used in numerous websites as the root page class.

- **`class.Bibliotheca_Page.php`**

  This class extends `htmlPage` and is not declared as abstract although it is never instantiated. This class contains a reference to the database abstraction layer and the database layer is instantiated in the constructor. It is intended that subclasses to `Bibliotheca_Page` should use the `parent::__construct()` command to ensure that the superclass constructor is run. The handle to the database abstraction layer is available through a public method from this class. The reason why this class is not declared as abstract is so that the database handle can be visible to scripts (and other resources) that do not require a web page instantiation.

- **`class.Bibliotheca_Intranet_Page`**

  This class extends `Bibliotheca_Page` and is used to ensure that a user is logged into a valid session when the page is instantiated. This feature guards the restricted content of the system. There is also an abstract method called `getPage()` that must be overridden in any subclasses of this class.

- **`class.Bibliotheca_Content_Page.php`**

  This class extends `Bibliotheca_Intranet_Page` and contains all of the page templating details for the implementation of the web site. This includes the main template, modal dialog messages that may appear, ajax search boxes, side menus and so on. The page is rendered by calling the `getPage()` method in this class, which in turn calls `getMenu()` and some other methods.

---

## User Session Management

Users log into the system using the authentication mechanisms mentioned earlier. Once authenticated a session is started by adding a record to the database table `user_session` containing the user's cookie id, the user IP address and the user browser description. This record works hand in hand with the PHP user_session class that handles the interface to the session. The record stays in the table for the duration of the user's session and is deleted when they log out. If the record is left hanging (i.e. the logout function was not used and the browser closed), the record will be deleted by a housekeeping operation in the user session class constructor after a certain time delay.

Every page request that the user makes for a restricted page is checked against the `user_session` table and each of the cookie id, IP address and browser description is checked to be the same for each request (to prevent session hijacking). Each user has a `user_group_id`; these ids correspond to the roles defined in the system. Scripts that each user role can execute are identified in a table called `intranet_scripts` within the database. The constructor of `Bibliotheca_Intranet_Page` checks that the current user has the right to execute the current script; if not, execution of the current script is terminated.

---

## Using Page Templates

The HTML pages displayed by the system are rendered from individual scripts that refer to a library of PHP objects. Every one of these scripts will contain a reference to a page object and instantiation of this object is usually the first command in any script. Every page that is created and rendered by the system inherits properties from the abstract class `htmlPage`.

Along with a number of properties common to a web page, `htmlPage` contains many static methods that can be used to draw together the components to build a typical web page. The class `Bibliotheca_Page` indirectly inherits from `htmlPage` and contains a link to a database abstraction layer (`utilities/PDODatabase.php`). All references to the underlying database are made through this layer. `Bibliotheca_Intranet_Page` inherits from `Bibliotheca_Page` and contains code to manage the session management function. `Bibliotheca_Content_Page` inherits from `Bibliotheca_Intranet_Page` and contains the HTML page template. There is also a package containing classes to construct Bootstrap web page components in `/src/components`.

Scripts that exercise this library of classes are exposed on the web server and rendered to the user as webpages. A script must be registered in the database to be rendered by the system in the table `intranet_scripts`. This table contains an `id` (int), the script's name, and the `user_group_id` (int) which limits execution to users in user groups equal to or higher than this value.

For instance, a typical intranet page could be exposed on the web server in a file called `userList.html` with the following structure:

```php
<?php

require 'vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\presentation\UserPresentation;

$page = new Bibliotheca_Content_Page();

try {

    $content = "";

    $users = $page->getObjDB()->preparedStatement(
        "select user_details.id, user_details.name as username,
                first_name, last_name, email,
                user_group.name as user_group
         from user_details, user_group
         where user_group_id = user_group.id
         order by last_name",
        array()
    );

    $content .= UserPresentation::getUserListAsTable($users, $page->getObjDB());

    $page->setHeading("Publink Users");

} catch (Exception $e) {
    $page->handleException($e);
}

$page->setCentralContent($content);
echo $page->getPage();

?>
```

This script simply lists the users of the system in an HTML table for administrators. The class `Bibliotheca_Content_Page` is instantiated in the `$page` variable, which gives the script access to the database handle. An SQL query is then executed as a prepared statement on the database. The result set from the query is passed to a presentation class method which returns the raw HTML for the table. The page heading is set and finally the generated content is put into the correct slot and the page is rendered by echoing `$page->getPage()`.

This script is only available to sys admins and is therefore marked in `intranet_scripts` with `user_group_id = 20` (the sys admin group id). If a user with a lower group ID tries to render the script they will be blocked. Code to implement this grading of content is in the `Bibliotheca_Intranet_Page` class constructor.

---

## Object Models

There are two object models in the system that represent different parts of the system. The first is contained in `/src/om` (package `Biblhertz\Publink\om`) and contains housekeeping classes for the system. The second object model is in `/src/article/src/om` (package `Biblhertz\Article\om`) and contains the classes used in modelling an Article.

### Package `Biblhertz\Publink\om`

The objects modelled in this package are generally encoded in the database schema and they hold system information. Instantiated objects in the model often have a one-to-one correspondence to the relevant table and record in the database. When a class in the object model is instantiated it is usually provided with an id and its properties are populated from the appropriate record in the database.

#### `BHObject.php`

`BHObject` is an abstract class that is the root class of all objects in the object model. It contains the following global properties that all objects in the model have:

- `id` — the integer key of the object, corresponding to the integer key in the database (`field id`)
- `name` — almost all objects in the model have a name field in the corresponding database table
- `objDB` — a handle to the database abstraction layer
- `tableName` — the name of the database table that this object represents

The class contains public access getters and setters for these properties. As each object in the object model contains a reference to the database, operations that require database access for a particular object are represented as methods within the appropriate object class.

The class also contains the definitions for three abstract data security methods designed to be overridden in subclasses. All three return a boolean:

```php
// Who can edit this object — overridden in subclasses.
// $id is the user id: can the user with this id perform the operation?

public abstract function canEdit(int $id): bool;
public abstract function canDelete(int $id): bool;
public abstract function canView(int $id): bool;
```

The method `canEdit` contains the rules defining who can edit the record. `canDelete` defines who can delete the record and `canView` defines who can view the record. The parameter `$id` is the identifier of the user who would like to perform the operation.

The class also contains `getAsTable()` which is designed to be overridden in subclasses. This method renders an HTML table representation of the attribute/value pairs in any object in the model (where implemented).

Another important method is `fetchItem()`:

```php
/**
 * Fetch item from database and table based on instance variables set.
 *
 * @return mixed the array of the fetched item or null
 */
public function fetchItem(): mixed {
    if (!is_numeric($this->id)) return null;

    $sql  = "select * from " . $this->tableName . " where id = ?";
    $item = $this->objDB->preparedStatement($sql, array($this->id));

    if ($item->rowCount() == 0 || $item->rowCount() > 1) return null;

    return $item->fetch();
}
```

This generic method fetches the database record modelled by the subclass of `BHObject`. The attributes `id` and `tableName` are required to perform this operation. It is called from the constructor of the superclass.

#### Classes in `Biblhertz\Publink\om`

| Class | Description |
|---|---|
| `BHObject.php` | The superclass of all other classes in the object model |
| `File.php` | Represents a file — when a file is uploaded into the system this class represents it and its various properties |
| `FileType.php` | A lookup table containing the file types that can be uploaded to the system |
| `Job.php` | When a job is put into the job queue this class is instantiated and represents that job |
| `JobQueue.php` | Represents the actual job queue in the system |
| `SerializedObject.php` | When an Article (JATS) or a Reference Collection (BibTeX) is uploaded, a record is made in this table. The `object` field contains the base64-encoded version of the object being represented. When decoded it contains an instantiated object model of type `Biblhertz\Article\om` |
| `Task.php` | Represents a task in the system for use with the job queueing mechanism. When a task is exercised it becomes a job and is put into the job queue |
| `User.php` | Represents a user of the system |

### Package `Biblhertz\Article\om`

This package contains the object model that represents an Article (and also a Collection of References). When a file of either the JATS or BibTeX type is uploaded to the system it is stored as an object model in the `serialized_object` table. When it is used by the system the object is decoded from base64 format and unserialized, returning an object of either `Article` or `ReferenceCollection`. These items then form the root of the object model.

The superclass for all items in this package is `ArticleObject.php`. This class contains only two properties — `jatsID` (a unique identifier for the object) and an array called `disallowedFields` (a list of fields that cannot be edited by the GUI).

#### Classes in `Biblhertz\Article\om`

| Class | Description |
|---|---|
| `AAbstract.php` | Contains the abstract of an article — the abstract itself is a collection of Paragraph objects |
| `Affiliation.php` | Affiliation for a Person or Author |
| `Article.php` | Article class that contains information about an Article |
| `ArticleObject.php` | The superclass of all other classes in this package |
| `Author.php` | Author of an Article |
| `Book.php` | Reference class representing a book |
| `Chapter.php` | Reference class representing a chapter |
| `ConferencePaper.php` | Reference class representing a Conference Paper |
| `GalleyFile.php` | Class representing a Galley File |
| `JournalArticle.php` | Reference class representing a Journal Article |
| `Keyword.php` | Keyword class |
| `Manuscript.php` | Reference class representing a Manuscript |
| `Paragraph.php` | Paragraph class |
| `Person.php` | Class representing a Person |
| `Reference.php` | Abstract superclass of all Reference classes |
| `ReferenceCollection.php` | A collection of References |
| `Thesis.php` | Reference class representing a Thesis |
| `WebPage.php` | Reference class representing a Web Page |

The class `Reference` is abstract and represents the superclass of all reference types. A collection of references is held in the class `ReferenceCollection`.

In general, the Article model is an intermediate representation for an Article. The Article object model is exercised by adapters that can transform other representations to and from the object model.

### Package `Biblhertz\Article\om\presentation`

This package contains presentation classes for the objects in the Article om package. These generally render HTML code from a particular object for presentation in the GUI.

### Adapters — Package `Biblhertz\Article\Adapters`

The classes in this package either transform a representation into the Object Model or transform from the Object Model into another representation. The presence of numerous adapters means that the object model acts as an intermediate representation. Other adapters can be added to allow transformations from other representations.

| Class | Description |
|---|---|
| `BibtexToReferenceCollectionAdapter.php` | BibTeX → ReferenceCollection |
| `CSVToOMAdapter.php` | CSV → OM |
| `JatsToOMAdapter.php` | JATS → OM |
| `JatsXMLValidator.php` | Validates JATS XML input |
| `OMToCSVAdapter.php` | OM → CSV |
| `OMToDataCiteAdapter.php` | OM → DataCite |
| `OMToJATSArticleAdapter.php` | OM → JATS |
| `OMToJATSXMLArticleAdapter.php` | OM → JATS XML |
| `OMToOJSArticleAdapter.php` | OM → OJS |
| `OMToOJSNativeAdapter.php` | OM → OJS Native |

---

## Job Queue

The job queue mechanism is implemented using a Beanstalk job queue that runs in a separate container in the Docker architecture. Jobs that the architecture can perform are defined in the `task` table and instantiated as `Task` objects. A user can be given access to a task by inserting a record in the `task_user_details` table containing the key of the task and the key of the user. The `task_user_details` table is the link table in a many-to-many relationship between tasks and users.

Tasks are also defined by their inputs and outputs — the current inputs allowed for a task are held in an enum in the field `input_type`. These are:

- `Single File`
- `Multiple File`
- `Object`
- `Single File or Single Object`
- `Single File and Single Object`

File types that can be input to a task are limited by being defined in the `task_file_type` table that links the `task` table to the `file_type` table. The task table also contains a reference to a handler that is invoked when the task is to be executed. This in turn puts a record in the `job` table which is being monitored by the Beanstalk queue handler.

A script in the directory `/job_queue` called `worker.php` runs monitoring the job database table. When a job is put into the table this script will pick it up and run it.

### Adding a New Task

To insert a new task into the system three things need to be done:

**1. Insert a record into the `task` table**

The relevant fields in the database table are:

- `name` — name of the task
- `description` — a short description of the task
- `action_handler` — path to the handler code for this task
- `action_text` — text on the start button
- `input_type` — one of the enum values listed above
- `public` — a boolean flag; `1` means displayed on the interface, `0` not displayed

Input file types also need to be defined by putting records into the `task_file_type` linking table which links the id of the task to the id of the permitted file types.

**2. Write a handler**

When the button in the GUI component is pressed the handler is called. A handler can look like this:

```php
// -----------------------------------------------------------------------
// Validate task and check user authorisation
// -----------------------------------------------------------------------

if (!isset($_POST['task_id']) || !is_numeric($_POST['task_id'])) {
    throw new Exception("Task ID not defined in handler");
}

$task = new Task($page->getObjDB(), $_POST['task_id']);

if (!$task->canExecute($page->getUser())) {
    throw new Exception(
        "User :: " . $page->getUser()->getName() . " does not have the right to execute this task"
    );
}

// -----------------------------------------------------------------------
// Resolve file ID and check per-file authorisation
// -----------------------------------------------------------------------

$fileId = $_POST[$task->getCodeName()];

if (!isset($fileId) || !is_numeric($fileId)) {
    throw new Exception("File ID not defined in handler");
}

$file = new File($page->getObjDB(), $fileId);

if (!$file->canExecute($page->getUser()->getID())) {
    throw new Exception(
        "User :: " . $page->getUser()->getName()
        . " does not have the right to execute this task on the file selected :: "
        . $file->getName() . " with ID " . $file->getID()
    );
}

// -----------------------------------------------------------------------
// Create and enqueue the job (two-phase save)
// -----------------------------------------------------------------------

$job = new Job($page->getObjDB());
$job->setTask($task);
$job->setUser($page->getUser());

// Phase 1: save to obtain the job ID
$jobID = $job->saveJob();

// Build parameters including the job ID for the worker script
$parameters = array(
    "script"          => "pdfImageExtract.php",
    "file_id"         => $file->getID(),
    "user_details_id" => $page->getUser()->getID(),
    "task_id"         => $task->getID(),
    "job_id"          => $jobID,
    "output_type"     => "all",
);

// Phase 2: save again with the complete parameter set
$job->setParameters($parameters);
$jobID = $job->saveJob();
$job->putInQueue();

// -----------------------------------------------------------------------
// Redirect to the tasks page with a confirmation message
// -----------------------------------------------------------------------

$_SESSION['flash_message'] = (new JobPresentation($job))->getSubmitMessage();
header("Location: ../myTasks.html?task_id=" . $task->getID());
```

The code carries out checks on the parameters sent to it, builds the parameters, and then puts the job in the job queue.

**3. Write the job script**

The relevant parameters are stored in the `$params` array and the job is performed by the queue monitor. The queue monitor includes the script specified in the `params` block into its process so that it can be carried out. The following variables are passed through to the script by the worker:

- `$logger` — the log for the job
- `$objDB` — the database handle
- `$job` — the Job instance
- `$params` — the parameters array

```php
$file = new File($objDB, $params['file_id']);
$path = $file->getPath();
$user = new User($objDB, $params['user_details_id']);

// Create a unique temporary working directory in the user's file store.
$myPath = $user->getMyFileStoreDirectory() . uniqid() . DIRECTORY_SEPARATOR;
mkdir($myPath);

// Determine the pdfimages output format flag.
$extractFlag = "-all";
if (isset($params['output_type']) && !strcmp($params['output_type'], "jp2")) {
    $extractFlag = "-jp2";
}

// Build and run the pdfimages command.
$cmd = "pdfimages $extractFlag $path $myPath";
$job->updateStatus($cmd);
$out = system($cmd);

// Enumerate all files written to the temporary directory by pdfimages.
$files = File::getFileListFromDirectory($myPath);
$pathParts = pathinfo($path);

foreach ($files as $file) {
    $tmpPath = $myPath . DIRECTORY_SEPARATOR . $file;
    $newPath = $user->getUniqueFileName(
        $user->getMyFileStoreDirectory() . DIRECTORY_SEPARATOR . $pathParts['filename'] . $file
    );
    rename($tmpPath, $newPath);

    $vals = [
        'name'            => basename($newPath),
        'size'            => filesize($newPath),
        'type'            => filetype($newPath),
        'timestamp'       => htmlPage::getNowAsSQLTimeStamp(),
        'user_details_id' => $params['user_details_id'],
        'path'            => $newPath,
    ];

    $typeResult = $objDB->preparedSelect(
        "SELECT id, type FROM file_type WHERE name = ?",
        [File::getFileExtensionFromBaseName($vals['name'])]
    );
    $fileType = $typeResult->fetch();

    if ($fileType) {
        $vals['file_type_id'] = $fileType['id'];
        if (!strcmp($fileType['type'], "image")) {
            $vals['thumbnail_path'] = File::makeThumbNailImage($objDB, $newPath);
        }
    }

    $id = $objDB->insert("file", $vals);
    $job->updateStatus("Processed: " . $newPath);
}

// Remove the now-empty temporary working directory.
$removed = File::deleteDirectory($myPath);
if (!$removed) {
    throw new Exception("There was a problem removing the temporary directory $myPath");
}

$job->updateStatus("FINISHED");
```

This script should be stored in the `./job_queue` directory of the system. Much of the script is concerned with putting the input and output files into the correct directories to execute the job.

---

## Annotation Server and Annotation Tools

A separate part of the system operates a limited API that acts as an annotation server integrated with the Mirador client. A customised version of the Mirador client is shipped with Publink that interacts with the Publink annotation server. The customised Mirador client is served from port 3000 of the nginx front-end. This address is set in `Config.php` and is derived from the root address of the server.

The client is started from within the Publink interface and on initialisation the client sends the HTTP session key to the client so that it can identify the user in the API when incoming messages are received. The client is also sent the API address of the instance of Publink that started it, sent as parameters to the Mirador client.

There are three API calls: 1) add a new annotation, 2) delete an existing annotation, and 3) update an existing annotation. The updated file in the Mirador source is called `AnnototAdapter.js` — the methods in this script have been changed to send messages that the Publink API can consume.

There are several pages in the interface that allow annotations stored in Publink to be viewed in an external viewer and shared with other users. Individual annotations can be deleted either from within the Publink interface or on the Mirador client. In both cases they are first deleted in the annotation server in Publink and when the client updates its view, they are therefore no longer visible in the client.

---

## Simple Manifest Server

Publink is also integrated with a very simple manifest server that allows IIIF annotations to be published and viewed online. This is stored on git in a project called Simple Manifest Server in the biblhertz archive (`biblhertz\simple_manifest_server`). Documentation for that system is held in the GitHub archive.

There is a true/false switch in `config.ini` that can be used to turn the integration on and off in the variable called `publication` (which sets the config variable `Config::$PUBLICATION` inside Publink).

---

## Job Queue Mechanism

Publink contains a job queue mechanism that enables tasks to be inserted into the system and carried out offline if necessary. The idea of this feature is so that small tasks and file transformations can be added to the system with ease and kept within the Publink umbrella. With the advent of AI, many tasks can be written quickly and then added to the system.

The job queue runs in the Beanstalk container that monitors a database table called `job_queue` that maintains the job queue. The PHP container contains a version of Python so Python tasks can be integrated fairly easily.

The individual jobs in the job queue are constructed from tasks that are defined in two database tables: `task` and `task_file_type`.

> See [adding-a-task.md](adding-a-task.md) for a full step-by-step guide to adding a new task to the system.
