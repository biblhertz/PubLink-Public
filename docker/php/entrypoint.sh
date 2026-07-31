#!/bin/sh

if [ -z "${ENCRYPT_KEY}" ] || [ -z "${DB_PASSWORD}" ]; then
    echo "ERROR: ENCRYPT_KEY and DB_PASSWORD must be set. Run init.bat (Windows) or init.sh (Linux) to generate a .env file." >&2
    exit 1
fi

chown -R www-data:www-data /var/www/file_store
chmod -R 764 /var/www/file_store

# Generate config.ini from environment variables
cat > /var/www/config.ini << EOF
[environment]
environment = 1

[xsd_paths]
ojs_xsd['3.3'] = "/var/www/xsd/ojs/3_3/native.xsd"
ojs_xsd['3.4'] = "/var/www/xsd/ojs/3_4/native.xsd"
ojs_xsd['3.5'] = "/var/www/xsd/ojs/3_5/native.xsd"
jats_xsd['1.4'] = "/var/www/xsd/1.4/JATS-journalpublishing1-4.xsd"
jats_xsd['1.3'] = "/var/www/xsd/1.3/JATS-journalpublishing1-3.xsd"
jats_xsd['1.2'] = "/var/www/xsd/1.2/JATS-journalpublishing1.xsd"
jats_xsd['1.1'] = "/var/www/xsd/1.1/JATS-journalpublishing1.xsd"
jats_xsd['1.0'] = "/var/www/xsd/1.0/JATS-journalpublishing1.xsd"

[orcid]
orcid_integration = ${ORCID_INTEGRATION:-false}
orcid_client_id = "${ORCID_CLIENT_ID:-}"
orcid_client_secret = "${ORCID_CLIENT_SECRET:-}"
orcid_oath_address = "${ORCID_OATH_ADDRESS:-https://orcid.org/oauth/token}"
orcid_api_address = "${ORCID_API_ADDRESS:-https://api.orcid.org/v3.0/}"

[manifest_server]
manifest_server_integration = ${MANIFEST_SERVER_INTEGRATION:-false}
manifest_server_uri = "${MANIFEST_SERVER_URI:-}"

[crossref]
crossref_integration = ${CROSSREF_INTEGRATION:-true}
crossref_email = "${CROSSREF_EMAIL:-}"

[google_books]
google_books_api_key = "${GOOGLE_BOOKS_API_KEY:-}"

[primo]
primo_integration = ${PRIMO_INTEGRATION:-false}
primo_api_key = "${PRIMO_API_KEY:-}"
primo_vid = "${PRIMO_VID:-}"
primo_uri = "${PRIMO_URI:-}"

[site_settings]
registration_enabled = ${REGISTRATION_ENABLED:-true}
site_root = "${SITE_ROOT:-http://localhost}"
file_store_path = "/var/www/file_store"
log_dir = "/var/www/logs"
job_log_dir = "/var/www/logs/job_queue"
pdo_host = "mysql"
database_name = "bibliotheca"
database_user = "bibliotheca_user"
database_password = "${DB_PASSWORD:-}"
publink_encryption_key = "${ENCRYPT_KEY}"

[teipublisher]
teipublisher_integration = ${TEIPUB_INTEGRATION:-false}
teipublisher_uri = "${TEIPUB_URI:-}"

[publication]
publication = ${PUBLICATION:-false}

[citations]
citations[0] = "bibliotheca-hertziana-max-planck-institute-for-art-history"
citations[1] = "Bibliotheca Hertziana"
citations[2] = "ieee"
citations[3] = "ieee"
citations[4] = "apa"
citations[5] = "apa"
citations[6] = "nature"
citations[7] = "nature"
citations[8] = "harvard-cite-them-right"
citations[9] = "harvard-cite-them-right"
citations[10] = "bmj"
citations[11] = "British Medical Journal"
csl_location = "/var/www/vendor/citation-style-language/styles/"

[job_queue]
job_queue_dir = "/var/www/job_queue"

[annotation]
mirador_client = "${MIRADOR_CLIENT:-}"
EOF

exec php-fpm
