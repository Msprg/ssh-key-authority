#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$SCRIPT_DIR/../.." && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/lib.sh"

smoke_require_cmd php
smoke_require_cmd sed
smoke_require_cmd diff

RECORD_FIXTURE=0
if [ "${1:-}" = "--record" ]; then
    RECORD_FIXTURE=1
fi

smoke_require_env SKA_SMOKE_SYNC_SERVER_ID

SYNC_USER_SUFFIX=""
SYNC_USER_ARG=()
if [ -n "${SKA_SMOKE_SYNC_USERNAME:-}" ]; then
    SYNC_USER_SUFFIX="-user-${SKA_SMOKE_SYNC_USERNAME}"
    SYNC_USER_ARG=(--user "$SKA_SMOKE_SYNC_USERNAME")
fi

DEFAULT_FIXTURE="$SCRIPT_DIR/fixtures/sync/server-${SKA_SMOKE_SYNC_SERVER_ID}${SYNC_USER_SUFFIX}.txt"
FIXTURE_PATH="${SKA_SMOKE_SYNC_FIXTURE:-$DEFAULT_FIXTURE}"

TMP_DIR=$(mktemp -d)
RAW_OUTPUT="$TMP_DIR/sync-preview.raw.txt"
NORMALIZED_OUTPUT="$TMP_DIR/sync-preview.normalized.txt"

cleanup() {
    smoke_cleanup_dir "$TMP_DIR"
}
trap cleanup EXIT

smoke_log "Running sync preview for server id ${SKA_SMOKE_SYNC_SERVER_ID}"
(
    cd "$REPO_ROOT"
    php scripts/sync.php --id "$SKA_SMOKE_SYNC_SERVER_ID" --preview "${SYNC_USER_ARG[@]}"
) > "$RAW_OUTPUT"

# Normalize volatile output (timestamps and ANSI color codes).
sed -E 's/\x1B\[[0-9;]*[[:alpha:]]//g' "$RAW_OUTPUT" \
    | sed -E 's/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[^ ]+ ([^:]+): /\1: /' \
    | sed -E 's/[[:space:]]+$//' \
    > "$NORMALIZED_OUTPUT"

if [ "$RECORD_FIXTURE" -eq 1 ]; then
    mkdir -p "$(dirname "$FIXTURE_PATH")"
    cp "$NORMALIZED_OUTPUT" "$FIXTURE_PATH"
    smoke_log "Recorded fixture: $FIXTURE_PATH"
    exit 0
fi

[ -f "$FIXTURE_PATH" ] || smoke_die "Fixture not found: $FIXTURE_PATH. Record one with: scripts/smoke/sync-preview.sh --record"

diff -u "$FIXTURE_PATH" "$NORMALIZED_OUTPUT" >/dev/null \
    || smoke_die "Sync preview differs from fixture: $FIXTURE_PATH"

smoke_log "Sync preview matches fixture: $FIXTURE_PATH"
