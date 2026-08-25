# Generating a Dataverse deposit

PubLink-Public archives to Edmond (the Max Planck Society's Dataverse
instance) manually — there is no GitHub Action for this. Automated per-file
sync was tried and dropped: the repo's file count is close to Edmond's
500-file-per-dataset limit, and the upload tooling available at the time
couldn't reliably confirm that old files were actually deleted before new
ones were added, risking silent duplicate accumulation across versions. A
single zip archive avoids both problems.

Dataset: https://doi.org/10.17617/3.OJEPZN

## What goes in the deposit

- One zip of the repo, built from git so nothing untracked or stale sneaks in
- `README.md` and `SOURCES.md`, uploaded as their own separate browsable
  files (not inside the zip)
- The zip excludes `docker/publink/xsd/` — those are standard, publicly
  maintained JATS/OJS schema files, not part of PubLink itself. Their
  official sources are listed in `SOURCES.md`.

## Steps

1. From a clean checkout at the commit/tag you want to archive (e.g. the
   `v1.0-final-report` tag):

   ```bash
   cd PubLink-Public
   git archive --format=zip --output=/tmp/PubLink-Public-v1.0-final-report.zip HEAD
   zip -d /tmp/PubLink-Public-v1.0-final-report.zip README.md SOURCES.md 'docker/publink/xsd/*'
   ```

2. On the Edmond dataset page, upload:
   - `/tmp/PubLink-Public-v1.0-final-report.zip`
   - `README.md` (from the repo root)
   - `SOURCES.md` (from the repo root)

3. Submit the draft for curator review (Edmond requires curator approval —
   there is no self-publish).

For a future version, repeat from the desired commit/tag with an updated
zip filename (e.g. `-v1.1-...`), and remove the previous zip/README/SOURCES
files from the dataset draft before uploading the new ones, so the dataset
doesn't accumulate stale copies across versions.
