# SSH Key Authority (SKA) - AI Agent Notes

This document gives AI agents enough context to work safely in this repo.

## Current status (Phase 10 complete)
- Modernization phases 0-10 are implemented on the Bootstrap 5 modernization branch (`bootstrap5-upgrade*` lineage).
- Runtime state container is in use (`services/runtime_state.php`) with compatibility fallback paths still present.
- Request/auth/CSRF/security-header flow is service-based in `requesthandler.php`.
- Bootstrap 3/5 compatibility layer is active:
  - `public_html/bootstrap5-compat.css`
  - `public_html/bootstrap5-compat.js`
- Smoke harness and quality gates are available and expected in agent workflow.

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
- Services: `services/`
- CLI scripts: `scripts/` (sync + cron jobs)
- Documentation: `docs/`

## Configuration and secrets
- Main config template: `config/config.ini.example`
- Real config: `config/config.ini` (not in repo)
- Sync SSH keys expected at:
  - `config/keys-sync` (private key)
  - `config/keys-sync.pub` (public key)
- Never commit real secrets, keys, or production credentials.

## Critical compatibility contract
Do not break these unless explicitly approved and documented:
- LDAP login/auth flow.
- Public key add/remove lifecycle.
- Access rule add/remove lifecycle.
- Sync behavior and output compatibility.
- Audit/event logging semantics.
- Sync key paths:
  - `config/keys-sync`
  - `config/keys-sync.pub`

Reference: `docs/compatibility-contract.md`

## Core workflows (high level)
- Login/auth: `services/auth.php`, `services/login_flow.php`, `ldap.php`
- Request policy/auth/csrf: `services/request_policy_guard.php`, `services/request_auth_guard.php`, `services/request_csrf_guard.php`
- Key lifecycle: `services/key_lifecycle_service.php`, `model/publickey.php`
- Access lifecycle: `services/access_rule_service.php`, `model/access.php`, `model/accessoption.php`
- Sync distribution: `scripts/sync.php`, `scripts/sync-common.php`, `scripts/syncd.php`
- External key supervision: `scripts/supervise_external_keys.php`
- LDAP sync: `scripts/ldap_update.php`

## Data model (key tables)
See migrations (`migrations/00x.php`) for schema details.
- Users: `user`, `entity`
- Groups: `group`, `group_member`
- Servers: `server`, `server_account`
- Keys: `public_key`
- Access rules: `access`, `access_option`
- Events/audit: `entity_event`, `server_event`
- Sync: `sync_request`, `external_key`

## Validation workflow for agents
Run these before handoff:

```bash
source testenvs.env
COMPOSER_ALLOW_SUPERUSER=1 make ci-check
make smoke-dry-run
make smoke
```

If `testenvs.env` is not present in the environment, coordinate with the user for smoke variables.

## Smoke workflow expectations
Smoke harness validates:
- login page + LDAP auth
- key add/remove for authenticated user
- access rule add/remove for target account
- sync preview output against fixture

References:
- `docs/smoke-tests.md`
- `docs/operations-runbook.md`

## Where to look for changes
- UI and UX: `templates/`, `views/`, compatibility assets in `public_html/`
- Access logic: `model/access.php`, `model/accessoption.php`, `services/access_rule_service.php`
- LDAP behavior: `ldap.php`, `services/auth.php`
- Sync behavior: `scripts/sync.php`, `scripts/sync-common.php`, `scripts/syncd.php`
- Request/auth/security headers: `requesthandler.php`, `services/request_*`, `services/response_security_headers.php`

## Safety checks for edits
- Validate any change touching SSH sync, key generation, or host verification paths.
- Avoid changing LDAP query behavior unless assumptions and migration notes are explicit.
- Keep audit/event logging intact when changing key/access/admin flows.
- Keep schema-compatible migrations unless a breaking change is explicitly approved.

## Modernization docs map
- Plan: `docs/modernization-plan.md`
- Risks: `docs/modernization-risks.md`
- Roadmap: `docs/modernization-roadmap.md`
- Checkpoints:
  - `docs/phase-5-checkpoint.md`
  - `docs/phase-6-checkpoint.md`
  - `docs/phase-7-checkpoint.md`
  - `docs/phase-8-checkpoint.md`
  - `docs/phase-9-checkpoint.md`
  - `docs/phase-10-checkpoint.md`
