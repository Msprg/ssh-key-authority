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
  - A refused login is a `LoginFailedException` thrown by `AuthService::authenticate()`; its message is shown to
    the user as is. Failures before the password is verified must stay generic ("Invalid username or
    password") so the form cannot be used to probe for accounts; only failures after a successful user bind
    (missing email, disabled in SKA, ...) may be specific. Only `counts_as_attempt` failures feed the rate limit.
  - The password is checked by binding with the DN of the entry found for `user_id` under `dn_user`, so the
    account that is verified is the account that gets the session. Do not bind with a name built from the
    typed username (UPN, `CN=<username>,...`) to decide who is logged in.
- Key upload: stored in `model/publickey.php` and related directories.
- Access rules: `model/access.php` and related access option classes.
- Key distribution: `scripts/sync.php` over SSH to write files under `/var/local/keys-sync/`.
  - Which accounts get a key file, which access rules apply to an account and whom a rule covers is decided
    in `keyfile_common.php` (`list_keyfile_accounts()`, `list_account_rule_sets()`, `expand_access_source()`,
    `list_access_grantees()`), and the file content is built there (`build_account_keyfile()`,
    `build_ldap_user_keyfile()`). The sync, the "Troubleshoot access" tool, the permissions report
    (`model/report.php`) and the inherited rules on the server and account pages all use it. Do not walk
    `group_member` and `access` yourself to say who has access, and do not "fix" an oddity in one caller:
    nested groups are expanded even when inactive, each group once per rule by name, duplicates are kept.
    The report and the tool switch on remembering of group rules and members for the duration of their work
    (`remember_access_rules()`); the sync never does and reads everything at the moment it builds a file.
- Troubleshoot access (Tools menu, admins only): `views/troubleshoot_access.php` asks for a user or key, a
  server and a login name, and `model/accesstroubleshooter.php` explains why access is or is not granted.
  - Rules and the people they cover come from the shared functions above, and the verdict from the generated
    key file. What the tool still checks itself to explain it, and what has to change with the sync, is:
    inactive users and decommissioned servers (`match_rule()`), key destination rules (`examine_keys()`) and
    the LDAP user files (`examine_ldap_login()`).
  - The page shows the verdict, an "access path" diagram (`AccessTroubleshooter::diagram()`: user, groups,
    account, key file, server, login, with the break marked) and then the findings as a "Detailed report" that
    is collapsed by default. `public_html/extra.js` remembers in the browser whether the user opened it. The
    diagram must never look better than the verdict: a new kind of finding needs a place in both.
  - The web process cannot open SSH connections (it cannot read the sync key). Facts about a target server
    (sshd configuration, key directory, login accounts) are collected by `ServerHostFacts::collect_and_store()`
    at the end of each sync and, at most every 15 minutes, by `scripts/supervise_external_keys.php`, and
    stored in `server_host_facts`. Collection must never fail or slow down a sync noticeably.
  - `model/sshdconfig.php` evaluates the collected sshd directives the way sshd does (first value wins,
    Include in place, Match blocks). Text from target servers is untrusted: only allowlisted directives are
    stored, and it is always escaped on output.
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
- Facts collected from target servers: `server_host_facts` (one JSON snapshot per server)

## Development and runtime notes
- Version string: never hardcode it. `ska_version()` in `core.php` reads `VERSION` (written by the
  Dockerfile from the `SKA_VERSION` build arg, which CI sets via `git describe`), else `git describe`
  on a checkout, else `dev`. Releasing = publishing a GitHub release whose tag is the version.
- PHP 8.2+, MySQL/MariaDB, LDAP.
- Docker is the preferred deployment method (`Dockerfile`, `docker-compose.yml`).
- Container start goes through `scripts/docker-entrypoint.sh`: it fixes ownership of the bind-mounted
  `/var/log/ska` (www-data) and `/var/local/keys-sync` (keys-sync) dirs, installs the mounted sync key
  pair, then execs supervisord. Without this, cron jobs silently fail on root-owned host dirs.
- Cron and supervisor configs live under `etc/`.
- Web assets in `public_html/` (Bootstrap + jQuery).

## Where to look for changes
- UI changes: `templates/` and `views/`
- Access rules as data: `model/access.php` and `model/accessoption.php`; who has access as a result:
  `keyfile_common.php`
- LDAP behavior: `ldap.php` and `services/auth.php`
- Sync behavior: `scripts/sync.php` and `scripts/sync-common.php`; key file content: `keyfile_common.php`
- "Why can't X log in": `model/accesstroubleshooter.php`, `model/sshdconfig.php`, `model/serverhostfacts.php`

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

