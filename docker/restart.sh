#!/bin/bash
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/docker-env.sh"
source "$SCRIPT_DIR/dbbackup.sh"

COMPOSE_PROFILES=""
TEIPUB_DOCKER=$(grep '^TEIPUB_DOCKER=' "$SCRIPT_DIR/init.cfg" 2>/dev/null | cut -d'=' -f2-)
[ "$TEIPUB_DOCKER" = "true" ] && COMPOSE_PROFILES="--profile tei"

docker compose --profile tei down --rmi all -v --remove-orphans
images=$(docker images -q)
[ -n "$images" ] && docker rmi $images
docker compose --verbose $COMPOSE_PROFILES up -d
