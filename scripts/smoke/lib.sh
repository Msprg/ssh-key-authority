#!/usr/bin/env bash
set -euo pipefail

smoke_log() {
    printf '[smoke] %s\n' "$*"
}

smoke_err() {
    printf '[smoke][error] %s\n' "$*" >&2
}

smoke_die() {
    smoke_err "$*"
    exit 1
}

smoke_require_cmd() {
    local cmd="$1"
    command -v "$cmd" >/dev/null 2>&1 || smoke_die "Required command not found: $cmd"
}

smoke_require_env() {
    local var_name="$1"
    if [ -z "${!var_name:-}" ]; then
        smoke_die "Required environment variable is not set: $var_name"
    fi
}

smoke_urlencode() {
    php -r 'echo rawurlencode($argv[1]);' "$1"
}

smoke_extract_csrf() {
    local html_file="$1"
    sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$html_file" | head -n 1
}

smoke_cleanup_dir() {
    local dir="$1"
    if [ -d "$dir" ]; then
        find "$dir" -mindepth 1 -type f -delete
        find "$dir" -mindepth 1 -type d -empty -delete
        rmdir "$dir" 2>/dev/null || true
    fi
}
