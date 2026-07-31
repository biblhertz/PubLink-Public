#!/bin/bash
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/docker-env.sh"
source "$SCRIPT_DIR/dbbackup.sh"
docker compose --profile tei down
