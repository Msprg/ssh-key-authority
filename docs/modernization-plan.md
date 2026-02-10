# SKA Modernization Blueprint

Date: 2026-02-09
Branch: `bootstrap5-upgrade`
Scope: behavior-preserving modernization of architecture, dependencies, frontend, security posture, and developer workflow.

## 1. Goals And Constraints

### Goals
- Modernize SKA to a maintainable PHP 8.x codebase with clear module boundaries.
- Increase confidence with automated checks and smoke/integration tests for critical workflows.
- Progressively modernize frontend from Bootstrap 3 + jQuery patterns to a maintainable stack.
- Harden operational and application security without disrupting core usage.

### Hard constraints (must stay stable)
- No secrets, real private keys, or real environment config committed.
- Keep sync key paths compatible unless config/docs migration ships together:
  - `config/keys-sync`
  - `config/keys-sync.pub`
- Preserve LDAP/AD behavior assumptions unless migration notes explicitly call out changes.
- Preserve event/audit logging semantics.
- Preserve critical workflows:
  - LDAP login/auth
  - add/remove public keys
  - create/remove access rules
  - trigger sync and verify keyfile outputs

## 2. Current-State Inventory (Phase 0 Findings)

### Runtime and dependencies
- PHP baseline already at `^8.2` (`composer.json`).
- Direct runtime extensions required: `ldap`, `mysqli`, `pcntl`, `ssh2`, `mbstring`.
- Third-party PHP library usage is minimal (`phpseclib/phpseclib`).
- No explicit dev tooling dependencies for linting/static analysis/formatting.

### Architecture and flow
- Entry and orchestration are procedural and globally coupled:
  - `public_html/init.php` -> `requesthandler.php` -> `core.php`
- Shared mutable globals drive runtime state (`$config`, `$database`, `$active_user`, directories).
- Routing is custom regex-based (`router.php`, `routes.php`).
- Views mix request handling, authorization, mutation, and rendering (example: `views/serveraccount.php`).
- Model layer is ActiveRecord-like with magic accessors and global DB coupling (`model/record.php`).
- Migrations auto-run during bootstrap via `MigrationDirectory::LAST_MIGRATION`.

### Frontend
- Bootstrap 3 assets vendored in repo (`public_html/bootstrap/*`) with glyphicons.
- jQuery currently at 3.7.1 and loaded globally.
- Templates tightly coupled to Bootstrap 3 classes and jQuery plugin behaviors.
- No frontend build pipeline; static assets are directly served.

### Security posture (current)
- Positive controls present:
  - prepared statements in many paths
  - CSRF checks on POST flows
  - some secure session flags
  - host-key verification logic in sync path
- Gaps/risks:
  - CSP currently minimal (`default-src 'self'`) and set in template layer.
  - legacy hardening header (`X-XSS-Protection`) still used.
  - `eval()` in autoload fallback in `core.php` was identified as unnecessary risk (removed in Phase 10).
  - `scripts/ssh.php` disables strict host checking for jumphost chain command.
  - inconsistent auth/session logic split between request handler, login view, and `AuthService`.

### Data/model and domain
- Domain logic heavily embedded in model classes and views.
- Table locking pattern (`LOCK TABLES`) in access option updates can create operational risk.
- SQL query construction is mixed; some dynamic query assembly exists.
- Audit/event logging is deeply interleaved with write operations and must be preserved.

### DevEx and operations
- Dockerized runtime exists and is functional.
- CI currently focuses on container build/push, not behavior or quality gates.
- No automated unit/integration test suite.
- Baseline checks run in Phase 0:
  - `composer validate --strict` -> pass
  - `composer audit` -> no advisories
  - `composer check-platform-reqs` -> pass
  - `docker compose config -q` -> pass

### Technical debt signals
- ~139 PHP files in app tree (excluding vendor).
- High coupling indicators:
  - `global` usage count: 92 matches
  - superglobal usage count: 189 matches
- Indicates high regression risk for big-bang refactors; migration must be incremental.

## 3. Target Architecture (Incremental)

### Desired boundaries
- `App\Http`: request context, routing adapters, middleware-like auth/csrf guards.
- `App\Domain`: key lifecycle, access policy, sync orchestration, LDAP user/group sync.
- `App\Infra`: DB repositories, LDAP gateway, SSH transport, mail adapter.
- `App\UI`: template presenters and view models.

### Transitional strategy
- Keep existing routes and templates operational while introducing new service boundaries behind adapters.
- Introduce typed DTO/service interfaces first, then migrate call sites route-by-route.
- Preserve database schema compatibility unless explicitly approved.

## 4. Modernization Workstreams

### A. Backend/runtime
- Add strict typing gradually (`declare(strict_types=1)`) on new/touched files.
- Introduce namespaced classes for new modules while keeping legacy autoload path intact.
- Remove dangerous/legacy constructs (`eval` fallback, implicit globals in new code).

### B. Data/domain
- Create repository layer for high-risk entities first (User, PublicKey, Access, Server, SyncRequest).
- Preserve current SQL behavior and ordering semantics via compatibility tests before rewrites.
- Keep migrations additive and reversible where possible.

### C. Frontend
- Progressive migration:
  - phase-in Bootstrap 5 compatible markup wrappers
  - isolate jQuery-dependent widgets
  - migrate per page/feature instead of full rewrite
- Maintain current navigation, forms, and keyboard accessibility paths.

### D. Security
- Move security headers from template to HTTP response layer and expand policy incrementally.
- Centralize input validation and output encoding rules.
- Harden sync/jumphost host verification and document required trust model.

### E. DevEx/ops
- Add CI quality gates: lint, static analysis, composer checks, smoke checks.
- Add deterministic local/dev compose profiles.
- Introduce reproducible smoke/integration test harness for critical workflows.

## 5. Quality Gates For Every Phase

A phase is complete only if all pass (or failures are explicitly documented):
- `composer validate --strict`
- `composer audit`
- `composer check-platform-reqs`
- `docker compose config -q`
- smoke checklist status for impacted workflows

## 6. Definition Of Done For Modernization PRs
- No compatibility-contract regressions.
- No secret material introduced.
- Rollback steps documented in PR description.
- Residual risks explicitly listed.
