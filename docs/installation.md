# Publink — Installation Guide

Publink is a web-based toolkit for digital publishing workflows. It runs in a four-container Docker environment and is installed via an interactive setup script that generates all configuration and starts the containers automatically.

---

## Table of Contents

1. [System Requirements](#1-system-requirements)
2. [Architecture Overview](#2-architecture-overview)
3. [Pre-installation Checklist](#3-pre-installation-checklist)
4. [Standard Installation (Linux / macOS)](#4-standard-installation-linux--macos)
5. [Windows Installation](#5-windows-installation)
6. [Rootless Docker Installation (Linux)](#6-rootless-docker-installation-linux)
7. [Configuration Reference](#7-configuration-reference)
8. [Integration Setup](#8-integration-setup)
9. [Post-installation Verification](#9-post-installation-verification)
10. [Maintenance](#10-maintenance)
11. [Upgrading](#11-upgrading)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. System Requirements

### Software

| Requirement | Minimum version | Notes |
|---|---|---|
| Docker Engine | 24.x | Must include Compose v2 (`docker compose`) |
| Docker Compose | v2 (plugin) | Invoked as `docker compose`, not `docker-compose` |
| `openssl` | Any | Used by `init.sh` to generate secrets |
| Node.js | 14.x | Used at **build time only** inside the Docker image; not needed on the host |

### Hardware (recommended)

| Resource | Minimum | Notes |
|---|---|---|
| CPU cores | 2 | InnoDB benefits from multiple cores |
| RAM | 4 GB | MySQL InnoDB buffer pool is sized automatically |
| Disk | 20 GB free | For the database, file store, and Docker image layers |

### Network

- Ports **80**, **443**, and **3000** must be free on the host.
- Port **3000** is used by the bundled Mirador annotation viewer.
- Port **3306** is also exposed for direct database access (can be blocked at the firewall in production).
- Port **8080** is used by the optional TEI Publisher container. Only required if TEI Publisher is run as a Docker service.

---

## 2. Architecture Overview

Publink runs as four core Docker containers on a shared bridge network called `publink-network`, with an optional fifth container for TEI Publisher.

| Container name | Image | Role | Optional |
|---|---|---|---|
| `web` | nginx (stable-alpine) | Front-end web server; serves PHP pages via FastCGI and static Mirador assets | No |
| `php` | php:8.3-fpm | PHP-FPM processing engine; runs the application code | No |
| `mysql` | mysql:8.0 | Relational database (`bibliotheca`) | No |
| `beanstalkd` | custom | Beanstalk job queue for offline task processing | No |
| `tei-publisher` | existdb/teipublisher | eXist-db with TEI Publisher for JATS XML → HTML rendering | Yes |

The `tei-publisher` container is started only when TEI Publisher is enabled as a Docker service (see [TEI Publisher](#tei-publisher) in Integration Setup). It is gated behind a Docker Compose profile and does not affect the other containers when not in use.

### How requests flow

```
Browser → nginx (port 80/443) → php-fpm (port 9000) → MySQL (port 3306)
Browser → nginx (port 3000)  → Mirador static files
php-fpm → tei-publisher (port 8080)  [optional, when TEI integration enabled]
```

### Persistent data

| Path on host | Mounted into container | Purpose |
|---|---|---|
| `./local-data` | `/var/www/file_store` | Uploaded files and generated documents |
| MySQL container filesystem | `/var/lib/mysql` | Database — stored in the container's writable layer; lost on rebuild. `dbbackup.sh` dumps and seeds `mysql/bibliotheca.sql` so data survives a full container rebuild. |

### Configuration flow

The init script collects settings interactively and writes two files:

- **`.env`** — consumed by Docker Compose; environment variables are injected into containers at runtime.
- **`init.cfg`** — saves your answers so defaults are pre-filled on the next run of the init script.

Inside the `php` container, `php/entrypoint.sh` reads the environment variables and generates `config.ini` at startup. You never need to edit `config.ini` by hand.

> **Security note:** `docker/init.cfg` and `docker/.env` are where your real credentials live once you've run the init script for a genuine install (site URL, `ORCID_CLIENT_SECRET`, `PRIMO_API_KEY`, `GOOGLE_BOOKS_API_KEY`, the generated `DB_PASSWORD`, etc.). Both are excluded in `.gitignore`, but **git only respects that going forward — it won't undo a commit that already happened.** If you fork or copy this project for your own deployment, never run `git add`/`git commit` from inside a live, configured install without checking `git status` first. The same applies to `docker/mysql/bibliotheca.sql`: it ships here as schema-only (no real rows) so it can seed a fresh database, but `dbbackup.sh` overwrites that same path with a real production dump so it reloads on the next container start. If that dump is ever staged and committed, treat every credential and user record in it as compromised — rotate everything, and get a fresh sanitized history rather than trying to edit the leak out of an existing commit.

---

## 3. Pre-installation Checklist

Before running the init script, confirm each item:

- [ ] Docker Engine is installed and the daemon is running (`docker ps` returns no error).
- [ ] `docker compose version` (v2 plugin) works.
- [ ] Ports 80, 443, and 3000 are free on the host.
- [ ] On Linux: IPv4 forwarding is enabled (required for Docker bridge networking).
- [ ] You have a valid admin email address and a password ready for the first admin account.
- [ ] If using ORCID authentication: the site must be reachable at a public HTTPS address (ORCID does not work on `http://localhost`).
- [ ] If enabling Simple Manifest Server integration: the manifest server is already installed and running, and you have its URI. (No API key is configured at install time — see [Simple Manifest Server](#simple-manifest-server) below.)

### Verify IPv4 forwarding (Linux only)

```bash
cat /proc/sys/net/ipv4/ip_forward
```

If the output is `0`, enable it:

```bash
sudo sysctl -w net.ipv4.ip_forward=1
echo "net.ipv4.ip_forward=1" | sudo tee /etc/sysctl.d/99-ip-forward.conf
sudo systemctl restart docker
```

---

## 4. Standard Installation (Linux / macOS)

### Step 1 — Clone the repository

```bash
git clone <repository-url> publink
cd publink/docker
```

### Step 2 — Make scripts executable

```bash
chmod +x *.sh
```

### Step 3 — Run the init script

```bash
./init.sh
```

The script is fully interactive. It will:

1. Prompt for the site root URL and Mirador client URL.
2. Generate a random database password and encryption key automatically.
3. Walk through each optional integration (CrossRef, Google Books, ORCID, Primo, Simple Manifest Server, annotation publication, TEI Publisher).
4. Prompt for the first admin user's email and password.
5. Save answers to `init.cfg` for future runs.
6. Write `.env` for Docker Compose.
7. Tear down any existing containers, rebuild all images, and start the stack.
8. Wait for MySQL to become ready, then seed the admin user.

The full process typically takes 5–15 minutes on the first run (Docker image builds including the Mirador npm build are the slow part).

### Step 4 — Confirm installation

```bash
docker ps
```

You should see four containers running: `web`, `php`, `mysql`, `beanstalkd`. If TEI Publisher was enabled as a Docker service, `tei-publisher` will also be listed.

Open `http://localhost` (or your configured site root) in a browser and log in with the admin credentials you provided.

---

## 5. Windows Installation

On Windows, run the `.bat` version of the init script from a Command Prompt or PowerShell window. Docker Desktop must be installed and running.

### Step 1 — Clone the repository

```cmd
git clone <repository-url> publink
cd publink\docker
```

### Step 2 — Run the init script

```cmd
init.bat
```

The Windows script behaves identically to `init.sh`. Secrets are generated using PowerShell's `System.Security.Cryptography.RandomNumberGenerator` instead of `openssl`, but the output format and security properties are equivalent.

### Notes for Windows

- The script uses `timeout /t` instead of `sleep` for the MySQL wait loop.
- Passwords are prompted via PowerShell's `Read-Host -AsSecureString` so they are masked in the terminal.
- Docker Desktop must have **WSL 2 backend** enabled (recommended) or Hyper-V backend.
- If you are using WSL 2 and need IPv4 forwarding, add the following to `/etc/sysctl.conf` inside your WSL 2 distro (WSL 2 resets kernel parameters on restart):

  ```ini
  net.ipv4.ip_forward = 1
  ```

  Then run `sudo sysctl -p` inside WSL 2.

---

## 6. Rootless Docker Installation (Linux)

Running Docker in rootless mode confines the daemon to a non-root user, reducing the blast radius of a container escape. This is the recommended production configuration on Linux servers.

### Step 1 — Run the rootless setup script (as root)

```bash
cd publink/docker
chmod +x setup-rootless.sh

# Creates a dedicated 'publink' user (default name)
sudo ./setup-rootless.sh

# Or specify an existing or new username
sudo ./setup-rootless.sh myuser
```

This script performs the following operations automatically:

| Step | What it does |
|---|---|
| Install dependencies | `apt-get install uidmap slirp4netns dbus-user-session` |
| Create user | `useradd` if the user does not already exist |
| Subordinate UID/GID mappings | Adds entries to `/etc/subuid` and `/etc/subgid` for user-namespace mapping |
| Unprivileged port binding | Sets `net.ipv4.ip_unprivileged_port_start=80` and persists it to `/etc/sysctl.d/99-publink-rootless.conf` |
| XDG runtime directory | Creates `/run/user/<uid>` with correct ownership |
| Install rootless Docker | Runs `dockerd-rootless-setuptool.sh install` as the target user |
| Enable systemd service | Enables the user-level Docker service and enables lingering |

### Step 2 — Fix ownership of the project directory (as root)

```bash
chown -R publink /path/to/publink
```

Replace `publink` with the username chosen in Step 1.

### Step 3 — Switch to the rootless user and start the daemon

```bash
sudo -u publink -i

# Start the Docker user daemon
systemctl --user start docker

# Verify
docker ps
```

If the system does not have a systemd user session (e.g. some minimal server installs), start the daemon manually:

```bash
dockerd-rootless.sh &> /tmp/dockerd-rootless.log &
disown
```

### Step 4 — Run the init script

```bash
cd /path/to/publink/docker
./init.sh
```

### How rootless mode is detected

All operational scripts (`init.sh`, `start.sh`, `stop.sh`, `restart.sh`) source `docker-env.sh` at startup. That file automatically sets `DOCKER_HOST` to the rootless daemon socket when running as a non-root user:

```bash
# docker-env.sh
if [ "$(id -u)" -ne 0 ]; then
    export DOCKER_HOST="unix:///run/user/$(id -u)/docker.sock"
    export XDG_RUNTIME_DIR="/run/user/$(id -u)"
fi
```

No manual configuration is needed — the correct socket is selected automatically.

---

## 7. Configuration Reference

Configuration is set by answering the init script prompts. Answers are saved to `init.cfg` and reloaded as defaults on the next run. All values flow through `.env` → Docker Compose environment → container entrypoint → `config.ini`.

### Site Settings

| Setting | Default | Description |
|---|---|---|
| `SITE_ROOT` | `http://localhost` | Public root URL of the site (e.g. `https://publink.example.org`). Used to construct absolute URLs throughout the application. Must be `https://` for ORCID authentication to work. |
| `MIRADOR_CLIENT` | `{SITE_ROOT}:3000` | URL of the Mirador annotation viewer. Defaults to the site root on port 3000. Used in nginx CORS headers so Mirador can communicate with the API. |
| `REGISTRATION_ENABLED` | `true` | Allow new users to self-register via the registration form (`register.html`). When `false`, the "Register" link is hidden from the login page and `register.html` redirects to `index.html` even if visited directly. Does not affect ORCID login/registration, which is controlled separately by `ORCID_INTEGRATION`. |

### Auto-generated Secrets

The following values are generated fresh on every run of the init script and are **never saved to `init.cfg`**. They are written only to `.env` and to `mysql/my.cnf`.

| Setting | Length | Description |
|---|---|---|
| `DB_PASSWORD` | 16 bytes | MySQL password for the `bibliotheca_user` account and the MySQL root account. Also written to `mysql/my.cnf` so it is embedded in the MySQL container image at build time. `dbbackup.sh` reads the password from `.env` at runtime. |
| `ENCRYPT_KEY` | 32 bytes | AES encryption key used to encrypt sensitive values stored in the database. |

> **Important:** After each run of the init script, the database password changes. The `mysql/my.cnf` file is updated automatically, so `dbbackup.sh` continues to work without any manual intervention.

### Integration Flags

All integrations default to disabled unless you explicitly enable them during setup.

| Setting | Default | Description |
|---|---|---|
| `CROSSREF_INTEGRATION` | `true` | Enable reference lookup via the CrossRef REST API. |
| `CROSSREF_EMAIL` | *(empty)* | Contact email sent in the CrossRef `User-Agent` header to qualify for the polite pool (faster rate limits). Optional but recommended. |
| `GOOGLE_BOOKS_INTEGRATION` | `false` | Enable book metadata lookup via the Google Books API. |
| `GOOGLE_BOOKS_API_KEY` | *(empty)* | Google Books API key. Leave blank for unauthenticated access (lower rate limits). |
| `MANIFEST_SERVER_INTEGRATION` | `false` | Enable integration with a Simple Manifest Server instance. |
| `MANIFEST_SERVER_URI` | *(empty)* | Base URI of the Simple Manifest Server (e.g. `https://manifests.example.org`). |
| `ORCID_INTEGRATION` | `false` | Enable ORCID OAuth login. Not available when `SITE_ROOT` is `http://localhost`. |
| `ORCID_CLIENT_ID` | *(empty)* | ORCID member client ID (issued by ORCID to institutional members). |
| `ORCID_CLIENT_SECRET` | *(empty)* | ORCID member client secret. |
| `ORCID_OATH_ADDRESS` | `https://orcid.org/oauth/token` | ORCID OAuth token endpoint. |
| `ORCID_API_ADDRESS` | `https://api.orcid.org/v3.0/` | ORCID public API endpoint. |
| `PRIMO_INTEGRATION` | `false` | Enable library catalogue search via Ex Libris Primo/Alma. |
| `PRIMO_API_KEY` | *(empty)* | Primo API key from your Ex Libris developer account. |
| `PRIMO_VID` | *(empty)* | Primo View ID (VID) — identifies your institution's catalogue view. |
| `PRIMO_URI` | *(empty)* | Base URI of your Primo instance. |
| `PUBLICATION` | `false` | Enable annotation publication to a Simple Manifest Server. Requires `MANIFEST_SERVER_INTEGRATION=true`. |
| `TEIPUB_INTEGRATION` | `false` | Enable TEI Publisher integration for JATS XML → HTML rendering. |
| `TEIPUB_DOCKER` | `false` | Run TEI Publisher as a Docker service (`existdb/teipublisher`). When `true`, `TEIPUB_URI` is set automatically and the `tei` Docker Compose profile is activated. Not saved to `.env` — used only by the init script. |
| `TEIPUB_URI` | *(empty)* | Base URI of the TEI Publisher instance (e.g. `https://tei.example.org/exist/apps/tei-publisher`). Set automatically to `http://tei-publisher:8080/exist/apps/tei-publisher` when using the Docker service. |

### CORS Configuration

nginx is configured with a `map` directive that allows the following origins to make cross-origin requests to the API:

- `iiif.humanitiesconnect.pub`
- `mirador.humanitiesconnect.pub`
- `annotation.biblhertz.it`
- Any `localhost` address (with any port number)
- The value of `MIRADOR_CLIENT` (substituted at container start via `envsubst`)

If your Mirador instance is hosted at a different domain, update `MIRADOR_CLIENT` to match.

---

## 8. Integration Setup

### CrossRef

CrossRef reference lookup is enabled by default. It uses the CrossRef REST API to augment references with DOIs and metadata. Supplying a contact email (`CROSSREF_EMAIL`) places your requests in the polite pool, which has higher and more reliable rate limits. The email is included only in the `User-Agent` header; no registration is required.

### Google Books

Requires a Google Cloud project with the Books API enabled. Generate an API key in the Google Cloud Console and paste it when prompted. Leave the key blank to use unauthenticated access, which has stricter quotas.

### Simple Manifest Server

The Simple Manifest Server is a companion project (`biblhertz/simple_manifest_server`) that stores and serves IIIF manifests containing published annotations. To configure the integration:

1. Install the Simple Manifest Server separately (see its own installation guide).
2. Supply its URI when prompted by the Publink init script (`MANIFEST_SERVER_URI`).

No API key is configured at install time or stored server-side. Publink does not
hold a shared secret for the manifest server — each person publishing or
removing a manifest (from an article page, an image annotation canvas, or the
standalone IIIF Manifest Generator) must type in their own API key at the time
of the request. Create individual API keys per user in the Simple Manifest
Server admin panel (`/api_keys.html`) with access to the `putManifest` and
`removeManifest` endpoints, and distribute them to whoever needs to publish.

### ORCID

ORCID authentication requires an ORCID member API credential (client ID and client secret), which is issued to institutions by ORCID. The site must be reachable at a public HTTPS address — ORCID will not issue tokens to `http://localhost` redirect URIs.

Contact ORCID (support@orcid.org) to apply for member API access. Once you have credentials:

1. Register your site's callback URL with ORCID.
2. Supply the client ID and client secret when prompted by the init script.
3. The default OAuth and API addresses are correct for ORCID production; only change them if you are using the ORCID sandbox.

### Primo / Alma

Contact your Ex Libris account manager or system administrator to obtain:

- An API key from the Ex Libris Developer Network.
- Your institution's View ID (`VID`).
- The base URI of your Primo installation.

### TEI Publisher

TEI Publisher renders JATS XML articles as styled HTML using the `jats.odd` ODD stylesheet. When enabled, a **Display Article As HTML** link appears on each article page. Clicking it sends the article's JATS XML to TEI Publisher's `/api/preview` endpoint and renders the result in a standalone viewer page.

#### Option A — Docker service (recommended for local/server deployments)

When prompted by the init script, answer **Y** to both *Include TEI Publisher integration* and *Run TEI Publisher as a Docker service*. The init script will:

1. Set `TEIPUB_URI` automatically to `http://tei-publisher:8080/exist/apps/tei-publisher`.
2. Start the `existdb/teipublisher` container alongside the other services.

No further configuration is required. The TEI Publisher container is on the same Docker network as `php-fpm` and is reachable by its container name.

> **First-run note:** On the first transformation request, eXist-db compiles the `jats.odd` stylesheet, which can take up to several minutes. Subsequent requests are fast. The compiled stylesheet is cached inside the container and survives restarts (but not a full `docker compose down -v`).

#### Option B — External instance

If you already have a TEI Publisher instance running elsewhere, answer **Y** to *Include TEI Publisher integration* and **N** to *Run as Docker service*, then supply the base URI of the instance (e.g. `https://tei.example.org/exist/apps/tei-publisher`).

The external instance must:
- Have `jats.odd` installed (included in standard TEI Publisher installations).
- Be reachable from the `php-fpm` container over the network.
- Accept unauthenticated POST requests to `/api/preview` (the default for most installations).

#### Verifying the integration

After installation, navigate to any article and click **Display Article As HTML**. The article should open in a new tab rendered as styled HTML. If you see a cURL error, check:

```bash
docker logs php | tail -20
docker exec php curl -s http://tei-publisher:8080/exist/apps/tei-publisher/api/
```

---

## 9. Post-installation Verification

### Check running containers

```bash
docker ps
```

All four containers (`web`, `php`, `mysql`, `beanstalkd`) should show status `Up`.

### Check nginx configuration

```bash
docker exec web nginx -t
```

Expected output: `nginx: configuration file /etc/nginx/nginx.conf test is successful`.

### Check the generated nginx config

The nginx config is generated from a template at container start. Inspect the result:

```bash
docker exec web cat /etc/nginx/conf.d/default.conf
```

Verify that the `MIRADOR_CLIENT` placeholder has been replaced with your actual value.

### Check PHP is responding

```bash
docker exec php php -r "echo 'PHP OK';"
```

### Check MySQL connectivity

```bash
docker exec php php -r "new PDO('mysql:host=mysql;dbname=bibliotheca', 'bibliotheca_user', getenv('DB_PASSWORD')); echo 'DB OK';"
```

### Run the PHP test suite

```bash
docker exec -it php bash
/var/www/vendor/bin/phpunit
exit
```

### Log in via the browser

Navigate to your site root in a browser. The login page should appear. Log in with the admin credentials supplied during setup.

---

## 10. Maintenance

All maintenance scripts are in `publink/docker/`. Run them from that directory.

### Daily operations

| Script | Command | Description |
|---|---|---|
| Start | `./start.sh` | Tears down all containers and images, rebuilds from scratch, and starts the stack. |
| Stop | `./stop.sh` | Backs up the database, then stops all containers. Volumes and images are preserved. |
| Restart | `./restart.sh` | Backs up the database, tears down all containers and images, rebuilds, and starts the stack. |
| Backup | `./dbbackup.sh` | Dumps the live database to a timestamped file in `./db_backup/` and copies it to `./mysql/bibliotheca.sql` so it is loaded on the next container start. Dumps older than 30 days are deleted automatically. |

### Backup details

`dbbackup.sh` runs `mysqldump` inside the running MySQL container via `docker exec`, reading the current password from `.env`. No host-side MySQL client tools are required.

Backup files are named: `db_backup/publink_YYYYMMDD_HHMMSS.sql`

The copy placed at `mysql/bibliotheca.sql` is loaded automatically by the MySQL container on its next start. This is how database state survives a full container rebuild.

### File store

Uploaded and generated files are stored in `./local-data/`, which is bind-mounted into the PHP container at `/var/www/file_store`. This directory is not affected by `docker compose down` or container rebuilds — it persists on the host.

Back up `./local-data/` with your standard host backup tooling. Keeping a backup of both `./local-data/` and the latest SQL dump gives you a complete restore point.

### Re-running the init script

Running `./init.sh` again is safe and is the correct way to change configuration. Your previous answers are pre-filled as defaults (loaded from `init.cfg`). The script always generates a new database password and encryption key — the database password is updated in `mysql/my.cnf` automatically.

> **Note:** Re-running the init script will **tear down all containers and volumes** and rebuild from scratch. Any data not captured by `./local-data/` or the MySQL dump will be lost. The init script does not back up the database before tearing down — run `./dbbackup.sh` first if you need to preserve current data.

---

## 11. Upgrading

Use this procedure when upgrading to a new version of Publink **without database schema changes**.

If schema changes are involved, consult the release notes for that version before proceeding.

### Step 1 — Back up the current installation

```bash
cd publink/docker

# Back up the database
./dbbackup.sh

# Copy the dump to a safe location outside the project directory
cp mysql/bibliotheca.sql /path/to/safe/location/bibliotheca_$(date +%Y%m%d).sql

# Back up the file store
cp -r local-data /path/to/safe/location/local-data_$(date +%Y%m%d)
```

### Step 2 — Stop the running stack

```bash
./stop.sh
```

This runs `dbbackup.sh` again before stopping, so the dump is current.

### Step 3 — Replace the project with the new version

```bash
cd ..
mv publink publink_old          # keep the old version until verified
git clone <repository-url> publink
```

### Step 4 — Restore data into the new version

```bash
# Database dump
cp /path/to/safe/location/bibliotheca_YYYYMMDD.sql publink/docker/mysql/bibliotheca.sql

# File store
cp -r /path/to/safe/location/local-data_YYYYMMDD publink/docker/local-data
```

### Step 5 — Run the init script

```bash
cd publink/docker
chmod +x *.sh
./init.sh
```

Your previous settings are pre-filled from `init.cfg` if you copied it across. Otherwise re-enter them when prompted.

### Step 6 — Verify and clean up

Verify the site is working, then remove the old version:

```bash
rm -rf publink_old
```

---

## 12. Troubleshooting

### Bad gateway (502) from nginx

This means nginx started successfully but cannot reach php-fpm. Causes and fixes:

**IPv4 forwarding disabled (Linux)**

```bash
cat /proc/sys/net/ipv4/ip_forward
# Expected: 1
# If 0, run:
sudo sysctl -w net.ipv4.ip_forward=1
sudo systemctl restart docker
docker compose up -d
```

**nginx config not generated**

Check whether the entrypoint ran successfully:

```bash
docker logs web
docker exec web cat /etc/nginx/conf.d/default.conf
```

If `default.conf` is missing or shows template placeholders, the `40-envsubst-mirador.sh` entrypoint script did not run. Rebuild the image:

```bash
docker compose up --build -d
```

**nginx config syntax error**

```bash
docker exec web nginx -t
```

If this reports an error, check the generated config for problems.

### MySQL not ready

The init script polls MySQL up to 30 times at 5-second intervals. If MySQL does not become ready:

```bash
docker logs mysql
```

Common causes:
- `DB_PASSWORD` contains special characters that conflict with the `my.cnf` format. Re-run the init script to generate a new password.
- Insufficient disk space for the InnoDB data files.

### Containers start but site shows an error page

Check the PHP error log:

```bash
docker logs php
```

Check the nginx error log:

```bash
docker exec web cat /var/log/nginx/error.log
```

### IPv4 forwarding warning in docker compose output

This warning is cosmetic when IPv4 forwarding is enabled at the kernel level. Docker emits it when it cannot verify kernel support through certain detection paths. Confirm the actual value:

```bash
cat /proc/sys/net/ipv4/ip_forward
```

If this returns `1`, the warning is safe to ignore.

### Admin password forgotten

Connect to MySQL directly and update the user record:

```bash
docker exec -it mysql mysql -u root -p bibliotheca
```

Then run the password reset through the Publink credential script:

```bash
# Create a temporary credentials file
echo -e "USERNAME=admin@example.com\nPASSWORD=newpassword\nPASSWORD_REPEAT=newpassword" \
    > /tmp/credentials.txt
docker cp /tmp/credentials.txt php:/var/www/src/credentials.txt
docker exec php php ./src/userCredentials.php
docker exec php rm ./src/credentials.txt
rm /tmp/credentials.txt
```

### Port conflicts

If ports 80, 443, or 3000 are in use on the host, `docker compose up` will fail with a bind error. Identify the process using the port:

```bash
sudo ss -tlnp | grep ':80\|:443\|:3000'
```

Stop the conflicting service or change the host port mapping in `docker-compose.yml`.

---

*Publink is developed at the Bibliotheca Hertziana with support from the Deutsche Forschungsgemeinschaft — Grant No. 501142032.*
