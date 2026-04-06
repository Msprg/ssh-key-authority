#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck disable=SC1091
source "$SCRIPT_DIR/lib.sh"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/browser-lib.sh"

browser_smoke_require_prereqs

smoke_require_env SKA_SMOKE_BASE_URL
smoke_require_env SKA_SMOKE_USERNAME
smoke_require_env SKA_SMOKE_PASSWORD
smoke_require_env SKA_SMOKE_ACCESS_SERVER_HOSTNAME

BASE_URL="${SKA_SMOKE_BASE_URL%/}"
TARGET_SERVER=$(smoke_urlencode "$SKA_SMOKE_ACCESS_SERVER_HOSTNAME")

browser_smoke_init 1600 1200
trap browser_smoke_cleanup EXIT
browser_smoke_start
browser_smoke_login "$BASE_URL" "$SKA_SMOKE_USERNAME" "$SKA_SMOKE_PASSWORD"

smoke_log "Checking authenticated shell dropdown interaction"
browser_smoke_navigate "${BASE_URL}/"
browser_smoke_wait_for_js '
    return !!(window.bootstrap && window.bootstrap.Dropdown) &&
        !!document.querySelector("[data-bs-toggle=\"dropdown\"]") &&
        !!document.querySelector(".ska-dropdown-menu");
' 40 0.1 || smoke_die "Shell dropdown assets did not initialize"
browser_smoke_exec '
    var trigger = document.querySelector("[data-bs-toggle=\"dropdown\"]");
    if (!trigger) {
        return false;
    }
    trigger.click();
    return true;
' >/dev/null
browser_smoke_wait_for_js '
    var trigger = document.querySelector("[data-bs-toggle=\"dropdown\"]");
    var menu = document.querySelector(".ska-dropdown-menu.show");
    return !!trigger && !!menu &&
        trigger.getAttribute("aria-expanded") === "true";
' 40 0.1 || smoke_die "Shell dropdown did not open"
browser_smoke_exec '
    document.documentElement.click();
    return true;
' >/dev/null
browser_smoke_wait_for_js '
    var trigger = document.querySelector("[data-bs-toggle=\"dropdown\"]");
    var menu = document.querySelector(".ska-dropdown-menu");
    return !!trigger && !!menu &&
        !menu.classList.contains("show") &&
        trigger.getAttribute("aria-expanded") === "false" &&
        menu.getAttribute("aria-hidden") !== "false";
' 40 0.1 || smoke_die "Shell dropdown did not close"

smoke_log "Checking help collapse interaction"
browser_smoke_navigate "${BASE_URL}/help"
browser_smoke_exec '
    var trigger = document.querySelector("a[href=\"#getting_started\"][data-bs-toggle=\"collapse\"]");
    if (!trigger) {
        return false;
    }
    trigger.click();
    return true;
' >/dev/null
browser_smoke_wait_for_js '
    var trigger = document.querySelector("a[href=\"#getting_started\"][data-bs-toggle=\"collapse\"]");
    var panel = document.getElementById("getting_started");
    return !!trigger && !!panel &&
        panel.classList.contains("show") &&
        panel.getAttribute("aria-hidden") === "false" &&
        trigger.getAttribute("aria-expanded") === "true";
' 50 0.1 || smoke_die "Help collapse did not open"
browser_smoke_exec '
    var trigger = document.querySelector("a[href=\"#getting_started\"][data-bs-toggle=\"collapse\"]");
    if (!trigger) {
        return false;
    }
    trigger.click();
    return true;
' >/dev/null
browser_smoke_wait_for_js '
    var trigger = document.querySelector("a[href=\"#getting_started\"][data-bs-toggle=\"collapse\"]");
    var panel = document.getElementById("getting_started");
    return !!trigger && !!panel &&
        !panel.classList.contains("show") &&
        panel.getAttribute("aria-hidden") === "true" &&
        trigger.getAttribute("aria-expanded") === "false";
' 50 0.1 || smoke_die "Help collapse did not close"

smoke_log "Checking server list tab interaction"
browser_smoke_navigate "${BASE_URL}/servers"
TAB_RESULT=$(browser_smoke_exec '
    var tab = document.querySelector(".nav-link[data-bs-toggle=\"tab\"]:not(.active)");
    if (!tab) {
        return "";
    }
    tab.click();
    return tab.id + "|" + tab.getAttribute("href");
')
[ -n "$TAB_RESULT" ] || smoke_die "No inactive server-list tab was available to click"
TAB_ID=${TAB_RESULT%%|*}
TAB_TARGET=${TAB_RESULT#*|}
browser_smoke_wait_for_js '
    var tabId = arguments[0];
    var target = arguments[1];
    var tab = document.getElementById(tabId);
    var pane = document.querySelector(target);
    return !!tab && !!pane &&
        tab.classList.contains("active") &&
        tab.getAttribute("aria-selected") === "true" &&
        pane.classList.contains("active") &&
        pane.classList.contains("show") &&
        pane.getAttribute("aria-hidden") === "false";
' 50 0.1 "$TAB_ID" "$TAB_TARGET" || smoke_die "Server-list tab did not activate"

smoke_log "Browser interaction checks completed successfully"
