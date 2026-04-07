# Phase 6 Checkpoint: Security Header and CSP Hardening

Date: 2026-02-09
Branch: `bootstrap5-upgrade`

## Objective

Move security headers to a response-layer policy, support pragmatic CSP report-first rollout, and harden core output/redirect validation paths without breaking existing workflows.

## Completed slices

1. Centralized response security headers + configurable CSP mode
   Commit: `7833b6d`
   - Added `services/response_security_headers.php`.
   - Moved header emission from template layer to `requesthandler.php`.
   - Added config support for CSP policy override and report-only mode.

2. Output encoding and redirect validation hardening
   Commit: `9f6e04e`
   - Hardened default HTML escaping in `core.php` (`ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8).
   - Added redirect newline validation to prevent header injection edge cases.

3. Pragmatic header baseline expansion
   Commit: `005593a`
   - Added `Permissions-Policy` baseline restrictions.
   - Added optional HTTPS-only HSTS controls (`hsts_enabled`, `hsts_max_age`, `hsts_include_subdomains`, `hsts_preload`) in config template.

## Validation evidence

- Full gate pass after each slice:
  - `composer validate --strict`
  - `composer audit`
  - `composer check-platform-reqs`
  - `docker compose config -q`
  - `composer run qa`
- Smoke checks executed from sourced env:
  - `source testenvs.env && make smoke-dry-run`
  - `source testenvs.env && make smoke`
  - Result: web workflows and sync preview fixture checks passed.

## Compatibility status

- Preserved:
  - LDAP login/auth behavior
  - Public key lifecycle flows
  - Access rule lifecycle flows
  - Sync preview output and fixture stability
- No schema migrations introduced in Phase 6.
- CSP default behavior remains compatible (`default-src 'self'` enforced unless explicitly configured otherwise).

## Residual risks

- Strict CSP rollout still requires environment-specific review for any future inline scripts or third-party assets.
- HSTS is optional and disabled by default; production enablement should be planned with HTTPS and subdomain coverage.
- Legacy templates may still contain raw HTML insertion points that rely on explicit `ESC_NONE`; ongoing template-by-template review is still needed in later phases.

## Rollback strategy (Phase 6)

- Revert Phase 6 commits in reverse order if needed:
  - permissions-policy / HSTS controls
  - escaping and redirect hardening
  - centralized header service and CSP mode plumbing
- If required, template-level header behavior can be restored by reverting `requesthandler.php` + `templates/base.php` changes.
