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
LEGACY_LAYOUT_TOKENS='container|row|col-sm-[0-9]+|col-md-[0-9]+|text-muted|w-100|mb-0|mb-1|mb-3|mb-4|mt-3|d-none|float-end|invisible'
LEGACY_LAYOUT_RE="class=\"(${LEGACY_LAYOUT_TOKENS})([[:space:]]|\")|class=\"[^\"]+[[:space:]](${LEGACY_LAYOUT_TOKENS})([[:space:]]|\")"
LEGACY_PRESENTATION_TOKENS='text-muted|text-success|text-warning|text-danger|text-info|d-xl-none|h-50px'
LEGACY_PRESENTATION_RE="class=\"(${LEGACY_PRESENTATION_TOKENS})([[:space:]]|\")|class=\"[^\"]+[[:space:]](${LEGACY_PRESENTATION_TOKENS})([[:space:]]|\")"
RETIRED_SKA_GENERIC_RE='ska-(form-control|form-label|btn|tabs|tab-content|tab-pane|row|col-(sm|md)-[0-9]+|d-none|w-100|form-check|table)'

cleanup() {
    smoke_cleanup_dir "$TMP_DIR"
}
trap cleanup EXIT

smoke_log "Checking login page"
curl -fsS -L -c "$COOKIE_JAR" "$BASE_URL/login" -o "$TMP_DIR/login.html"
LOGIN_CSRF=$(smoke_extract_csrf "$TMP_DIR/login.html")
[ -n "$LOGIN_CSRF" ] || smoke_die "Login CSRF token not found"
grep -Eq '<link[^>]+href="[^"]*/vendor/bootstrap5/bootstrap-5\.3\.8\.min\.css' "$TMP_DIR/login.html" || smoke_die "Login page is missing the vendored Bootstrap 5 CSS"
grep -Eq '<script[^>]+src="[^"]*/vendor/bootstrap5/bootstrap-5\.3\.8\.bundle\.min\.js' "$TMP_DIR/login.html" || smoke_die "Login page is missing the vendored Bootstrap 5 bundle"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap/css/bootstrap\.min\.css' "$TMP_DIR/login.html" || smoke_die "Login page still loads bootstrap.min.css"
! grep -Eq "$RETIRED_SKA_GENERIC_RE" "$TMP_DIR/login.html" || smoke_die "Login page still renders retired SKA generic classes"
! grep -Eq "$LEGACY_PRESENTATION_RE" "$TMP_DIR/login.html" || smoke_die "Login page still renders Bootstrap-named semantic helper classes"
grep -Eq 'class="[^"]*form-label' "$TMP_DIR/login.html" || smoke_die "Login page is missing Bootstrap 5 form-label classes"
grep -Eq 'class="[^"]*form-control' "$TMP_DIR/login.html" || smoke_die "Login page is missing Bootstrap 5 form-control classes"
grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/login.html" || smoke_die "Login page is missing Bootstrap 5 button classes"

smoke_log "Executing LDAP login flow"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    --data-urlencode "csrf_token=$LOGIN_CSRF" \
    --data-urlencode "username=$SKA_SMOKE_USERNAME" \
    --data-urlencode "password=$SKA_SMOKE_PASSWORD" \
    "$BASE_URL/login" -o "$TMP_DIR/post-login.html"

grep -q '>Logout<' "$TMP_DIR/post-login.html" || smoke_die "Login failed or did not reach authenticated UI"
grep -Eq '<link[^>]+href="[^"]*/vendor/bootstrap5/bootstrap-5\.3\.8\.min\.css' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell is missing the vendored Bootstrap 5 CSS"
grep -Eq '<script[^>]+src="[^"]*/vendor/bootstrap5/bootstrap-5\.3\.8\.bundle\.min\.js' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell is missing the vendored Bootstrap 5 bundle"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap/css/bootstrap\.min\.css' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads bootstrap.min.css"
! grep -q '/bootstrap/js/bootstrap.min.js' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads bootstrap.min.js"
! grep -Eq '<script[^>]+src="[^"]*/jquery/jquery-3\.7\.1\.min\.js' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads jquery"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap5-compat\.css' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads bootstrap5-compat.css"
! grep -Eq '<script[^>]+src="[^"]*/bootstrap5-compat\.js' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still loads bootstrap5-compat.js"
! grep -q 'data-ska-skip-legacy' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still renders migration-only data-ska-skip-legacy markup"
! grep -Eq '\bsr-only\b|\bhidden-xl\b' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still renders Bootstrap 3 visibility helper classes"
grep -q 'data-bs-toggle="dropdown"' "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell is missing Bootstrap 5 dropdown markup"
! grep -Eq "$LEGACY_PRESENTATION_RE" "$TMP_DIR/post-login.html" || smoke_die "Authenticated shell still renders Bootstrap-named semantic helper classes"

smoke_log "Adding and deleting a public key for logged-in user"
KEY_COMMENT="ska-smoke-$(date +%s)-$$"
KEY_PATH="$TMP_DIR/smoke-key"
ssh-keygen -q -t ed25519 -N '' -C "$KEY_COMMENT" -f "$KEY_PATH" >/dev/null
PUBLIC_KEY=$(cat "$KEY_PATH.pub")

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/" -o "$TMP_DIR/home-before-key.html"
HOME_CSRF=$(smoke_extract_csrf "$TMP_DIR/home-before-key.html")
[ -n "$HOME_CSRF" ] || smoke_die "Home page CSRF token not found before key add"
grep -Eq '<link[^>]+href="[^"]*/vendor/bootstrap5/bootstrap-5\.3\.8\.min\.css' "$TMP_DIR/home-before-key.html" || smoke_die "Home page is missing the vendored Bootstrap 5 CSS"
grep -Eq '<script[^>]+src="[^"]*/vendor/bootstrap5/bootstrap-5\.3\.8\.bundle\.min\.js' "$TMP_DIR/home-before-key.html" || smoke_die "Home page is missing the vendored Bootstrap 5 bundle"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap/css/bootstrap\.min\.css' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads bootstrap.min.css"
! grep -q '/bootstrap/js/bootstrap.min.js' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads bootstrap.min.js"
! grep -Eq '<script[^>]+src="[^"]*/jquery/jquery-3\.7\.1\.min\.js' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads jquery"
! grep -Eq '<link[^>]+href="[^"]*/bootstrap5-compat\.css' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads bootstrap5-compat.css"
! grep -Eq '<script[^>]+src="[^"]*/bootstrap5-compat\.js' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still loads bootstrap5-compat.js"
! grep -q 'data-ska-skip-legacy' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders migration-only data-ska-skip-legacy markup"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders legacy glyphicon classes"
! grep -Eq 'class="[^"]*\\bhidden\\b' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders the legacy hidden helper class"
! grep -Eq "$RETIRED_SKA_GENERIC_RE" "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders retired SKA generic classes"
! grep -Eq "$LEGACY_PRESENTATION_RE" "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders Bootstrap-named semantic helper classes"
! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$TMP_DIR/home-before-key.html" || smoke_die "Home page still renders Bootstrap-named inactive badge classes"
grep -Eq 'class="[^"]*table[^"]*table-striped' "$TMP_DIR/home-before-key.html" || smoke_die "Home page is missing Bootstrap 5 table classes"
grep -Eq 'class="[^"]*form-control' "$TMP_DIR/home-before-key.html" || smoke_die "Home page is missing Bootstrap 5 form-control classes"
grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/home-before-key.html" || smoke_die "Home page is missing Bootstrap 5 primary button classes"
grep -Eq 'class="[^"]*btn[^"]*btn-secondary' "$TMP_DIR/home-before-key.html" || smoke_die "Home page is missing Bootstrap 5 secondary button classes"

TARGET_SERVER=$(smoke_urlencode "$SKA_SMOKE_ACCESS_SERVER_HOSTNAME")
TARGET_ACCOUNT=$(smoke_urlencode "$SKA_SMOKE_ACCESS_ACCOUNT_NAME")
TARGET_PATH="/servers/${TARGET_SERVER}/accounts/${TARGET_ACCOUNT}"

smoke_log "Checking help and groups pages for migration regressions"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/help" -o "$TMP_DIR/help.html"
grep -q '>Logout<' "$TMP_DIR/help.html" || smoke_die "Authenticated session was lost while loading help page"
! grep -Eq 'panel-group|panel panel-default|panel-heading|panel-title|panel-body|panel-footer|panel-collapse' "$TMP_DIR/help.html" || smoke_die "Help page still renders legacy panel markup"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/help.html" || smoke_die "Help page still renders legacy glyphicon classes"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/help.html" || smoke_die "Help page still renders legacy collapse/tab state classes"
grep -Eq 'class="[^"]*accordion' "$TMP_DIR/help.html" || smoke_die "Help page is missing Bootstrap 5 accordion classes"
grep -Eq 'class="[^"]*alert[^"]*alert-info' "$TMP_DIR/help.html" || smoke_die "Help page is missing Bootstrap 5 alert classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/groups" -o "$TMP_DIR/groups.html"
grep -q '>Logout<' "$TMP_DIR/groups.html" || smoke_die "Authenticated session was lost while loading groups page"
! grep -Eq 'panel-group|panel panel-default|panel-heading|panel-title|panel-body|panel-footer|panel-collapse' "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy panel markup"
! grep -Eq "form-inline|form-horizontal|control-label|col-sm-offset-|help-block|<div class=\"checkbox|<div class=\"radio|\\bsr-only\\b|\\bhidden-xl\\b|$LEGACY_FORM_GROUP_RE" "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy Bootstrap 3 form helpers"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy glyphicon classes"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/groups.html" || smoke_die "Groups page still renders legacy collapse/tab state classes"
! grep -Eq "$LEGACY_PRESENTATION_RE" "$TMP_DIR/groups.html" || smoke_die "Groups page still renders Bootstrap-named semantic helper classes"
grep -Eq 'class="[^"]*nav[^"]*nav-tabs' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 nav-tab classes"
grep -Eq 'class="[^"]*tab-content' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 tab-content classes"
grep -Eq 'class="[^"]*card' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 card classes"
grep -Eq 'class="[^"]*row' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 row classes"
grep -Eq 'class="[^"]*col-sm-[0-9]+' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 column classes"
grep -Eq 'class="[^"]*table[^"]*table-striped' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 table classes"
grep -Eq 'class="[^"]*form-control' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 form-control classes"
grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 primary button classes"
grep -Eq 'class="[^"]*btn[^"]*btn-secondary' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 secondary button classes"
grep -Eq 'class="[^"]*form-check' "$TMP_DIR/groups.html" || smoke_die "Groups page is missing Bootstrap 5 form-check classes"

TARGET_GROUP_PATH=$(grep -Eo '/groups/[^"#?]+' "$TMP_DIR/groups.html" | head -n1 || true)
[ -n "$TARGET_GROUP_PATH" ] || smoke_die "Could not find a group detail link on groups page"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL$TARGET_GROUP_PATH" -o "$TMP_DIR/group-detail.html"
grep -q '>Logout<' "$TMP_DIR/group-detail.html" || smoke_die "Authenticated session was lost while loading target group page"
! grep -Eq 'panel-group|panel panel-default|panel-heading|panel-title|panel-body|panel-footer|panel-collapse' "$TMP_DIR/group-detail.html" || smoke_die "Target group page still renders legacy panel markup"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/group-detail.html" || smoke_die "Target group page still renders legacy glyphicon classes"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/group-detail.html" || smoke_die "Target group page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\btext-center\\b|class="[^"]*\\btext-muted\\b|class="[^"]*\\btext-success\\b|class="[^"]*\\btext-warning\\b|class="[^"]*\\btext-danger\\b|class="[^"]*\\btext-info\\b|class="[^"]*\\brounded\\b|class="[^"]*\\bimg-fluid\\b|class="[^"]*\\bclearfix\\b|class="[^"]*\\bd-xl-none\\b|class="[^"]*\\bh-50px\\b' "$TMP_DIR/group-detail.html" || smoke_die "Target group page still renders Bootstrap-named semantic helper classes"
! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$TMP_DIR/group-detail.html" || smoke_die "Target group page still renders Bootstrap-named inactive badge classes"
grep -Eq 'class="[^"]*nav[^"]*nav-tabs' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 nav-tab classes"
grep -Eq 'class="[^"]*tab-content' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 tab-content classes"
grep -Eq 'class="[^"]*row' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 row classes"
grep -Eq 'class="[^"]*col-md-[0-9]+' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 column classes"
grep -Eq 'class="[^"]*table' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 table classes"
grep -Eq 'class="[^"]*btn[^"]*btn-secondary' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 secondary button classes"
if grep -Eq 'name="add_member"|name="add_members"|name="add_access"|name="add_admin"|name="edit_group"' "$TMP_DIR/group-detail.html"; then
    grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 primary button classes"
    grep -Eq 'class="[^"]*form-control' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 form-control classes"
fi
if grep -Eq 'name="add_member"|name="add_members"|name="add_access"' "$TMP_DIR/group-detail.html"; then
    grep -Eq 'class="[^"]*input-group' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 input-group classes"
fi
if grep -Eq 'name="edit_group"' "$TMP_DIR/group-detail.html"; then
    grep -Eq 'class="[^"]*form-check' "$TMP_DIR/group-detail.html" || smoke_die "Target group page is missing Bootstrap 5 form-check classes"
fi

smoke_log "Checking secondary pages for Bootstrap 5 class handoff regressions"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/activity" -o "$TMP_DIR/activity.html"
grep -q '>Logout<' "$TMP_DIR/activity.html" || smoke_die "Authenticated session was lost while loading activity page"
grep -Eq 'class="[^"]*table[^"]*table-sm' "$TMP_DIR/activity.html" || smoke_die "Activity page is missing Bootstrap 5 table classes"
grep -Eq 'class="[^"]*card' "$TMP_DIR/activity.html" || smoke_die "Activity page is missing Bootstrap 5 card classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/report" -o "$TMP_DIR/report.html"
grep -q '>Logout<' "$TMP_DIR/report.html" || smoke_die "Authenticated session was lost while loading report page"
grep -Eq 'class="[^"]*table[^"]*table-bordered' "$TMP_DIR/report.html" || smoke_die "Report page is missing Bootstrap 5 table classes"
grep -Eq 'class="[^"]*card' "$TMP_DIR/report.html" || smoke_die "Report page is missing Bootstrap 5 card classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/bulk_mail" -o "$TMP_DIR/bulk-mail.html"
grep -q '>Logout<' "$TMP_DIR/bulk-mail.html" || smoke_die "Authenticated session was lost while loading bulk mail chooser"
grep -Eq 'class="[^"]*card' "$TMP_DIR/bulk-mail.html" || smoke_die "Bulk mail chooser is missing Bootstrap 5 card classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/bulk_mail/all_users" -o "$TMP_DIR/bulk-mail-form.html"
grep -q '>Logout<' "$TMP_DIR/bulk-mail-form.html" || smoke_die "Authenticated session was lost while loading bulk mail form"
grep -Eq 'class="[^"]*alert[^"]*alert-warning' "$TMP_DIR/bulk-mail-form.html" || smoke_die "Bulk mail form is missing Bootstrap 5 alert classes"
grep -Eq 'class="[^"]*form-label' "$TMP_DIR/bulk-mail-form.html" || smoke_die "Bulk mail form is missing Bootstrap 5 form-label classes"
grep -Eq 'class="[^"]*form-control' "$TMP_DIR/bulk-mail-form.html" || smoke_die "Bulk mail form is missing Bootstrap 5 form-control classes"
grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/bulk-mail-form.html" || smoke_die "Bulk mail form is missing Bootstrap 5 button classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/help#getting_started" -o "$TMP_DIR/functions.html"
grep -q '>Logout<' "$TMP_DIR/functions.html" || smoke_die "Authenticated session was lost while loading keygen help tabs"
grep -Eq 'class="[^"]*nav[^"]*nav-tabs' "$TMP_DIR/functions.html" || smoke_die "Keygen help tabs are missing Bootstrap 5 nav-tab classes"
grep -Eq 'class="[^"]*tab-content' "$TMP_DIR/functions.html" || smoke_die "Keygen help tabs are missing Bootstrap 5 tab-content classes"
grep -Eq 'class="[^"]*alert[^"]*alert-info' "$TMP_DIR/functions.html" || smoke_die "Keygen help tabs are missing Bootstrap 5 alert classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/servers" -o "$TMP_DIR/servers.html"
grep -q '>Logout<' "$TMP_DIR/servers.html" || smoke_die "Authenticated session was lost while loading servers page"
! grep -Eq "form-inline|form-horizontal|control-label|col-sm-offset-|help-block|<div class=\"checkbox|<div class=\"radio|\\bsr-only\\b|table-condensed|$LEGACY_FORM_GROUP_RE" "$TMP_DIR/servers.html" || smoke_die "Servers page still renders legacy Bootstrap 3 form helpers"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders legacy glyphicon classes"
! grep -Eq 'class="[^"]*\\bhidden\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders the legacy hidden helper class"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/servers.html" || smoke_die "Servers page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\btext-center\\b|class="[^"]*\\btext-muted\\b|class="[^"]*\\btext-success\\b|class="[^"]*\\btext-warning\\b|class="[^"]*\\btext-danger\\b|class="[^"]*\\btext-info\\b|class="[^"]*\\brounded\\b|class="[^"]*\\bimg-fluid\\b|class="[^"]*\\bclearfix\\b|class="[^"]*\\bd-xl-none\\b|class="[^"]*\\bh-50px\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders Bootstrap-named semantic helper classes"
! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$TMP_DIR/servers.html" || smoke_die "Servers page still renders Bootstrap-named inactive badge classes"
grep -Eq 'class="[^"]*nav[^"]*nav-tabs' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 nav-tab classes"
grep -Eq 'class="[^"]*tab-content' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 tab-content classes"
grep -Eq 'class="[^"]*card' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 card classes"
grep -Eq 'class="[^"]*row' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 row classes"
grep -Eq 'class="[^"]*col-sm-[0-9]+' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 column classes"
grep -Eq 'class="[^"]*table[^"]*table-hover[^"]*table-sm|class="[^"]*table[^"]*table-sm[^"]*table-hover' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 table classes"
grep -Eq 'class="[^"]*form-check' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 form-check classes"
grep -Eq 'class="[^"]*form-control' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 form-control classes"
grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 primary button classes"
grep -Eq 'class="[^"]*btn[^"]*btn-secondary' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 secondary button classes"
grep -Eq 'class="[^"]*alert[^"]*alert-info' "$TMP_DIR/servers.html" || smoke_die "Servers page is missing Bootstrap 5 alert classes"

TARGET_SERVER_PAGE="/servers/${TARGET_SERVER}"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL$TARGET_SERVER_PAGE" -o "$TMP_DIR/server.html"
grep -q '>Logout<' "$TMP_DIR/server.html" || smoke_die "Authenticated session was lost while loading target server page"
! grep -Eq "form-inline|form-horizontal|control-label|col-sm-offset-|help-block|<div class=\"checkbox|<div class=\"radio|\\bsr-only\\b|$LEGACY_FORM_GROUP_RE" "$TMP_DIR/server.html" || smoke_die "Target server page still renders legacy Bootstrap 3 form helpers"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/server.html" || smoke_die "Target server page still renders legacy glyphicon classes"
! grep -Eq 'class="[^"]*\\bhidden\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders the legacy hidden helper class"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/server.html" || smoke_die "Target server page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\btext-center\\b|class="[^"]*\\btext-muted\\b|class="[^"]*\\btext-success\\b|class="[^"]*\\btext-warning\\b|class="[^"]*\\btext-danger\\b|class="[^"]*\\btext-info\\b|class="[^"]*\\brounded\\b|class="[^"]*\\bimg-fluid\\b|class="[^"]*\\bclearfix\\b|class="[^"]*\\bd-xl-none\\b|class="[^"]*\\bh-50px\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders Bootstrap-named semantic helper classes"
! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$TMP_DIR/server.html" || smoke_die "Target server page still renders Bootstrap-named inactive badge classes"
if grep -Eq 'id="server_accounts_tab"|id="server_admins_tab"|id="server_settings_tab"' "$TMP_DIR/server.html"; then
    grep -Eq 'class="[^"]*nav[^"]*nav-tabs' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 nav-tab classes"
    grep -Eq 'class="[^"]*tab-content' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 tab-content classes"
fi
if grep -Eq '<table[^>]+class="[^"]*' "$TMP_DIR/server.html"; then
    grep -Eq 'class="[^"]*table' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 table classes"
fi
if grep -Eq 'name="add_account"|name="add_admin"|name="edit_server"|name="request_access"|name="send_mail"|name="sync"' "$TMP_DIR/server.html"; then
    grep -Eq 'class="[^"]*form-control' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 form-control classes"
    grep -Eq 'class="[^"]*btn[^"]*btn-primary|class="[^"]*btn[^"]*btn-secondary|class="[^"]*btn[^"]*btn-info' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 button classes"
fi
if grep -Eq 'name="request_access"' "$TMP_DIR/server.html"; then
    grep -Eq 'class="[^"]*row' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 row classes"
    grep -Eq 'class="[^"]*col-sm-[0-9]+' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 column classes"
    grep -Eq 'class="[^"]*input-group' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 input-group classes"
fi
if grep -Eq 'name="add_note"|name="send_mail"' "$TMP_DIR/server.html"; then
    grep -Eq 'class="[^"]*w-100' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 width helpers on primary actions"
fi
if grep -Eq 'name="key_management"|name="key_scan"|name="authorization"|name="access_option\[' "$TMP_DIR/server.html"; then
    grep -Eq 'class="[^"]*form-check' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 form-check classes"
fi
if grep -Eq 'same IP address|same SSH host key|does not have any leaders assigned|Failed to supervise external keys' "$TMP_DIR/server.html"; then
    grep -Eq 'class="[^"]*alert[^"]*alert-danger' "$TMP_DIR/server.html" || smoke_die "Target server page is missing Bootstrap 5 alert classes"
fi

TARGET_USER=$(smoke_urlencode "$SKA_SMOKE_USERNAME")
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/users" -o "$TMP_DIR/users.html"
grep -q '>Logout<' "$TMP_DIR/users.html" || smoke_die "Authenticated session was lost while loading users page"
! grep -Eq "$LEGACY_PRESENTATION_RE" "$TMP_DIR/users.html" || smoke_die "Users page still renders Bootstrap-named semantic helper classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/users/${TARGET_USER}" -o "$TMP_DIR/user.html"
grep -q '>Logout<' "$TMP_DIR/user.html" || smoke_die "Authenticated session was lost while loading target user page"
! grep -Eq "form-inline|form-horizontal|control-label|col-sm-offset-|help-block|<div class=\"checkbox|<div class=\"radio|\\bsr-only\\b|$LEGACY_FORM_GROUP_RE" "$TMP_DIR/user.html" || smoke_die "Target user page still renders legacy Bootstrap 3 form helpers"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/user.html" || smoke_die "Target user page still renders legacy glyphicon classes"
! grep -Eq "$RETIRED_SKA_GENERIC_RE" "$TMP_DIR/user.html" || smoke_die "Target user page still renders retired SKA generic classes"
! grep -Eq "$LEGACY_IN_CLASS_RE" "$TMP_DIR/user.html" || smoke_die "Target user page still renders legacy collapse/tab state classes"
! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$TMP_DIR/user.html" || smoke_die "Target user page still renders Bootstrap-named inactive badge classes"
! grep -Eq "$LEGACY_PRESENTATION_RE" "$TMP_DIR/user.html" || smoke_die "Target user page still renders Bootstrap-named semantic helper classes"
grep -Eq 'class="[^"]*nav[^"]*nav-tabs' "$TMP_DIR/user.html" || smoke_die "Target user page is missing Bootstrap 5 nav-tab classes"
grep -Eq 'class="[^"]*tab-content' "$TMP_DIR/user.html" || smoke_die "Target user page is missing Bootstrap 5 tab-content classes"
grep -Eq 'class="[^"]*table[^"]*table-striped' "$TMP_DIR/user.html" || smoke_die "Target user page is missing Bootstrap 5 table classes"
grep -Eq 'class="[^"]*btn[^"]*btn-secondary' "$TMP_DIR/user.html" || smoke_die "Target user page is missing Bootstrap 5 secondary button classes"
if grep -Eq 'name="edit_user"' "$TMP_DIR/user.html"; then
    grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/user.html" || smoke_die "Target user page is missing Bootstrap 5 primary button classes"
    grep -Eq 'class="[^"]*form-check-input' "$TMP_DIR/user.html" || smoke_die "Target user page is missing Bootstrap 5 form-check inputs"
fi

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/bulk_mail/server_admins" -o "$TMP_DIR/bulk-mail.html"
grep -q '>Logout<' "$TMP_DIR/bulk-mail.html" || smoke_die "Authenticated session was lost while loading bulk mail page"
grep -Eq 'class="[^"]*alert[^"]*alert-warning' "$TMP_DIR/bulk-mail.html" || smoke_die "Bulk mail page is missing Bootstrap 5 alert classes"
grep -Eq 'class="[^"]*form-label' "$TMP_DIR/bulk-mail.html" || smoke_die "Bulk mail page is missing Bootstrap 5 form-label classes"
grep -Eq 'class="[^"]*form-control' "$TMP_DIR/bulk-mail.html" || smoke_die "Bulk mail page is missing Bootstrap 5 form-control classes"
grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/bulk-mail.html" || smoke_die "Bulk mail page is missing Bootstrap 5 button classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/pubkeys" -o "$TMP_DIR/pubkeys.html"
grep -q '>Logout<' "$TMP_DIR/pubkeys.html" || smoke_die "Authenticated session was lost while loading public keys page"
! grep -Eq "$LEGACY_PRESENTATION_RE" "$TMP_DIR/pubkeys.html" || smoke_die "Public keys page still renders Bootstrap-named semantic helper classes"
! grep -Eq 'class="[^"]*\\bhidden\\b' "$TMP_DIR/pubkeys.html" || smoke_die "Public keys page still renders the legacy hidden helper class"
! grep -Eq 'glyphicon-[a-z0-9-]+' "$TMP_DIR/pubkeys.html" || smoke_die "Public keys page still renders legacy glyphicon classes"
grep -Eq 'class="[^"]*nav[^"]*nav-tabs' "$TMP_DIR/pubkeys.html" || smoke_die "Public keys page is missing Bootstrap 5 nav-tab classes"
grep -Eq 'class="[^"]*tab-content' "$TMP_DIR/pubkeys.html" || smoke_die "Public keys page is missing Bootstrap 5 tab-content classes"
grep -Eq 'class="[^"]*form-control' "$TMP_DIR/pubkeys.html" || smoke_die "Public keys page is missing Bootstrap 5 form-control classes"
grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/pubkeys.html" || smoke_die "Public keys page is missing Bootstrap 5 primary button classes"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    --data-urlencode "csrf_token=$HOME_CSRF" \
    --data-urlencode "add_public_key=$PUBLIC_KEY" \
    "$BASE_URL/" -o "$TMP_DIR/home-after-key-add.html"

if ! KEY_ID=$(php "$SCRIPT_DIR/helpers/find_user_public_key.php" \
    --html "$TMP_DIR/home-after-key-add.html" \
    --comment "$KEY_COMMENT"); then
    smoke_die "Added key was not found in active key set"
fi

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/users/${TARGET_USER}/pubkeys" -o "$TMP_DIR/user-pubkeys.html"
grep -q '>Logout<' "$TMP_DIR/user-pubkeys.html" || smoke_die "Authenticated session was lost while loading user public keys page"
grep -Eq 'class="[^"]*card' "$TMP_DIR/user-pubkeys.html" || smoke_die "User public keys page is missing Bootstrap 5 card classes"
grep -Eq 'class="[^"]*btn[^"]*btn-secondary' "$TMP_DIR/user-pubkeys.html" || smoke_die "User public keys page is missing Bootstrap 5 secondary button classes"
if grep -q 'name="add_public_key"' "$TMP_DIR/user-pubkeys.html"; then
    grep -Eq 'class="[^"]*form-control' "$TMP_DIR/user-pubkeys.html" || smoke_die "User public keys page is missing Bootstrap 5 form-control classes"
    grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/user-pubkeys.html" || smoke_die "User public keys page is missing Bootstrap 5 primary button classes"
fi

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$BASE_URL/pubkeys/${KEY_ID}" -o "$TMP_DIR/pubkey-detail.html"
grep -q '>Logout<' "$TMP_DIR/pubkey-detail.html" || smoke_die "Authenticated session was lost while loading public key detail page"
grep -Eq 'class="[^"]*nav[^"]*nav-tabs' "$TMP_DIR/pubkey-detail.html" || smoke_die "Public key detail page is missing Bootstrap 5 nav-tab classes"
grep -Eq 'class="[^"]*tab-content' "$TMP_DIR/pubkey-detail.html" || smoke_die "Public key detail page is missing Bootstrap 5 tab-content classes"
grep -Eq 'class="[^"]*btn[^"]*btn-secondary' "$TMP_DIR/pubkey-detail.html" || smoke_die "Public key detail page is missing Bootstrap 5 secondary button classes"

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
    ! grep -Eq "$LEGACY_IN_CLASS_RE" "$output_file" || smoke_die "Target account page still renders legacy collapse/tab state classes"
    ! grep -Eq 'class="[^"]*\\btext-center\\b|class="[^"]*\\btext-muted\\b|class="[^"]*\\btext-success\\b|class="[^"]*\\btext-warning\\b|class="[^"]*\\btext-danger\\b|class="[^"]*\\btext-info\\b|class="[^"]*\\brounded\\b|class="[^"]*\\bimg-fluid\\b|class="[^"]*\\bclearfix\\b|class="[^"]*\\bd-xl-none\\b|class="[^"]*\\bh-50px\\b' "$output_file" || smoke_die "Target account page still renders Bootstrap-named semantic helper classes"
    ! grep -Eq 'class="[^"]*\\btext-bg-secondary\\b' "$output_file" || smoke_die "Target account page still renders Bootstrap-named inactive badge classes"
    grep -Eq 'class="[^"]*nav[^"]*nav-tabs' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 nav-tab classes"
    grep -Eq 'class="[^"]*tab-content' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 tab-content classes"
    grep -Eq 'class="[^"]*table' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 table classes"
    grep -Eq 'class="[^"]*row' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 row classes"
    grep -Eq 'class="[^"]*col-md-[0-9]+' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 column classes"
    grep -Eq 'class="[^"]*input-group' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 input-group classes"
    grep -Eq 'class="[^"]*form-control' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 form-control classes"
    grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 primary button classes"
    grep -Eq 'class="[^"]*btn[^"]*btn-secondary' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 secondary button classes"
    grep -Eq 'class="[^"]*alert[^"]*alert-info' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 info alert classes"
    grep -Eq 'class="[^"]*w-100' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 width helpers on primary actions"
    if grep -Eq 'name="approve_access"|name="reject_access"' "$output_file"; then
        grep -Eq 'class="[^"]*btn[^"]*btn-success' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 success button classes"
        grep -Eq 'class="[^"]*btn[^"]*btn-danger' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 danger button classes"
    fi
    if grep -Eq 'name="force"' "$output_file"; then
        grep -Eq 'class="[^"]*form-check' "$output_file" || smoke_die "Target account page is missing Bootstrap 5 form-check classes"
    fi
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
! grep -Eq "$RETIRED_SKA_GENERIC_RE" "$TMP_DIR/access-options.html" || smoke_die "Access options page still renders retired SKA generic classes"
! grep -Eq "$LEGACY_PRESENTATION_RE" "$TMP_DIR/access-options.html" || smoke_die "Access options page still renders Bootstrap-named semantic helper classes"
grep -Eq 'class="[^"]*form-check' "$TMP_DIR/access-options.html" || smoke_die "Access options page is missing Bootstrap 5 form-check classes"
grep -Eq 'class="[^"]*form-control' "$TMP_DIR/access-options.html" || smoke_die "Access options page is missing Bootstrap 5 form-control classes"
grep -Eq 'class="[^"]*btn[^"]*btn-primary' "$TMP_DIR/access-options.html" || smoke_die "Access options page is missing Bootstrap 5 primary button classes"
grep -Eq 'class="[^"]*btn[^"]*btn-secondary' "$TMP_DIR/access-options.html" || smoke_die "Access options page is missing Bootstrap 5 secondary button classes"
grep -Eq 'class="[^"]*row' "$TMP_DIR/access-options.html" || smoke_die "Access options page is missing Bootstrap 5 row classes"

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