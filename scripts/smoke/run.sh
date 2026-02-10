#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/lib.sh"

MODE="all"
DRY_RUN=0
RECORD_SYNC=0

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run)
            DRY_RUN=1
            ;;
        --record-sync)
            RECORD_SYNC=1
            ;;
        --web-only)
            MODE="web"
            ;;
        --sync-only)
            MODE="sync"
            ;;
        *)
            smoke_die "Unknown option: $1"
            ;;
    esac
    shift
done

if [ "$DRY_RUN" -eq 1 ]; then
    smoke_log "Dry-run mode: validating smoke harness scripts"
    bash -n "$SCRIPT_DIR/lib.sh"
    bash -n "$SCRIPT_DIR/web-workflows.sh"
    bash -n "$SCRIPT_DIR/sync-preview.sh"
    bash -n "$SCRIPT_DIR/run.sh"
    php -l "$SCRIPT_DIR/helpers/find_user_public_key.php" >/dev/null
    php -l "$SCRIPT_DIR/helpers/find_account_access_rule.php" >/dev/null
    smoke_log "Smoke harness dry-run checks passed"
    exit 0
fi

if [ "$MODE" = "all" ] || [ "$MODE" = "web" ]; then
    "$SCRIPT_DIR/web-workflows.sh"
fi

if [ "$MODE" = "all" ] || [ "$MODE" = "sync" ]; then
    if [ "$RECORD_SYNC" -eq 1 ]; then
        "$SCRIPT_DIR/sync-preview.sh" --record
    else
        "$SCRIPT_DIR/sync-preview.sh"
    fi
fi

smoke_log "All requested smoke checks completed"
