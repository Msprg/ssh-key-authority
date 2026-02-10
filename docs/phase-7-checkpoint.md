# Phase 7 Checkpoint: Bootstrap 5 Compatibility Layer

Date: 2026-02-09
Branch: `bootstrap5-upgrade`

## Objective

Enable an incremental Bootstrap 3 -> 5 migration path by introducing a compatibility layer that supports mixed markup without breaking current workflows.

## Completed slices

1. Bootstrap 5 compatibility CSS/JS layer + shared template primitives
   - Added `public_html/bootstrap5-compat.css` with Bootstrap 5 utility aliases used during progressive migration (`ms/me/ps/pe`, `text-start/end`, `float-start/end`, `fw-*`, `visually-hidden*`, `form-select`, `form-check*`, `d-grid`, `gap-*`, `text-bg-*`).
   - Added `public_html/bootstrap5-compat.js` to map `data-bs-*` attributes and selected Bootstrap 5 classes to Bootstrap 3 runtime equivalents.
   - Updated `templates/base.php` to load compatibility assets and dual-wire shared markup primitives (dropdown trigger/menu, dismissible alerts, skip-link utility classes) so legacy Bootstrap 3 behavior remains intact while allowing Bootstrap 5-compatible markup.

## Validation evidence

- Full quality gate pass:
  - `source testenvs.env && COMPOSER_ALLOW_SUPERUSER=1 make ci-check`
- Smoke harness pass:
  - `source testenvs.env && make smoke-dry-run`
  - `source testenvs.env && make smoke`
  - Result: login, key add/delete, access add/remove, and sync preview fixture checks passed.

## Compatibility status

- Preserved:
  - LDAP login/auth behavior
  - Public key lifecycle flows
  - Access rule lifecycle flows
  - Sync preview output fixture stability
- No schema migrations introduced in Phase 7.
- No key path changes:
  - `config/keys-sync`
  - `config/keys-sync.pub`

## Residual risks

- Compatibility shims intentionally cover common primitives; less common Bootstrap 5 components still require targeted handling before use.
- Mixed Bootstrap 3/5 classes can accumulate visual drift if page-level migrations are not normalized per page.
- Client-side class/attribute mapping runs on DOM ready; newly injected dynamic markup should still use compatibility-aware classes.

## Rollback strategy (Phase 7)

- Revert Phase 7 compatibility files and template wiring:
  - `public_html/bootstrap5-compat.css`
  - `public_html/bootstrap5-compat.js`
  - `templates/base.php`
  - `docs/modernization-roadmap.md` and this checkpoint doc
- Existing Bootstrap 3 assets and behaviors remain available as the compatibility layer is additive.
