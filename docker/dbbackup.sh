#!/bin/bash
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

TARGET_DIR="$SCRIPT_DIR/db_backup/"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
FILENAME="${TARGET_DIR}publink_${TIMESTAMP}.sql"
EFILENAME="$SCRIPT_DIR/mysql/bibliotheca.sql"

DB_PASSWORD=$(grep '^DB_PASSWORD=' "$SCRIPT_DIR/.env" | cut -d'=' -f2- | tr -d '[:space:]')

mkdir -p "$TARGET_DIR"

docker exec mysql mysqldump -u root -p"$DB_PASSWORD" bibliotheca > "$FILENAME"

if [ -s "$FILENAME" ]; then
    cp "$FILENAME" "$EFILENAME"
    echo "Backup saved to $FILENAME and $EFILENAME"
else
    echo "Warning: backup file is empty, not copying to $EFILENAME"
    rm -f "$FILENAME"
fi

find "$TARGET_DIR" -type f -mtime +30 -print -delete
