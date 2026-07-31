#!/bin/bash
#
# Sourced by start.sh / stop.sh / restart.sh / init.sh.
# When running as a non-root user, point the Docker CLI at the rootless
# daemon socket; when running as root, use the default system socket.

if [ "$(id -u)" -ne 0 ]; then
    export DOCKER_HOST="unix:///run/user/$(id -u)/docker.sock"
    export XDG_RUNTIME_DIR="/run/user/$(id -u)"
fi
