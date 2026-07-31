# Publink Database Backend

The database is used to persist information in the system as the PHP framework keeps no state information between individual HTTP requests. There are currently 18 tables in the database. These can broadly be split into three groups of entities:

- **Framework tables** — tables that are linked to the web framework in order to make it work; these tables are not domain specific
- **General tables** — tables that are linked to the operation of publink as a system; these often describe entities in the domain
- **Object Model Tables** — tables that represent the core entities of the system; these tables are domain specific, and include a number of lookup tables that contain id/name pairs

---

## Database Conventions

- Tables are named after singular nouns that represent the entity being modelled (e.g. patients/donors are stored in a table called `patient`)
- Every table has a unique integer primary key in the first column called `id`
- Where appropriate there is a column called `name` containing the name of the individual record
- Foreign keys have the form `<table_name>_id` and point at the primary key of the linked table
- Link tables (for many-to-many relationships) are named `<table1_name>_<table2_name>`
- Lookup tables generally consist of `id` / `name` pairs

---

## Web Framework Tables

Tables in this group are used for managing the web framework. Items recorded here include user information, role information, script execution information, and history. These tables are tightly coupled to classes in the PHP framework.

| Table | Description |
|---|---|
| `intranet_scripts` | Contains the names of files (scripts) that can be processed whilst logged in. Each script has a `user_group_id` associated with it — users with a lower group value than the script's requirement cannot execute it. |
| `user_intranet_log` | For each page loaded whilst logged in, a record is placed here detailing the action. |
| `job` | The system has a job-queuing facility to run resource-intensive scripts asynchronously. A worker process checks this table periodically and pops the first entry when one is present. The record contains information about what is to be run. |
| `job_log` | Records information about jobs that the queuing system has run. |
| `session_variable` | Used by the framework to persist variable values between HTTP requests. Not used in publink. |
| `user_details` | Records information about users of the system and is referenced by many domain-specific tables (usually via a `user_details_id` foreign key). The `user_group_id` field represents the user's role. |
| `user_group` | Lookup table containing the ids and names of the different user roles in the system. |
| `user_session` | Contains information about the current user session. The cookie ID sent from the browser is stored here along with browser details and the user's IP address. Every request uses this table to match the session to a user. Works in close coupling with `User_Session.php`. |

---

## Publink-Specific Tables

Tables in this group relate to the objects being modelled in the domain. The most important is perhaps `serialized_object`. There are a couple of lookup tables with the suffix `_type`.

| Table | Description |
|---|---|
| `file` | Contains details of the files loaded into the system. Entries correspond to files in the file store, which has a particular directory hierarchy described elsewhere. |
| `file_type` | Lookup table containing the list of file types used in the system. |
| `serialized_object` | Contains Articles and Reference Collections uploaded into the system as JATS or BibTeX files. The `object` field contains the base64-encoded serialised object model — when used, it is unserialised and the object model exercised. |
| `task` | Contains task definitions. Tasks are entities that, when instantiated, become jobs and are placed in the job queue. The system is designed to be semi-pluggable so that new tasks can be added with ease. See [adding-a-task.md](adding-a-task.md). |
| `task_file_type` | The file types associated with each task. This information is used when constructing the GUI for each task. |
| `user_details_task` | Records the relationship between users and tasks. An entry here means a user is permitted to execute a particular task. |

---

## Image Annotation Tables

These tables contain information about annotations stored in publink.

| Table | Description |
|---|---|
| `canvas_publication` | Contains information about canvases that have already been published. |
| `image_annotation` | Contains details of image annotations loaded into the system. |
| `user_details_canvas_annotation` | Records the relationship between a canvas and a user when annotations from a canvas have been shared with someone else. |

---

Tables are accessed by the PHP classes that model the entities in the system; these classes contain the SQL queries.
