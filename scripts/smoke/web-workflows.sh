#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/lib.sh"

smoke_require_cmd curl
smoke_require_cmd php
smoke_require_cmd ssh-keygen

smoke_require_env SKA_SMOKE_BASE_URL
smoke_require_env SKA_SMOKE_USERNAME
smoke_require_env SKA_SMOKE_PASSWORD
smoke_require_env SKA_SMOKE_ACCESS_SERVER_HOSTNAME
smoke_require_env SKA_SMOKE_ACCESS_ACCOUNT_NAME
smoke_require_env SKA_SMOKE_ACCESS_SOURCE_USER

BASE_URL="${SKA_SMOKE_BASE_URL%/}"
TMP_DIR=$(mktemp -d)
COOKIE_JAR="$TMP_DIR/cookies.txt"

cleanup() {
    smoke_cleanup_dir "$TMP_DIR"
}
trap cleanup EXIT

smoke_log "Checking login page"
curl -fsS -L -c "$COOKIE_JAR" "$BASE_URL/login" -o "$TMP_DIR/login.html"
LOGIN_CSRF=$(smoke_extract_csrf "$TMP_DIR/login.html")
[ -n "$LOGIN_CSRF" ] || smoke_die "Login CSRF token not found"

smoke_log "Executing LDAP login flow"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    --data-urlencode "csrf_token=$LOGIN_CSRF" \
    --data-urlencode "username=$SKA_SMOKE_USERNAME" \
    --data-urlencode "password=$SKA_SMOKE_PASSWORD" \
    "$BASE_URL/login" -o "$TMP_DIR/post-login.html"

grep -q '>Logout<' "$TMP_DIR/post-login.html" || smoke_die "Login failed or did not reach authenticated UI"

smoke_log "Adding and deleting a public key for logged-in user"
KEY_COMMENT="ska-smoke-$(date +%s)"
KEY_PATH="$TMP_DIR/smoke-key"
ssh-keygen -q -t ed25519 -N '' -C "$KEY_COMMENT" -f "$KEY_PATH" >/dev/null
PUBLIC_KEY=$(cat "$KEY_PATH.pub")

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/" -o "$TMP_DIR/home-before-key.html"
HOME_CSRF=$(smoke_extract_csrf "$TMP_DIR/home-before-key.html")
[ -n "$HOME_CSRF" ] || smoke_die "Home page CSRF token not found before key add"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    --data-urlencode "csrf_token=$HOME_CSRF" \
    --data-urlencode "add_public_key=$PUBLIC_KEY" \
    "$BASE_URL/" -o "$TMP_DIR/home-after-key-add.html"

if ! KEY_ID=$(php "$SCRIPT_DIR/helpers/find_user_public_key.php" \
    --html "$TMP_DIR/home-after-key-add.html" \
    --comment "$KEY_COMMENT"); then
    smoke_die "Added key was not found in active key set"
fi

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/" -o "$TMP_DIR/home-before-key-delete.html"
HOME_DELETE_CSRF=$(smoke_extract_csrf "$TMP_DIR/home-before-key-delete.html")
[ -n "$HOME_DELETE_CSRF" ] || smoke_die "Home page CSRF token not found before key delete"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    --data-urlencode "csrf_token=$HOME_DELETE_CSRF" \
    --data-urlencode "delete_public_key=$KEY_ID" \
    "$BASE_URL/" -o "$TMP_DIR/home-after-key-delete.html"

if php "$SCRIPT_DIR/helpers/find_user_public_key.php" \
    --html "$TMP_DIR/home-after-key-delete.html" \
    --comment "$KEY_COMMENT" >/dev/null 2>&1; then
    smoke_die "Deleted key is still active"
fi

smoke_log "Adding and removing access rule on target account"

TARGET_SERVER=$(smoke_urlencode "$SKA_SMOKE_ACCESS_SERVER_HOSTNAME")
TARGET_ACCOUNT=$(smoke_urlencode "$SKA_SMOKE_ACCESS_ACCOUNT_NAME")
TARGET_PATH="/servers/${TARGET_SERVER}/accounts/${TARGET_ACCOUNT}"

fetch_account_page() {
    local output_file="$1"
    local http_code
    http_code=$(curl -sS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
        -o "$output_file" \
        -w "%{http_code}" \
        "$BASE_URL$TARGET_PATH")
    if [ "$http_code" != "200" ]; then
        smoke_die "Target account page request failed (HTTP ${http_code}) at path '${TARGET_PATH}'. Verify SKA_SMOKE_BASE_URL and target server/account env vars."
    fi
    grep -q '>Logout<' "$output_file" || smoke_die "Authenticated session was lost while accessing '${TARGET_PATH}'"
    grep -q 'name="add_access"' "$output_file" || smoke_die "Authenticated user cannot manage access on '${SKA_SMOKE_ACCESS_ACCOUNT_NAME}@${SKA_SMOKE_ACCESS_SERVER_HOSTNAME}' (add_access form missing)"
}

require_post_status_ok() {
    local status_code="$1"
    local action_name="$2"
    case "$status_code" in
        200|302|303)
            ;;
        *)
            smoke_die "${action_name} failed (HTTP ${status_code}) at path '${TARGET_PATH}'"
            ;;
    esac
}

fetch_account_page "$TMP_DIR/account-before-access.html"
set +e
EXISTING_ACCESS_ID=$(php "$SCRIPT_DIR/helpers/find_account_access_rule.php" \
    --html "$TMP_DIR/account-before-access.html" \
    --source-user "$SKA_SMOKE_ACCESS_SOURCE_USER")
EXISTING_ACCESS_LOOKUP_RC=$?
set -e
if [ "$EXISTING_ACCESS_LOOKUP_RC" -eq 0 ]; then
    smoke_log "Existing access rule detected for '${SKA_SMOKE_ACCESS_SOURCE_USER}' (id=${EXISTING_ACCESS_ID}); removing it before add/remove smoke cycle"
    ACCOUNT_CSRF=$(smoke_extract_csrf "$TMP_DIR/account-before-access.html")
    [ -n "$ACCOUNT_CSRF" ] || smoke_die "Account page CSRF token not found before pre-clean delete"

    PRECLEAN_DELETE_HTTP_CODE=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
        --data-urlencode "csrf_token=$ACCOUNT_CSRF" \
        --data-urlencode "delete_access=$EXISTING_ACCESS_ID" \
        -o "$TMP_DIR/account-after-preclean-delete.html" \
        -w "%{http_code}" \
        "$BASE_URL$TARGET_PATH")
    require_post_status_ok "$PRECLEAN_DELETE_HTTP_CODE" "Pre-clean access delete request"
    fetch_account_page "$TMP_DIR/account-before-access.html"
fi

ACCOUNT_CSRF=$(smoke_extract_csrf "$TMP_DIR/account-before-access.html")
[ -n "$ACCOUNT_CSRF" ] || smoke_die "Account page CSRF token not found before access add"

ADD_ACCESS_HTTP_CODE=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    --data-urlencode "csrf_token=$ACCOUNT_CSRF" \
    --data-urlencode "username=$SKA_SMOKE_ACCESS_SOURCE_USER" \
    --data-urlencode "add_access=2" \
    -o "$TMP_DIR/account-after-access-add.html" \
    -w "%{http_code}" \
    "$BASE_URL$TARGET_PATH")
require_post_status_ok "$ADD_ACCESS_HTTP_CODE" "Access add request"

fetch_account_page "$TMP_DIR/account-after-access-add.html"

if ! ACCESS_ID=$(php "$SCRIPT_DIR/helpers/find_account_access_rule.php" \
    --html "$TMP_DIR/account-after-access-add.html" \
    --source-user "$SKA_SMOKE_ACCESS_SOURCE_USER"); then
    smoke_die "Added access rule was not found"
fi

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL$TARGET_PATH" -o "$TMP_DIR/account-before-access-delete.html"
ACCOUNT_DELETE_CSRF=$(smoke_extract_csrf "$TMP_DIR/account-before-access-delete.html")
[ -n "$ACCOUNT_DELETE_CSRF" ] || smoke_die "Account page CSRF token not found before access delete"

DELETE_ACCESS_HTTP_CODE=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    --data-urlencode "csrf_token=$ACCOUNT_DELETE_CSRF" \
    --data-urlencode "delete_access=$ACCESS_ID" \
    -o "$TMP_DIR/account-after-access-delete.html" \
    -w "%{http_code}" \
    "$BASE_URL$TARGET_PATH")
require_post_status_ok "$DELETE_ACCESS_HTTP_CODE" "Access delete request"

fetch_account_page "$TMP_DIR/account-after-access-delete.html"

if php "$SCRIPT_DIR/helpers/find_account_access_rule.php" \
    --html "$TMP_DIR/account-after-access-delete.html" \
    --source-user "$SKA_SMOKE_ACCESS_SOURCE_USER" >/dev/null 2>&1; then
    smoke_die "Deleted access rule is still present"
fi

smoke_log "Web workflows completed successfully"
