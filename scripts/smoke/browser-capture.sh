#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/lib.sh"

smoke_require_cmd curl
smoke_require_cmd php
smoke_require_cmd chromedriver
smoke_require_cmd chromium
smoke_require_cmd base64

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

TMP_DIR=$(mktemp -d)
DRIVER_LOG="$TMP_DIR/chromedriver.log"
PORT_FILE="$TMP_DIR/chromedriver.port"
SCREENSHOT_B64="$TMP_DIR/screenshot.b64"
CHROMEDRIVER_PID=""
SESSION_ID=""

cleanup() {
    if [ -n "$SESSION_ID" ]; then
        curl -fsS -X DELETE "http://127.0.0.1:$(cat "$PORT_FILE")/session/$SESSION_ID" >/dev/null 2>&1 || true
    fi
    if [ -n "$CHROMEDRIVER_PID" ]; then
        kill "$CHROMEDRIVER_PID" >/dev/null 2>&1 || true
        wait "$CHROMEDRIVER_PID" 2>/dev/null || true
    fi
    smoke_cleanup_dir "$TMP_DIR"
}
trap cleanup EXIT

pick_port() {
    php -r '$sock = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (!$sock) { fwrite(STDERR, $errstr . PHP_EOL); exit(1); } $name = stream_socket_get_name($sock, false); fclose($sock); echo (int) substr(strrchr($name, ":"), 1);'
}

webdriver_post() {
    local endpoint="$1"
    local payload="${2:-}"
    if [ -n "$payload" ]; then
        curl -fsS -H 'Content-Type: application/json' -d "$payload" "http://127.0.0.1:$(cat "$PORT_FILE")$endpoint"
    else
        curl -fsS -H 'Content-Type: application/json' "http://127.0.0.1:$(cat "$PORT_FILE")$endpoint"
    fi
}

webdriver_delete() {
    local endpoint="$1"
    curl -fsS -X DELETE "http://127.0.0.1:$(cat "$PORT_FILE")$endpoint"
}

json_value() {
    php -r '
        $response = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($response) || !array_key_exists("value", $response)) {
            fwrite(STDERR, "Invalid WebDriver response\n");
            exit(1);
        }
        $value = $response["value"];
        if (is_array($value)) {
            echo json_encode($value, JSON_UNESCAPED_SLASHES);
            exit(0);
        }
        if ($value === true) {
            echo "true";
            exit(0);
        }
        if ($value === false) {
            echo "false";
            exit(0);
        }
        if ($value === null) {
            exit(0);
        }
        echo $value;
    '
}

wd_exec() {
    local script="$1"
    shift || true
    local payload
    payload=$(php -r '
        $script = $argv[1];
        $args = array_slice($argv, 2);
        echo json_encode(["script" => $script, "args" => $args], JSON_UNESCAPED_SLASHES);
    ' "$script" "$@")
    webdriver_post "/session/$SESSION_ID/execute/sync" "$payload" | json_value
}

wait_for_js() {
    local script="$1"
    local attempts="${2:-40}"
    local sleep_seconds="${3:-0.25}"
    local i
    for ((i = 0; i < attempts; i += 1)); do
        if [ "$(wd_exec "$script")" = "true" ]; then
            return 0
        fi
        sleep "$sleep_seconds"
    done
    return 1
}

navigate_to() {
    local url="$1"
    local payload
    payload=$(php -r 'echo json_encode(["url" => $argv[1]], JSON_UNESCAPED_SLASHES);' "$url")
    webdriver_post "/session/$SESSION_ID/url" "$payload" >/dev/null
    wait_for_js 'return document.readyState === "complete";' 80 0.1 || smoke_die "Timed out waiting for page load: $url"
}

printf '%s' "$(pick_port)" >"$PORT_FILE"
chromedriver --port="$(cat "$PORT_FILE")" --allowed-ips='' >"$DRIVER_LOG" 2>&1 &
CHROMEDRIVER_PID=$!

for _ in $(seq 1 50); do
    if curl -fsS "http://127.0.0.1:$(cat "$PORT_FILE")/status" >/dev/null 2>&1; then
        break
    fi
    sleep 0.1
done
curl -fsS "http://127.0.0.1:$(cat "$PORT_FILE")/status" >/dev/null 2>&1 || smoke_die "ChromeDriver did not start. See: $DRIVER_LOG"

SESSION_PAYLOAD=$(cat <<EOF
{"capabilities":{"alwaysMatch":{"browserName":"chrome","goog:chromeOptions":{"binary":"/usr/bin/chromium","args":["--headless=new","--no-sandbox","--disable-dev-shm-usage","--window-size=${WINDOW_WIDTH},${WINDOW_HEIGHT}"]}}}}
EOF
)
SESSION_ID=$(webdriver_post "/session" "$SESSION_PAYLOAD" | php -r '
    $response = json_decode(stream_get_contents(STDIN), true);
    $sessionId = $response["value"]["sessionId"] ?? $response["sessionId"] ?? "";
    if ($sessionId === "") {
        fwrite(STDERR, "Failed to create WebDriver session\n");
        exit(1);
    }
    echo $sessionId;
')

smoke_log "Logging into ${BASE_URL}"
navigate_to "${BASE_URL}/login"

wd_exec '
    document.querySelector("input[name=username]").value = arguments[0];
    document.querySelector("input[name=password]").value = arguments[1];
    window.setTimeout(function () {
        document.querySelector("form").submit();
    }, 0);
    return true;
' "$SKA_SMOKE_USERNAME" "$SKA_SMOKE_PASSWORD" >/dev/null

wait_for_js 'return !!document.querySelector("a[href=\"/logout\"]");' 80 0.25 \
    || smoke_die "Login did not reach authenticated shell"

smoke_log "Opening ${TARGET_URL}"
navigate_to "$TARGET_URL"
wait_for_js 'return document.body && document.body.innerText.length > 0;' 40 0.1 \
    || smoke_die "Target page did not render body content"

PAGE_HEIGHT=$(wd_exec 'return Math.max(document.body.scrollHeight, document.documentElement.scrollHeight, window.innerHeight);')
case "$PAGE_HEIGHT" in
    ''|*[!0-9]*)
        PAGE_HEIGHT="$WINDOW_HEIGHT"
        ;;
esac
if [ "$PAGE_HEIGHT" -lt 900 ]; then
    PAGE_HEIGHT=900
fi
if [ "$PAGE_HEIGHT" -gt 4000 ]; then
    PAGE_HEIGHT=4000
fi

RECT_PAYLOAD=$(php -r '
    echo json_encode([
        "width" => (int) $argv[1],
        "height" => (int) $argv[2],
        "x" => 0,
        "y" => 0,
    ], JSON_UNESCAPED_SLASHES);
' "$WINDOW_WIDTH" "$PAGE_HEIGHT")
webdriver_post "/session/$SESSION_ID/window/rect" "$RECT_PAYLOAD" >/dev/null || true
sleep 0.3

webdriver_post "/session/$SESSION_ID/screenshot" | json_value >"$SCREENSHOT_B64"
base64 -d "$SCREENSHOT_B64" >"$OUTPUT_PATH"

smoke_log "Saved screenshot: $OUTPUT_PATH"
