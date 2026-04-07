# SKA Compatibility Contract

Date: 2026-02-09
Applies to all modernization phases on branch `bootstrap5-upgrade`.

## 1. Purpose
This contract defines externally observable behavior that must remain stable unless a phase explicitly introduces a documented breaking change and migration path.

## 2. Stable Runtime Contracts

### Authentication and authorization
- LDAP-backed login remains the primary authentication path.
- Existing LDAP config semantics remain supported (`config/config.ini` keys used by `ldap.php` and `services/auth.php`).
- Login/logout route behavior remains stable:
  - unauthenticated users accessing protected routes are redirected to `/login`
  - authenticated users visiting `/login` are redirected back/home
- Admin/server-leader privilege semantics remain unchanged for access/key operations.

### Key lifecycle
- Public key create/delete semantics remain unchanged for users and server accounts.
- Key validation behavior (including configured minimum strengths) remains equivalent.
- Existing key import/export endpoints and response formats remain stable unless explicitly versioned.

### Access rules
- Add/remove access rules for user/group/server-account sources must preserve current grant/revoke behavior.
- Access option semantics (`command=`, `from=`, `nopty`, etc.) must stay equivalent.

### Sync distribution
- Sync key source paths remain:
  - `config/keys-sync`
  - `config/keys-sync.pub`
- Remote key output target remains `/var/local/keys-sync/` with per-account files.
- Sync scripts (`scripts/sync.php`, `scripts/syncd.php`) retain CLI contracts and expected statuses.
- Host verification and collision protection behaviors remain at least as strict as current effective policy.

### Audit and events
- Database and syslog event semantics for key/access/admin/sync actions remain intact.
- Event records remain generated for equivalent actions in same workflows.

## 3. Stable Data and Migration Contracts
- No destructive schema change without explicit approval and migration guide.
- Existing migrations remain runnable on upgraded code.
- Existing data in core tables must remain readable/writable with no required manual fixups.

## 4. Stable API/Route Contracts
- Existing route patterns in `routes.php` remain valid.
- Existing HTML/TXT/JSON endpoints used by operators/scripts remain available.
- Error pages (`403`, `404`, `500`, `503`) remain reachable under equivalent conditions.

## 5. Security Baseline Contracts
- No reduction in CSRF/session protections.
- No weakening of SSH sync trust model.
- No introduction of new secret material into repository.

## 6. Manual Smoke Checklist (Required Per Impacted Phase)
1. LDAP login/auth success with valid user.
2. LDAP login fails with invalid credentials.
3. Add public key to user/account; verify visible and logged.
4. Delete public key; verify removal and logged event.
5. Add access rule (user/group/server-account) and verify presence.
6. Remove access rule and verify absence.
7. Trigger sync and verify produced files under `/var/local/keys-sync/` for target account(s).
8. Verify no loss of admin/server-leader authorization boundaries.

## 7. Change Control Rule
If a PR cannot meet this contract, it must include:
- explicit compatibility delta,
- migration/rollback notes,
- approval before merge.
