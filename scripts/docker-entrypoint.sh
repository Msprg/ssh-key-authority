#!/bin/sh
# Container entrypoint for SKA.
#
# Bind-mounted volumes (see docker-compose.yml.example) replace the directories
# prepared in the Dockerfile, including their ownership. If the host directory
# is owned by root, cron jobs running as www-data cannot open their log files
# and silently never run, and the sync daemon (keys-sync) cannot write key
# files. Repair ownership on every start, then hand off to supervisord.
set -eu

APP_DIR=${APP_DIR:-/srv/keys}
LOG_DIR=/var/log/ska
SYNC_DIR=/var/local/keys-sync

# Logs written by the cron jobs in /etc/cron.d/ska (run as www-data).
mkdir -p "$LOG_DIR"
touch "$LOG_DIR/ldap_update.log" "$LOG_DIR/supervise_external_keys.log"
chown -R www-data:www-data "$LOG_DIR"

# Output directory of scripts/sync.php (run as keys-sync).
mkdir -p "$SYNC_DIR"
chown -R keys-sync:keys-sync "$SYNC_DIR"

# Optional: SSH key pair mounted read-only from the host as *.host, installed
# with the ownership/permissions the sync daemon needs. No-op if not mounted.
install_if_present() {
    if [ -f "$1" ]; then
        install -o keys-sync -g keys-sync -m "$3" "$1" "$2"
    fi
}
install_if_present "$APP_DIR/config/keys-sync.host" "$APP_DIR/config/keys-sync" 600
install_if_present "$APP_DIR/config/keys-sync.pub.host" "$APP_DIR/config/keys-sync.pub" 644

exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
