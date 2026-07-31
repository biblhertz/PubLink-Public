#!/bin/bash
source "$(dirname "$0")/docker-env.sh"

MAX_TRIES="${MAX_TRIES:-30}"
WAIT_SECONDS="${WAIT_SECONDS:-5}"
PHP_CONTAINER="${PHP_CONTAINER:-php}"

echo "============================================"
echo " Publink Setup"
echo "============================================"
echo

# ============================================
# Defaults
# ============================================
SITE_ROOT=http://localhost
REGISTRATION_ENABLED=true
CROSSREF_INTEGRATION=true
CROSSREF_EMAIL=
GOOGLE_BOOKS_INTEGRATION=false
GOOGLE_BOOKS_API_KEY=
MANIFEST_SERVER_INTEGRATION=false
MANIFEST_SERVER_URI=
ORCID_INTEGRATION=false
ORCID_CLIENT_ID=
ORCID_CLIENT_SECRET=
ORCID_OATH_ADDRESS=https://orcid.org/oauth/token
ORCID_API_ADDRESS=https://api.orcid.org/v3.0/
PRIMO_INTEGRATION=false
PRIMO_API_KEY=
PRIMO_VID=
PRIMO_URI=
PUBLICATION=false
TEIPUB_INTEGRATION=false
TEIPUB_URI=
MIRADOR_CLIENT=
ADMIN_EMAIL=

# Load saved config if available
INIT_CFG="$(dirname "$0")/init.cfg"
if [[ -f "$INIT_CFG" ]]; then
    while IFS='=' read -r key value; do
        [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
        declare "$key=$value"
    done < "$INIT_CFG"
    echo "Loaded settings from init.cfg"
    echo
fi

# Helper: prompt with current value shown; keeps value if user presses Enter
# Usage: prompt_value VAR "Label"
prompt_value() {
    local var="$1" label="$2"
    local current="${!var}"
    local input
    read -p "$label [$current]: " input
    [[ -n "$input" ]] && declare -g "$var=$input"
}

# Helper: prompt for a secret; shows [*set*] if non-empty, only updates on input
# Usage: prompt_secret VAR "Label"
prompt_secret() {
    local var="$1" label="$2"
    local current="${!var}"
    local display=""
    [[ -n "$current" ]] && display="*set*"
    local input
    read -p "$label [$display]: " input
    [[ -n "$input" ]] && declare -g "$var=$input"
}

# Helper: prompt for a boolean (true/false); shows Y or N; accepts y/n/true/false
# Usage: prompt_bool VAR "Label"
prompt_bool() {
    local var="$1" label="$2"
    local current="${!var}"
    local display="N"
    [[ "$current" == "true" ]] && display="Y"
    local input
    read -p "$label [$display]: " input
    input="${input,,}"
    case "$input" in
        y|yes|true)  declare -g "$var=true"  ;;
        n|no|false)  declare -g "$var=false" ;;
    esac
}

# ============================================
# Site Settings
# ============================================
echo "============================================"
echo " Site Settings"
echo "============================================"
echo

prompt_value SITE_ROOT "Site root URL"
[[ -z "$SITE_ROOT" ]] && SITE_ROOT=http://localhost
[[ -z "$MIRADOR_CLIENT" ]] && MIRADOR_CLIENT="${SITE_ROOT}:3000"
prompt_value MIRADOR_CLIENT "Mirador client URL"
prompt_bool REGISTRATION_ENABLED "Allow users to self-register via the registration form"

# ============================================
# Auto-generate secrets (always fresh)
# ============================================
DB_PASSWORD=$(openssl rand -base64 16)
ENCRYPT_KEY=$(openssl rand -base64 32)
sed -i "s|^password=.*|password=${DB_PASSWORD}|" "$(dirname "$0")/mysql/my.cnf"
echo "Database password generated."
echo "Encryption key generated."
echo

# ============================================
# Integration Setup
# ============================================
echo "============================================"
echo " Integration Setup"
echo "============================================"
echo

# --- CrossRef ---
prompt_bool CROSSREF_INTEGRATION "Include CrossRef reference lookup"
if [[ "$CROSSREF_INTEGRATION" == "true" ]]; then
    prompt_value CROSSREF_EMAIL "CrossRef contact email for polite pool, leave blank to skip"
fi
echo

# --- Google Books ---
prompt_bool GOOGLE_BOOKS_INTEGRATION "Include Google Books reference lookup"
if [[ "$GOOGLE_BOOKS_INTEGRATION" == "true" ]]; then
    prompt_value GOOGLE_BOOKS_API_KEY "Google Books API key, leave blank for unauthenticated"
else
    GOOGLE_BOOKS_API_KEY=
fi
echo

# --- Simple Manifest Server ---
prompt_bool MANIFEST_SERVER_INTEGRATION "Include Simple Manifest Server"
if [[ "$MANIFEST_SERVER_INTEGRATION" == "true" ]]; then
    prompt_value      MANIFEST_SERVER_URI     "Manifest Server URI"
fi
echo

# --- TEI Publisher ---
TEIPUB_DOCKER=false
prompt_bool TEIPUB_INTEGRATION "Include TEI Publisher integration"
if [[ "$TEIPUB_INTEGRATION" == "true" ]]; then
    prompt_bool TEIPUB_DOCKER "Run TEI Publisher as a Docker service (uses existdb/teipublisher image)"
    if [[ "$TEIPUB_DOCKER" == "true" ]]; then
        TEIPUB_URI="http://tei-publisher:8080/exist/apps/tei-publisher"
        echo "TEI Publisher URI set to $TEIPUB_URI"
    else
        prompt_value TEIPUB_URI "TEI Publisher URI (external instance)"
    fi
else
    TEIPUB_URI=
fi
echo

# --- ORCID (not available on localhost) ---
if [[ "$SITE_ROOT" == "http://localhost" ]]; then
    ORCID_INTEGRATION=false
    ORCID_CLIENT_ID=
    ORCID_CLIENT_SECRET=
else
    prompt_bool ORCID_INTEGRATION "Include ORCID authentication"
    if [[ "$ORCID_INTEGRATION" == "true" ]]; then
        prompt_value  ORCID_CLIENT_ID     "ORCID Client ID"
        prompt_secret ORCID_CLIENT_SECRET "ORCID Client Secret"
        prompt_value  ORCID_OATH_ADDRESS  "ORCID OAuth address"
        prompt_value  ORCID_API_ADDRESS   "ORCID API address"
    fi
fi
echo

# --- Primo ---
prompt_bool PRIMO_INTEGRATION "Include Primo/Alma library search"
if [[ "$PRIMO_INTEGRATION" == "true" ]]; then
    prompt_value PRIMO_API_KEY "Primo API key"
    prompt_value PRIMO_VID     "Primo VID"
    prompt_value PRIMO_URI     "Primo URI"
fi
echo

# --- Annotation Publication Server ---
prompt_bool PUBLICATION "Enable annotation publication (requires Simple Manifest Server)"
echo

# ============================================
# Admin User Setup
# ============================================
echo "============================================"
echo " Admin User Setup"
echo "============================================"
echo

if [[ $# -eq 2 ]]; then
    USERNAME="$1"
    PASSWORD1="$2"
    PASSWORD2="$2"
elif [[ $# -eq 0 ]]; then
    prompt_value ADMIN_EMAIL "Admin Email"
    USERNAME="$ADMIN_EMAIL"
    if [[ -z "$USERNAME" ]]; then
        echo "Error: username cannot be empty"
        exit 1
    fi
    read -s -p "Password: " PASSWORD1; echo
    read -s -p "Repeat Password: " PASSWORD2; echo
else
    echo "Usage: $0 <username> <password>"
    echo "   or: $0  (interactive)"
    exit 1
fi

if [[ -z "$USERNAME" || -z "$PASSWORD1" ]]; then
    echo "Error: username and/or password cannot be empty"
    exit 1
fi
if [[ "$PASSWORD1" != "$PASSWORD2" ]]; then
    echo "Error: passwords do not match"
    exit 1
fi
echo "Passwords match"

# ============================================
# Save config for next run
# ============================================
cat > "$INIT_CFG" << EOF
SITE_ROOT=${SITE_ROOT}
REGISTRATION_ENABLED=${REGISTRATION_ENABLED}
CROSSREF_INTEGRATION=${CROSSREF_INTEGRATION}
CROSSREF_EMAIL=${CROSSREF_EMAIL}
GOOGLE_BOOKS_INTEGRATION=${GOOGLE_BOOKS_INTEGRATION}
GOOGLE_BOOKS_API_KEY=${GOOGLE_BOOKS_API_KEY}
MANIFEST_SERVER_INTEGRATION=${MANIFEST_SERVER_INTEGRATION}
MANIFEST_SERVER_URI=${MANIFEST_SERVER_URI}
ORCID_INTEGRATION=${ORCID_INTEGRATION}
ORCID_CLIENT_ID=${ORCID_CLIENT_ID}
ORCID_CLIENT_SECRET=${ORCID_CLIENT_SECRET}
ORCID_OATH_ADDRESS=${ORCID_OATH_ADDRESS}
ORCID_API_ADDRESS=${ORCID_API_ADDRESS}
PRIMO_INTEGRATION=${PRIMO_INTEGRATION}
PRIMO_API_KEY=${PRIMO_API_KEY}
PRIMO_VID=${PRIMO_VID}
PRIMO_URI=${PRIMO_URI}
PUBLICATION=${PUBLICATION}
TEIPUB_INTEGRATION=${TEIPUB_INTEGRATION}
TEIPUB_DOCKER=${TEIPUB_DOCKER}
TEIPUB_URI=${TEIPUB_URI}
MIRADOR_CLIENT=${MIRADOR_CLIENT}
ADMIN_EMAIL=${ADMIN_EMAIL}
EOF
chmod 600 "$INIT_CFG"
echo "init.cfg saved."
echo

# ============================================
# Write .env for docker compose
# ============================================
cat > .env << EOF
SITE_ROOT=${SITE_ROOT}
DB_PASSWORD=${DB_PASSWORD}
ENCRYPT_KEY=${ENCRYPT_KEY}
REGISTRATION_ENABLED=${REGISTRATION_ENABLED}
MANIFEST_SERVER_INTEGRATION=${MANIFEST_SERVER_INTEGRATION}
MANIFEST_SERVER_URI=${MANIFEST_SERVER_URI}
CROSSREF_INTEGRATION=${CROSSREF_INTEGRATION}
CROSSREF_EMAIL=${CROSSREF_EMAIL}
GOOGLE_BOOKS_INTEGRATION=${GOOGLE_BOOKS_INTEGRATION}
GOOGLE_BOOKS_API_KEY=${GOOGLE_BOOKS_API_KEY}
ORCID_INTEGRATION=${ORCID_INTEGRATION}
ORCID_CLIENT_ID=${ORCID_CLIENT_ID}
ORCID_CLIENT_SECRET=${ORCID_CLIENT_SECRET}
ORCID_OATH_ADDRESS=${ORCID_OATH_ADDRESS}
ORCID_API_ADDRESS=${ORCID_API_ADDRESS}
PRIMO_INTEGRATION=${PRIMO_INTEGRATION}
PRIMO_API_KEY=${PRIMO_API_KEY}
PRIMO_VID=${PRIMO_VID}
PRIMO_URI=${PRIMO_URI}
PUBLICATION=${PUBLICATION}
TEIPUB_INTEGRATION=${TEIPUB_INTEGRATION}
TEIPUB_URI=${TEIPUB_URI}
MIRADOR_CLIENT=${MIRADOR_CLIENT}
EOF
chmod 600 .env
echo ".env written."
echo

cat > credentials.txt << EOF
USERNAME=$USERNAME
PASSWORD=$PASSWORD1
PASSWORD_REPEAT=$PASSWORD2
EOF
chmod 600 credentials.txt

mkdir -p ./db_backup
chmod +x ./*.sh

# Tear down and rebuild containers
docker compose --profile tei down --rmi all -v --remove-orphans
docker images -q | xargs -r docker rmi
COMPOSE_PROFILES=""
[[ "$TEIPUB_DOCKER" == "true" ]] && COMPOSE_PROFILES="--profile tei"
docker compose --verbose $COMPOSE_PROFILES up -d

# Wait for MySQL
echo "Waiting for MySQL..."
count=0
until docker exec "$PHP_CONTAINER" php -r "new PDO('mysql:host=mysql;dbname=bibliotheca', 'bibliotheca_user', getenv('DB_PASSWORD'));" 2>/dev/null; do
    count=$((count + 1))
    if [[ "$count" -ge "$MAX_TRIES" ]]; then
        echo "Error: MySQL did not become ready after $((MAX_TRIES * WAIT_SECONDS)) seconds"
        rm -f credentials.txt
        exit 1
    fi
    echo "Attempt $count/$MAX_TRIES - waiting ${WAIT_SECONDS}s..."
    sleep "$WAIT_SECONDS"
done

echo "MySQL is ready!"

docker cp credentials.txt "$PHP_CONTAINER":/var/www/src/credentials.txt
docker exec "$PHP_CONTAINER" php ./src/userCredentials.php
docker exec "$PHP_CONTAINER" rm ./src/credentials.txt
rm -f credentials.txt

echo
echo "Publink is now installed"
