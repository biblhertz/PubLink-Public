# Publink

Publink is a web-based toolkit for digital publishing workflows, developed with support from the Deutsche Forschungsgemeinschaft (Bonn, DE) — Grant No. 501142032 ([view grant](https://app.dimensions.ai/details/grant/grant.12927414)).

Designed to address bottlenecks in the publishing pipeline at the Bibliotheca Hertziana, Publink is also suitable for broader digital publishing use cases.

---

## Features

- Import articles from JATS format
- Upload and download multiple file formats
- Edit article metadata
- Attach galley files to articles
- Export articles in OJS format for upload to an OJS server
- Export article data back to JATS format
- Render articles as HTML via TEI Publisher (JATS XML → styled HTML)
- Reference resolution and augmentation via CrossRef, Alma, and Google Books
- Import/export references in BibTeX format
- Export article reference data to DataCite
- IIIF annotation store and server
- Integration with the Mirador annotation tool (custom build included)
- Job queue infrastructure for offline task processing

---

## Technology Stack

Publink is built on **MySQL / PHP / HTML / JavaScript** and backed by a relational database that persists user data and content.

---

## Architecture

### Components

| Component | Description |
|---|---|
| MySQL database | Persists all user and article data |
| PHP object model | Classes representing system entities (articles, references, pages, etc.) |
| Page classes | Inherit from `class.htmlPage.php`; render HTML responses server-side |
| Utility classes | Includes `Database.php` for database abstraction |
| PHP scripts | Entry points accessible to users |

### Source Packages

PHP classes are organized into six packages under `/src` (Composer package: `biblhertz/publink`; PHP namespaces `Biblhertz\Publink` and `Biblhertz\Article`):

| Package | Description |
|---|---|
| `annotation` | Classes for image annotation |
| `article` | Object model for articles |
| `components` | Reusable web page components |
| `om` | General site object model |
| `pages` | Page templates |
| `utilities` | Utility and helper classes |

### Request Lifecycle

Each page request is treated as a discrete, stateless event:

1. The client sends a request to the server
2. A page object is instantiated
3. The entity object model is exercised to build page components
4. HTML/CSS/JavaScript is generated via the page object's `getPage()` method
5. The server returns the HTML to the client

---

## Docker Setup

Publink runs in a four-container Docker environment:

| Container | Role |
|---|---|
| `web` | Front-end web server (nginx) |
| `php` | PHP processing engine (php-fpm) |
| `mysql` | Relational database |
| `beanstalkd` | Job queue |
| `tei-publisher` | JATS XML → HTML rendering via eXist-db/TEI Publisher (optional) |

Configuration files are located in:
- `/docker/nginx` — nginx config
- `/docker/php` — PHP config
- `/docker/mysql` — MySQL config
- `/docker` — Dockerfiles (`nginx.Dockerfile`, `php.Dockerfile`, `mysql.Dockerfile`) and `docker-compose.yml`

---

## Installation

For full installation instructions — including standard setup, Windows, rootless Docker, configuration reference, and troubleshooting — see the **[Installation Guide](docs/installation.md)**.

---

## Configuration

The file `/src/config.ini` contains all global configuration settings. These are loaded at page instantiation as static variables in the `Config` class. Each variable is documented with an explanation inside the config file.

---

## Mirador Integration

A custom build of the [Mirador](https://projectmirador.org/) image viewer is bundled with Publink for annotation tasks. It is compiled and injected into the nginx container when the init script runs.

---

## Unit Tests

After installation, run the test suite inside the PHP container:

```bash
docker exec -it php bash
/var/www/vendor/bin/phpunit
```

---

## Maintenance

Uploaded and generated files are persisted in the bind mount `./local-data` (host path relative to `docker/`) and survive container restarts.

### Maintenance Scripts

Run these from the `/publink/docker` directory:

| Script | Description |
|---|---|
| `dbbackup.sh` | Saves a timestamped database dump to `./db_backup/` and copies it to `./mysql/bibliotheca.sql` so it is loaded on the next container start |
| `stop.sh` | Backs up the database and stops all containers. Volumes and images are preserved. |
| `start.sh` | Tears down all containers and images, rebuilds from scratch, and starts the stack. |
| `restart.sh` | Backs up the database, tears down all containers and images, rebuilds, and starts the stack. |

### Upgrading

When no database schema changes are involved:

1. Back up the document archive: `/publink/docker/local-data`
2. Save the latest database: `./stop.sh`
3. Copy the database dump from `/publink/docker/db_backup` to a safe location
4. Once everything is safely backed up, delete the `publink` directory
5. Clone the new version from GitHub
6. Copy the saved database to `/publink/docker/mysql/bibliotheca.sql`
7. Copy the saved file archive back to `/publink/docker/local-data`
8. Make the init script executable and run it: `chmod +x init.sh && ./init.sh`

---

## Documentation

Detailed documentation is available in the `/documentation` directory:

- `database_tables.docx` — relational database schema
- `framework.docx` — system architecture and operation

---

## Credits

Originally developed by Chris Tomlinson ([@hawkenbury](https://github.com/hawkenbury)).

---

## License

GPL-3.0-or-later — see [LICENSE](LICENSE) / [docs/COPYING](docs/COPYING). This project incorporates GPL-3.0 schema files (`docker/publink/xsd/ojs/`) from the Open Journal Systems (PKP) project, copyright Simon Fraser University and John Willinsky.
