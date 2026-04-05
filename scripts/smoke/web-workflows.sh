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
LEGACY_FORM_GROUP_RE='class="form-group([[:space:]]|")|class="[^"]+[[:space:]]form-group([[:space:]]|")'
LEGACY_DROPDOWN_RE='class="dropdown([[:space:]]|")|class="[^"]+[[:space:]]dropdown([[:space:]]|")|class="dropdown-toggle([[:space:]]|")|class="[^"]+[[:space:]]dropdown-toggle([[:space:]]|")'
LEGACY_CARET_RE='class="caret([[:space:]]|")|class="[^"]+[[:space:]]caret([[:space:]]|")'
LEGACY_IN_CLASS_RE='class="in([[:space:]]|")|class="[^"]+[[:space:]]in([[:space:]]|")'

cleanup() {
    smoke_cleanup_dir "$TMP_DIR"
}
trap cleanup EXIT

smoke_log "Checking login page"
curl -fsS -L -c "$COOKIE_JAR" "$BASE_URL/login" -o "$TMP_DIR/login.html"
LOGIN_CSRF=$(smoke_extract_csrf "$TMP_DIR/login.html")
[ -n "$LOGIN_CSRF" ] || smoke_die "Login CSRF token not found"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap/css/bootstrap\.min\.css' "$TMP_DIR/login.html" || smoke_die "Login page still loads bootstrap.min.css"
grep -Eq 'class="[^"]*ska-form-control' "$TMP_DIR/login.html" || smoke_die "Login page is missing SKA-owned form-control classes"
grep -Eq 'class="[^"]*ska-form-label' "$TMP_DIR/login.html" || smoke_die "Login page is missing SKA-owned form-label classes"

smoke_log "Executing LDAP login flow"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    --data-urlencode "csrf_token=$LOGIN_CSRF" \
    --data-urlencode "username=$SKA_SMOKE_USERNAME" \
    --data-urlencode "password=$SKA_SMOKE_PASSWORD" \
    "$BASE_URL/login" -o "$TMP_DIR/post-login.html"

grep -q '>Logout<' "$TMP_DIR/post-login.html" || smoke_die "Login failed or did not reach authenticated UI"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap/css/bootstrap\.min\.css' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads bootstrap.min.css"
! grep -q '/bootstrap/js/bootstrap.min.js' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads bootstrap.min.js"
! grep -Eq '<script[^>]+src="[^"]*/jquery/jquery-3\.7\.1\.min\.js' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads jquery"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap5-compat\.css' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads bootstrap5-compat.css"
! grep -Eq '<script[^>]+src="[^"]*/bootstrap5-compat\.js' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads bootstrap5-compat.js"
! grep -q 'data-ska-skip-legacy' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still renders migration-only data-ska-skip-legacy markup"
! grep -Eq '\bsr-only\b|\bhidden-xl\b' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still renders Bootstrap 3 visibility helper classes"
! grep -Eq "$LEGACY_DROPDOWN_RE" "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still renders legacy dropdown helper classes"
grep -q 'data-bs-toggle="dropdown"' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell is missing Bootstrap 5 dropdown markup"

smoke_log "Adding and deleting a public key for logged-in user"
KEY_COMMENT="ska-smoke-$(date +%s)"
KEY_PATH="$TMP_DIR/smoke-key"
ssh-keygen -q -t ed25519 -N '' -C "$KEY_COMMENT" -f "$KEY_PATH" >/dev/null
PUBLIC_KEY=$(cat "$KEY_PATH.pub")

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/" -o "$TMP_DIR/home-before-key.html"
HOME_CSRF=$(smoke_extract_csrf "$TMP_DIR/home-before-key.html")
[ -n "$HOME_CSRF" ] || smoke_die "Home page CSRF token not found before key add"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap/css/bootstrap\.min\.css' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads bootstrap.min.css"
! grep -q '/bootstrap/js/bootstrap.min.js' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads bootstrap.min.js"
! grep -Eq '<script[^>]+src="[^"]*/jquery/jquery-3\.7\.1\.min\.js' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads jquery"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap5-compat\.css' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads bootstrap5-compat.css"
! grep -Eq '<script[^>]+src="[^"]*/bootstrap5-compat\.js' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads bootstrap5-compat.js"
! grep -q 'data-ska-skip-legacy' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders migration-only data-ska-skip-legacy markup"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders legacy glyphicon classes"
! grep -Eq 'class="[^"]*\\bhidden\\b' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders the legacy hidden helper class"
! grep -Eq 'class="[^"]*\\btable\\b|class="[^"]*\\btable-bordered\\b|class="[^"]*\\btable-striped\\b|class="[^"]*\\btable-hover\\b|class="[^"]*\\btable-sm\\b' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders legacy Bootstrap table classes"

TARGET_SERVER=$(smoke_urlencode "$SKA_SMOKE_ACCESS_SERVER_HOSTNAME")
TARGET_ACCOUNT=$(smoke_urlencode "$SKA_SMOKE_ACCESS_ACCOUNT_NAME")
TARGET_PATH="/servers/${TARGET_SERVER}/accounts/${TARGET_ACCOUNT}"

smoke_log "Checking help and groups pages for card migration regressions"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/help" -o "$TMP_DIR/help.html"
grep -q '>Logout<' "$TMP_DIR/help.html" || smoke_die "Authenticated session was lost while loading help page"
! grep -Eq 'panel-group|panel panel-default|panel-heading|panel-title|panel-body|panel-footer|panel-collapse' "$TMP_DIR/help.html" || smoke_die "Help page still renders legacy panel markup"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/help.html" || smoke_die "Help page still renders legacy glyphicon classes"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/help.html" || smoke_die "Help page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\balert\\b|class="[^"]*\\balert-info\\b|class="[^"]*\\balert-warning\\b|class="[^"]*\\balert-danger\\b|class="[^"]*\\balert-success\\b|class="[^"]*\\balert-link\\b' "$TMP_DIR/help.html" || smoke_die "Help page still renders Bootstrap-named alert classes"
grep -Eq 'class="[^"]*ska-alert[^"]*ska-alert-info' "$TMP_DIR/help.html" || smoke_die "Help page is missing SKA-owned alert classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/groups" -o "$TMP_DIR/groups.html"
grep -q '>Logout<' "$TMP_DIR/groups.html" || smoke_die "Authenticated session was lost while loading groups page"
! grep -Eq 'panel-group|panel panel-default|panel-heading|panel-title|panel-body|panel-footer|panel-collapse' "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy panel markup"
! grep -Eq "form-inline|form-horizontal|control-label|col-sm-offset-|help-block|<div class=\"checkbox|<div class=\"radio|\\bsr-only\\b|\\bhidden-xl\\b|$LEGACY_FORM_GROUP_RE" "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy Bootstrap 3 form helpers"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy glyphicon classes"
! grep -Eq 'class="[^"]*\\btable\\b|class="[^"]*\\btable-bordered\\b|class="[^"]*\\btable-striped\\b|class="[^"]*\\btable-hover\\b|class="[^"]*\\btable-sm\\b' "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy Bootstrap table classes"
! grep -Eq 'class="nav nav-tabs"|class="[^"]*\\bnav-item\\b|class="[^"]*\\bnav-link\\b|class="tab-content"|class="[^"]*\\btab-pane\\b' "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy Bootstrap tab classes"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\bform-control\\b' "$TMP_DIR/groups.html" || smoke_die "Groups page still renders Bootstrap-named form-control classes"
! grep -Eq 'class="[^"]*\\bbtn\\b|class="[^"]*\\bbtn-primary\\b|class="[^"]*\\bbtn-secondary\\b|class="[^"]*\\bbtn-success\\b|class="[^"]*\\bbtn-danger\\b|class="[^"]*\\bbtn-info\\b|class="[^"]*\\bbtn-sm\\b|class="[^"]*\\bbtn-lg\\b' "$TMP_DIR/groups.html" || smoke_die "Groups page still renders Bootstrap-named button classes"
grep -Eq 'class="[^"]*ska-form-control' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing SKA-owned form-control classes"
grep -Eq 'class="[^"]*ska-btn' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing SKA-owned button classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/servers" -o "$TMP_DIR/servers.html"
grep -q '>Logout<' "$TMP_DIR/servers.html" || smoke_die "Authenticated session was lost while loading servers page"
! grep -Eq "form-inline|form-horizontal|control-label|col-sm-offset-|help-block|<div class=\"checkbox|<div class=\"radio|\\bsr-only\\b|table-condensed|$LEGACY_FORM_GROUP_RE" "$TMP_DIR/servers.html" || smoke_die "Servers page still renders legacy Bootstrap 3 form helpers"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders legacy glyphicon classes"
! grep -Eq 'class="[^"]*\\bhidden\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders the legacy hidden helper class"
! grep -Eq 'class="[^"]*\\btable\\b|class="[^"]*\\btable-bordered\\b|class="[^"]*\\btable-striped\\b|class="[^"]*\\btable-hover\\b|class="[^"]*\\btable-sm\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders legacy Bootstrap table classes"
! grep -Eq 'class="nav nav-tabs"|class="[^"]*\\bnav-item\\b|class="[^"]*\\bnav-link\\b|class="tab-content"|class="[^"]*\\btab-pane\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders legacy Bootstrap tab classes"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/servers.html" || smoke_die "Servers page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\bform-check\\b|class="[^"]*\\bform-check-label\\b|class="[^"]*\\bform-check-input\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders Bootstrap-named form-check classes"
! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders Bootstrap-named inactive badge classes"
! grep -Eq 'class="[^"]*\\bform-control\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders Bootstrap-named form-control classes"
! grep -Eq 'class="[^"]*\\bbtn\\b|class="[^"]*\\bbtn-primary\\b|class="[^"]*\\bbtn-secondary\\b|class="[^"]*\\bbtn-success\\b|class="[^"]*\\bbtn-danger\\b|class="[^"]*\\bbtn-info\\b|class="[^"]*\\bbtn-sm\\b|class="[^"]*\\bbtn-lg\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders Bootstrap-named button classes"
! grep -Eq 'class="[^"]*\\balert\\b|class="[^"]*\\balert-info\\b|class="[^"]*\\balert-warning\\b|class="[^"]*\\balert-danger\\b|class="[^"]*\\balert-success\\b|class="[^"]*\\balert-link\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders Bootstrap-named alert classes"
grep -Eq 'class="[^"]*ska-form-check' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing SKA-owned form-check classes"
grep -Eq 'class="[^"]*ska-form-control' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing SKA-owned form-control classes"
grep -Eq 'class="[^"]*ska-btn' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing SKA-owned button classes"
grep -Eq 'class="[^"]*ska-alert[^"]*ska-alert-info' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing SKA-owned alert classes"

TARGET_SERVER_PAGE="/servers/${TARGET_SERVER}"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL$TARGET_SERVER_PAGE" -o "$TMP_DIR/server.html"
grep -q '>Logout<' "$TMP_DIR/server.html" || smoke_die "Authenticated session was lost while loading target server page"
! grep -Eq "form-inline|form-horizontal|control-label|col-sm-offset-|help-block|<div class=\"checkbox|<div class=\"radio|\\bsr-only\\b|$LEGACY_FORM_GROUP_RE" "$TMP_DIR/server.html" || smoke_die "Target server page still renders legacy Bootstrap 3 form helpers"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/server.html" || smoke_die "Target server page still renders legacy glyphicon classes"
! grep -Eq 'class="[^"]*\\bhidden\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders the legacy hidden helper class"
! grep -Eq 'class="[^"]*\\btable\\b|class="[^"]*\\btable-bordered\\b|class="[^"]*\\btable-striped\\b|class="[^"]*\\btable-hover\\b|class="[^"]*\\btable-sm\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders legacy Bootstrap table classes"
! grep -Eq 'class="nav nav-tabs"|class="[^"]*\\bnav-item\\b|class="[^"]*\\bnav-link\\b|class="tab-content"|class="[^"]*\\btab-pane\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders legacy Bootstrap tab classes"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/server.html" || smoke_die "Target server page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\binput-group\\b|class="[^"]*\\binput-group-text\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders Bootstrap-named input-group classes"
! grep -Eq 'class="[^"]*\\bform-check\\b|class="[^"]*\\bform-check-label\\b|class="[^"]*\\bform-check-input\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders Bootstrap-named form-check classes"
! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders Bootstrap-named inactive badge classes"
! grep -Eq 'class="[^"]*\\bform-control\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders Bootstrap-named form-control classes"
! grep -Eq 'class="[^"]*\\bbtn\\b|class="[^"]*\\bbtn-primary\\b|class="[^"]*\\bbtn-secondary\\b|class="[^"]*\\bbtn-success\\b|class="[^"]*\\bbtn-danger\\b|class="[^"]*\\bbtn-info\\b|class="[^"]*\\bbtn-sm\\b|class="[^"]*\\bbtn-lg\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders Bootstrap-named button classes"
grep -Eq 'class="[^"]*ska-form-control' "$TMP_DIR/server.html" || smoke_die "Target server page is missing SKA-owned form-control classes"
grep -Eq 'class="[^"]*ska-btn' "$TMP_DIR/server.html" || smoke_die "Target server page is missing SKA-owned button classes"

TARGET_USER=$(smoke_urlencode "$SKA_SMOKE_USERNAME")
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/users/${TARGET_USER}" -o "$TMP_DIR/user.html"
grep -q '>Logout<' "$TMP_DIR/user.html" || smoke_die "Authenticated session was lost while loading target user page"
! grep -Eq "form-inline|form-horizontal|control-label|col-sm-offset-|help-block|<div class=\"checkbox|<div class=\"radio|\\bsr-only\\b|$LEGACY_FORM_GROUP_RE" "$TMP_DIR/user.html" || smoke_die "Target user page still renders legacy Bootstrap 3 form helpers"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/user.html" || smoke_die "Target user page still renders legacy glyphicon classes"
! grep -Eq 'class="[^"]*\\btable\\b|class="[^"]*\\btable-bordered\\b|class="[^"]*\\btable-striped\\b|class="[^"]*\\btable-hover\\b|class="[^"]*\\btable-sm\\b' "$TMP_DIR/user.html" || smoke_die "Target user page still renders legacy Bootstrap table classes"
! grep -Eq 'class="nav nav-tabs"|class="[^"]*\\bnav-item\\b|class="[^"]*\\bnav-link\\b|class="tab-content"|class="[^"]*\\btab-pane\\b' "$TMP_DIR/user.html" || smoke_die "Target user page still renders legacy Bootstrap tab classes"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/user.html" || smoke_die "Target user page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$TMP_DIR/user.html" || smoke_die "Target user page still renders Bootstrap-named inactive badge classes"
! grep -Eq 'class="[^"]*\\bbtn\\b|class="[^"]*\\bbtn-primary\\b|class="[^"]*\\bbtn-secondary\\b|class="[^"]*\\bbtn-success\\b|class="[^"]*\\bbtn-danger\\b|class="[^"]*\\bbtn-info\\b|class="[^"]*\\bbtn-sm\\b|class="[^"]*\\bbtn-lg\\b' "$TMP_DIR/user.html" || smoke_die "Target user page still renders Bootstrap-named button classes"
grep -Eq 'class="[^"]*ska-btn' "$TMP_DIR/user.html" || smoke_die "Target user page is missing SKA-owned button classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/bulk_mail/server_admins" -o "$TMP_DIR/bulk-mail.html"
grep -q '>Logout<' "$TMP_DIR/bulk-mail.html" || smoke_die "Authenticated session was lost while loading bulk mail page"
grep -Eq 'class="[^"]*ska-alert[^"]*ska-alert-warning' "$TMP_DIR/bulk-mail.html" || smoke_die "Bulk mail page is missing SKA-owned alert classes"
grep -Eq 'class="[^"]*ska-form-control' "$TMP_DIR/bulk-mail.html" || smoke_die "Bulk mail page is missing SKA-owned form-control classes"
grep -Eq 'class="[^"]*ska-btn[^"]*ska-btn-primary' "$TMP_DIR/bulk-mail.html" || smoke_die "Bulk mail page is missing SKA-owned button classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/pubkeys" -o "$TMP_DIR/pubkeys.html"
grep -q '>Logout<' "$TMP_DIR/pubkeys.html" || smoke_die "Authenticated session was lost while loading public keys page"
! grep -Eq 'class="[^"]*\\bform-control\\b' "$TMP_DIR/pubkeys.html" || smoke_die "Public keys page still renders Bootstrap-named form-control classes"
grep -Eq 'class="[^"]*ska-form-control' "$TMP_DIR/pubkeys.html" || smoke_die "Public keys page is missing SKA-owned form-control classes"

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
    ! grep -Eq "$LEGACY_FORM_GROUP_RE" "$output_file" || smoke_die "Target account page still renders legacy form-group markup"
    ! grep -Eq 'glyphicon-[a-z0-9-]+' "$output_file" || smoke_die "Target account page still renders legacy glyphicon classes"
    ! grep -Eq 'class="[^"]*\\btable\\b|class="[^"]*\\btable-bordered\\b|class="[^"]*\\btable-striped\\b|class="[^"]*\\btable-hover\\b|class="[^"]*\\btable-sm\\b' "$output_file" || smoke_die "Target account page still renders legacy Bootstrap table classes"
    ! grep -Eq 'class="nav nav-tabs"|class="[^"]*\\bnav-item\\b|class="[^"]*\\bnav-link\\b|class="tab-content"|class="[^"]*\\btab-pane\\b' "$output_file" || smoke_die "Target account page still renders legacy Bootstrap tab classes"
    ! grep -Eq "$LEGACY_IN_CLASS_RE" "$output_file" || smoke_die "Target account page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\binput-group\\b|class="[^"]*\\binput-group-text\\b' "$output_file" || smoke_die "Target account page still renders Bootstrap-named input-group classes"
    ! grep -Eq 'class="[^"]*\\bform-check\\b|class="[^"]*\\bform-check-label\\b|class="[^"]*\\bform-check-input\\b' "$output_file" || smoke_die "Target account page still renders Bootstrap-named form-check classes"
    ! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$output_file" || smoke_die "Target account page still renders Bootstrap-named inactive badge classes"
    ! grep -Eq 'class="[^"]*\\bform-control\\b' "$output_file" || smoke_die "Target account page still renders Bootstrap-named form-control classes"
    ! grep -Eq 'class="[^"]*\\bbtn\\b|class="[^"]*\\bbtn-primary\\b|class="[^"]*\\bbtn-secondary\\b|class="[^"]*\\bbtn-success\\b|class="[^"]*\\bbtn-danger\\b|class="[^"]*\\bbtn-info\\b|class="[^"]*\\bbtn-sm\\b|class="[^"]*\\bbtn-lg\\b' "$output_file" || smoke_die "Target account page still renders Bootstrap-named button classes"
    ! grep -Eq 'class="[^"]*\\balert\\b|class="[^"]*\\balert-info\\b|class="[^"]*\\balert-warning\\b|class="[^"]*\\balert-danger\\b|class="[^"]*\\balert-success\\b|class="[^"]*\\balert-link\\b' "$output_file" || smoke_die "Target account page still renders Bootstrap-named alert classes"
    grep -Eq 'class="[^"]*ska-form-control' "$output_file" || smoke_die "Target account page is missing SKA-owned form-control classes"
    grep -Eq 'class="[^"]*ska-btn' "$output_file" || smoke_die "Target account page is missing SKA-owned button classes"
    grep -Eq 'class="[^"]*ska-alert[^"]*ska-alert-info' "$output_file" || smoke_die "Target account page is missing SKA-owned alert classes"
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

ACCESS_OPTIONS_PATH="${TARGET_PATH}/access_rules/${ACCESS_ID}"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL$ACCESS_OPTIONS_PATH" -o "$TMP_DIR/access-options.html"
grep -q '>Logout<' "$TMP_DIR/access-options.html" || smoke_die "Authenticated session was lost while loading access options page"
! grep -Eq "$LEGACY_FORM_GROUP_RE" "$TMP_DIR/access-options.html" || smoke_die "Access options page still renders legacy form-group markup"
! grep -Eq "$LEGACY_CARET_RE" "$TMP_DIR/access-options.html" || smoke_die "Access options page still renders legacy caret markup"
! grep -Eq 'class="[^"]*\\bform-check\\b|class="[^"]*\\bform-check-label\\b|class="[^"]*\\bform-check-input\\b' "$TMP_DIR/access-options.html" || smoke_die "Access options page still renders Bootstrap-named form-check classes"
! grep -Eq 'class="[^"]*\\bform-control\\b' "$TMP_DIR/access-options.html" || smoke_die "Access options page still renders Bootstrap-named form-control classes"
! grep -Eq 'class="[^"]*\\bbtn\\b|class="[^"]*\\bbtn-primary\\b|class="[^"]*\\bbtn-secondary\\b|class="[^"]*\\bbtn-success\\b|class="[^"]*\\bbtn-danger\\b|class="[^"]*\\bbtn-info\\b|class="[^"]*\\bbtn-sm\\b|class="[^"]*\\bbtn-lg\\b' "$TMP_DIR/access-options.html" || smoke_die "Access options page still renders Bootstrap-named button classes"
grep -Eq 'class="[^"]*ska-form-check' "$TMP_DIR/access-options.html" || smoke_die "Access options page is missing SKA-owned form-check classes"
grep -Eq 'class="[^"]*ska-form-control' "$TMP_DIR/access-options.html" || smoke_die "Access options page is missing SKA-owned form-control classes"
grep -Eq 'class="[^"]*ska-btn' "$TMP_DIR/access-options.html" || smoke_die "Access options page is missing SKA-owned button classes"

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
