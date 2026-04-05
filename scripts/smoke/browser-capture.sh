#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/lib.sh"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/browser-lib.sh"

smoke_require_cmd base64
browser_smoke_require_prereqs

smoke_require_env SKA_SMOKE_BASE_URL
smoke_require_env SKA_SMOKE_USERNAME
smoke_require_env SKA_SMOKE_PASSWORD

BASE_URL="${SKA_SMOKE_BASE_URL%/}"
ROUTE="/"
OUTPUT_PATH=""
WINDOW_WIDTH=1600
WINDOW_HEIGHT=1800

usage() {
    cat <<'EOF'
Usage:
  source testenvs.env
  bash scripts/smoke/browser-capture.sh [route] [output.png]

Examples:
  bash scripts/smoke/browser-capture.sh /groups /tmp/ska-groups.png
  bash scripts/smoke/browser-capture.sh /servers
EOF
}

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    usage
    exit 0
fi

if [ "${1:-}" != "" ]; then
    ROUTE="$1"
fi

if [ "${2:-}" != "" ]; then
    OUTPUT_PATH="$2"
fi

case "$ROUTE" in
    http://*|https://*)
        TARGET_URL="$ROUTE"
        ;;
    /*)
        TARGET_URL="${BASE_URL}${ROUTE}"
        ;;
    *)
        TARGET_URL="${BASE_URL}/${ROUTE}"
        ;;
esac

if [ -z "$OUTPUT_PATH" ]; then
    route_slug=$(printf '%s' "$ROUTE" | sed 's#^https\?://##; s#[^A-Za-z0-9._-]#-#g; s#--*#-#g; s#^-##; s#-$##')
    [ -n "$route_slug" ] || route_slug="root"
    OUTPUT_PATH="/tmp/${route_slug}.png"
fi

browser_smoke_init "$WINDOW_WIDTH" "$WINDOW_HEIGHT"
trap browser_smoke_cleanup EXIT
browser_smoke_start
browser_smoke_login "$BASE_URL" "$SKA_SMOKE_USERNAME" "$SKA_SMOKE_PASSWORD"

smoke_log "Opening ${TARGET_URL}"
browser_smoke_navigate "$TARGET_URL"
browser_smoke_wait_for_js 'return document.body && document.body.innerText.length > 0;' 40 0.1 \
    || smoke_die "Target page did not render body content"
browser_smoke_capture "$OUTPUT_PATH"

smoke_log "Saved screenshot: $OUTPUT_PATH"
