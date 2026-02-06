# SSH Key Authority (SKA) - AI Agent Notes

This document gives AI agents enough context to work safely in this repo.

## What this project is
- SKA is a PHP web app that centralizes SSH public key management.
- It integrates with LDAP/AD for users and groups.
- It distributes authorized keys to servers over SSH.

## Repo layout and entry points
- Web entry point: `public_html/init.php`
- Request handling: `requesthandler.php`
- App bootstrap: `core.php`
- Routing: `routes.php` and `router.php`
- Views/controllers: `views/`
- Templates: `templates/`
- Models: `model/`
- Services: `services/` (auth, init scripts)
- CLI scripts: `scripts/` (sync + cron jobs)

## Configuration and secrets
- Main config template: `config/config.ini.example`
- Real config: `config/config.ini` (not in repo)
- Sync SSH keys expected at:
  - `config/keys-sync` (private key)
  - `config/keys-sync.pub` (public key)
- Do not commit any real secrets or keys.

## Core workflows (high level)
- Login: LDAP auth via `services/auth.php` and `ldap.php`, session in `requesthandler.php`.
- Key upload: stored in `model/publickey.php` and related directories.
- Access rules: `model/access.php` and related access option classes.
- Key distribution: `scripts/sync.php` over SSH to write files under `/var/local/keys-sync/`.
- Periodic tasks:
  - `scripts/ldap_update.php` (LDAP sync)
  - `scripts/supervise_external_keys.php` (detect external keys)
  - `scripts/syncd.php` (daemon for key sync)

## Data model (key tables)
See `migrations/00x.php` for schema.
- Users: `user`, `entity`
- Groups: `group`, `group_member`
- Servers: `server`, `server_account`
- Keys: `public_key`
- Access rules: `access`, `access_option`
- Events/audit: `entity_event`, `server_event`
- Sync: `sync_request`, `external_key`

## Development and runtime notes
- PHP 5.6+ (legacy), MySQL/MariaDB, LDAP.
- Docker is the preferred deployment method (`Dockerfile`, `docker-compose.yml`).
- Cron and supervisor configs live under `etc/`.
- Web assets in `public_html/` (Bootstrap + jQuery).

## Where to look for changes
- UI changes: `templates/` and `views/`
- Access logic: `model/access.php` and `model/accessoption.php`
- LDAP behavior: `ldap.php` and `services/auth.php`
- Sync behavior: `scripts/sync.php` and `scripts/sync-common.php`

## Safety checks for edits
- Validate any change that touches SSH key generation or distribution.
- Do not change key file paths without updating docs and server config.
- Avoid modifying LDAP queries without checking group/user assumptions.
- Keep audit/event logging intact when altering access flows.

## Testing guidance
- No automated tests in repo.
- For changes, prefer targeted manual verification:
  - Login and LDAP auth flow
  - Add/Remove public keys
  - Create/Remove access rules
  - Trigger a sync (CLI) and verify output files

