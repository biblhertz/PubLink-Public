#!/bin/bash
#
# Sets up rootless Docker for a dedicated non-root user.
# Run once as root.
#
# Usage:
#   ./setup-rootless.sh            # uses default user 'publink'
#   ./setup-rootless.sh myuser     # uses an existing or new user 'myuser'

set -e

ROOTLESS_USER="${1:-publink}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Error: this script must be run as root" >&2
    exit 1
fi

# ── 1. Dependencies ────────────────────────────────────────────────────────────
echo "Installing dependencies..."
apt-get install -y --no-install-recommends uidmap slirp4netns dbus-user-session

# ── 2. Create user if needed ───────────────────────────────────────────────────
if ! id "$ROOTLESS_USER" &>/dev/null; then
    useradd -m -s /bin/bash "$ROOTLESS_USER"
    echo "Created user '$ROOTLESS_USER'"
fi

RUID=$(id -u "$ROOTLESS_USER")
RGID=$(id -g "$ROOTLESS_USER")

# ── 3. Subordinate UID/GID mappings ───────────────────────────────────────────
# Required for the user namespace that rootless Docker uses.
grep -q "^${ROOTLESS_USER}:" /etc/subuid || \
    echo "${ROOTLESS_USER}:100000:65536" >> /etc/subuid
grep -q "^${ROOTLESS_USER}:" /etc/subgid || \
    echo "${ROOTLESS_USER}:100000:65536" >> /etc/subgid

# ── 4. Allow binding to ports 80 and 443 ──────────────────────────────────────
echo "Configuring unprivileged port binding..."
sysctl -w net.ipv4.ip_unprivileged_port_start=80
echo "net.ipv4.ip_unprivileged_port_start=80" \
    > /etc/sysctl.d/99-publink-rootless.conf

# ── 5. Ensure the XDG runtime dir exists for the user ─────────────────────────
XDG_DIR="/run/user/${RUID}"
mkdir -p "$XDG_DIR"
chown "$RUID:$RGID" "$XDG_DIR"
chmod 700 "$XDG_DIR"

# ── 6. Install rootless Docker as the target user ─────────────────────────────
echo "Installing rootless Docker for user '$ROOTLESS_USER'..."
sudo -u "$ROOTLESS_USER" XDG_RUNTIME_DIR="$XDG_DIR" \
    dockerd-rootless-setuptool.sh install

# ── 7. Enable the systemd user service so it starts at login/boot ─────────────
sudo -u "$ROOTLESS_USER" XDG_RUNTIME_DIR="$XDG_DIR" \
    systemctl --user enable docker || true

# Lingering keeps the user service running even when no session is open.
loginctl enable-linger "$ROOTLESS_USER"

# ── 8. Add the user to the docker group (optional, for CLI convenience) ────────
usermod -aG docker "$ROOTLESS_USER" 2>/dev/null || true

# ── 9. Copy project files to the user's home if running from /root ────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [[ "$SCRIPT_DIR" == /root/* ]]; then
    DEST="/home/${ROOTLESS_USER}/$(basename "$SCRIPT_DIR")"
    cp -r "$SCRIPT_DIR" "$DEST"
    chown -R "$ROOTLESS_USER:$ROOTLESS_USER" "$DEST"
    echo "Copied project to $DEST"
fi

echo ""
echo "Done. Next steps:"
echo ""
echo "  1. Switch to the rootless user:"
echo "       sudo -u $ROOTLESS_USER -i"
echo ""
echo "  2. Start the Docker daemon (if not already started by systemd):"
echo "       systemctl --user start docker"
echo ""
echo "  3. Run the publink init script:"
echo "       cd <project-dir>/docker && ./init.sh"
