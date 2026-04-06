#!/usr/bin/env bash

browser_smoke_require_prereqs() {
    smoke_require_cmd curl
    smoke_require_cmd php
    smoke_require_cmd chromedriver
    smoke_require_cmd chromium
    BROWSER_SMOKE_CHROMIUM_BINARY=$(command -v chromium)
}

browser_smoke_init() {
    BROWSER_SMOKE_WINDOW_WIDTH="${1:-1600}"
    BROWSER_SMOKE_WINDOW_HEIGHT="${2:-1800}"
    BROWSER_SMOKE_TMP_DIR=$(mktemp -d)
    BROWSER_SMOKE_DRIVER_LOG="$BROWSER_SMOKE_TMP_DIR/chromedriver.log"
    BROWSER_SMOKE_PORT_FILE="$BROWSER_SMOKE_TMP_DIR/chromedriver.port"
    BROWSER_SMOKE_CHROMEDRIVER_PID=""
    BROWSER_SMOKE_SESSION_ID=""
}

browser_smoke_cleanup() {
    if [ -n "${BROWSER_SMOKE_SESSION_ID:-}" ]; then
        curl -fsS -X DELETE "http://127.0.0.1:$(cat "$BROWSER_SMOKE_PORT_FILE")/session/$BROWSER_SMOKE_SESSION_ID" >/dev/null 2>&1 || true
    fi
    if [ -n "${BROWSER_SMOKE_CHROMEDRIVER_PID:-}" ]; then
        kill "$BROWSER_SMOKE_CHROMEDRIVER_PID" >/dev/null 2>&1 || true
        wait "$BROWSER_SMOKE_CHROMEDRIVER_PID" 2>/dev/null || true
    fi
    smoke_cleanup_dir "${BROWSER_SMOKE_TMP_DIR:-}"
}

browser_smoke_pick_port() {
    php -r '$sock = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (!$sock) { fwrite(STDERR, $errstr . PHP_EOL); exit(1); } $name = stream_socket_get_name($sock, false); fclose($sock); echo (int) substr(strrchr($name, ":"), 1);'
}

browser_smoke_webdriver_post() {
    local endpoint="$1"
    local payload="${2:-}"
    if [ -n "$payload" ]; then
        curl -fsS -H 'Content-Type: application/json' -d "$payload" "http://127.0.0.1:$(cat "$BROWSER_SMOKE_PORT_FILE")$endpoint"
    else
        curl -fsS -H 'Content-Type: application/json' "http://127.0.0.1:$(cat "$BROWSER_SMOKE_PORT_FILE")$endpoint"
    fi
}

browser_smoke_json_value() {
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

browser_smoke_start() {
    printf '%s' "$(browser_smoke_pick_port)" >"$BROWSER_SMOKE_PORT_FILE"
    chromedriver --port="$(cat "$BROWSER_SMOKE_PORT_FILE")" --allowed-ips='' >"$BROWSER_SMOKE_DRIVER_LOG" 2>&1 &
    BROWSER_SMOKE_CHROMEDRIVER_PID=$!

    local _
    for _ in $(seq 1 50); do
        if curl -fsS "http://127.0.0.1:$(cat "$BROWSER_SMOKE_PORT_FILE")/status" >/dev/null 2>&1; then
            break
        fi
        sleep 0.1
    done
    curl -fsS "http://127.0.0.1:$(cat "$BROWSER_SMOKE_PORT_FILE")/status" >/dev/null 2>&1 \
        || smoke_die "ChromeDriver did not start. See: $BROWSER_SMOKE_DRIVER_LOG"

    local session_payload
    session_payload=$(cat <<EOF
{"capabilities":{"alwaysMatch":{"browserName":"chrome","goog:chromeOptions":{"binary":"${BROWSER_SMOKE_CHROMIUM_BINARY}","args":["--headless=new","--no-sandbox","--disable-dev-shm-usage","--window-size=${BROWSER_SMOKE_WINDOW_WIDTH},${BROWSER_SMOKE_WINDOW_HEIGHT}"]}}}}
EOF
)
    BROWSER_SMOKE_SESSION_ID=$(browser_smoke_webdriver_post "/session" "$session_payload" | php -r '
        $response = json_decode(stream_get_contents(STDIN), true);
        $sessionId = $response["value"]["sessionId"] ?? $response["sessionId"] ?? "";
        if ($sessionId === "") {
            fwrite(STDERR, "Failed to create WebDriver session\n");
            exit(1);
        }
        echo $sessionId;
    ')
}

browser_smoke_exec() {
    local script="$1"
    shift || true
    local payload
    payload=$(php -r '
        $script = $argv[1];
        $args = array_slice($argv, 2);
        echo json_encode(["script" => $script, "args" => $args], JSON_UNESCAPED_SLASHES);
    ' "$script" "$@")
    browser_smoke_webdriver_post "/session/$BROWSER_SMOKE_SESSION_ID/execute/sync" "$payload" | browser_smoke_json_value
}

browser_smoke_wait_for_js() {
    local script="$1"
    local attempts="${2:-40}"
    local sleep_seconds="${3:-0.25}"
    shift 3 || true
    local i
    for ((i = 0; i < attempts; i += 1)); do
        if [ "$(browser_smoke_exec "$script" "$@")" = "true" ]; then
            return 0
        fi
        sleep "$sleep_seconds"
    done
    return 1
}

browser_smoke_navigate() {
    local url="$1"
    local payload
    payload=$(php -r 'echo json_encode(["url" => $argv[1]], JSON_UNESCAPED_SLASHES);' "$url")
    browser_smoke_webdriver_post "/session/$BROWSER_SMOKE_SESSION_ID/url" "$payload" >/dev/null
    browser_smoke_wait_for_js 'return document.readyState === "complete";' 80 0.1 \
        || smoke_die "Timed out waiting for page load: $url"
}

browser_smoke_login() {
    local base_url="$1"
    local username="$2"
    local password="$3"

    smoke_log "Logging into ${base_url}"
    browser_smoke_navigate "${base_url}/login"
	[ "$(browser_smoke_exec '
		var usernameField = document.querySelector("input[name=username]");
		var passwordField = document.querySelector("input[name=password]");
		var form = document.querySelector("form");
		if (!usernameField || !passwordField || !form) {
			return false;
		}
		usernameField.value = arguments[0];
		passwordField.value = arguments[1];
        window.setTimeout(function () {
			form.submit();
        }, 0);
        return true;
	' "$username" "$password")" = "true" ] || smoke_die "Login form fields or form element were not found"

    browser_smoke_wait_for_js 'return !!document.querySelector("a[href=\"/logout\"]");' 80 0.25 \
        || smoke_die "Login did not reach authenticated shell"
}

browser_smoke_capture() {
    local output_path="$1"
    local page_height
    local rect_payload

    page_height=$(browser_smoke_exec 'return Math.max(document.body.scrollHeight, document.documentElement.scrollHeight, window.innerHeight);')
    case "$page_height" in
        ''|*[!0-9]*)
            page_height="$BROWSER_SMOKE_WINDOW_HEIGHT"
            ;;
    esac
    if [ "$page_height" -lt 900 ]; then
        page_height=900
    fi
    if [ "$page_height" -gt 4000 ]; then
        page_height=4000
    fi

    rect_payload=$(php -r '
        echo json_encode([
            "width" => (int) $argv[1],
            "height" => (int) $argv[2],
            "x" => 0,
            "y" => 0,
        ], JSON_UNESCAPED_SLASHES);
    ' "$BROWSER_SMOKE_WINDOW_WIDTH" "$page_height")
    browser_smoke_webdriver_post "/session/$BROWSER_SMOKE_SESSION_ID/window/rect" "$rect_payload" >/dev/null || true
    sleep 0.3

    browser_smoke_webdriver_post "/session/$BROWSER_SMOKE_SESSION_ID/screenshot" | browser_smoke_json_value >"$BROWSER_SMOKE_TMP_DIR/screenshot.b64"
    base64 -d "$BROWSER_SMOKE_TMP_DIR/screenshot.b64" >"$output_path"
}