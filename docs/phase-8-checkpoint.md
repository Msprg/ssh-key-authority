# Phase 8 Checkpoint: Page-by-Page Frontend Migration

Date: 2026-02-09
Branch: `bootstrap5-upgrade`

## Objective

Migrate high-value pages incrementally to Bootstrap 5-compatible markup while preserving existing Bootstrap 3 runtime behavior and all critical SKA workflows.

## Completed slices

1. Login page migration slice
   Commit: `df1526a`
   - Migrated login form spacing and utility usage to Bootstrap 5-compatible classes.
   - Preserved login payload fields and LDAP auth request flow.

2. Home page key lifecycle form migration
   Commit: `56df025`
   - Updated home key add/remove form layout utilities.
   - Updated JS selectors to support both legacy hidden behavior and `d-none`.

3. Server account page (access + key form primitives)
   Commit: `bae6f26`
   - Added dual tab/dropdown attributes and migrated high-use access/key forms to compatibility utilities.

4. Server page migration slice
   Commit: `f836cb2`
   - Added dual tab attributes.
   - Migrated notes/contact/request form blocks to Bootstrap 5-compatible spacing and width utilities.

5. Group page migration slice
   Commit: `ae6500a`
   - Added dual tab attributes.
   - Migrated member/access add forms to compatibility utilities.

6. Public key page migration slice
   Commit: `15b1eee`
   - Added dual tab attributes.
   - Migrated key-signing and destination rule form spacing/button width utilities.

7. Servers/groups listing pages migration slice
   Commit: `2d19813`
   - Added dual tab attributes on list/add views.
   - Migrated filter/create form spacing utilities.

8. Remaining tab/collapse/form compatibility sweep
   Commit: `2fd3f13`
   - Added dual tab attributes in `templates/user.php`, `templates/pubkeys.php`, and shared keygen help tabs in `templates/functions.php`.
   - Added collapse dual-attribute support and utility updates in `templates/access_options.php` and `templates/servers_bulk_action.php`.
   - Migrated bulk-mail and user-public-key form spacing/button utility usage.

## Validation evidence

- Repeated full gate pass during slices:
  - `source testenvs.env && COMPOSER_ALLOW_SUPERUSER=1 make ci-check`
- Repeated smoke pass during slices:
  - `source testenvs.env && make smoke-dry-run`
  - `source testenvs.env && make smoke`
- Result: login, key add/delete, access add/remove, and sync preview fixture checks remained green throughout Phase 8 slices.

## Compatibility status

- Preserved:
  - LDAP login/auth behavior
  - Public key lifecycle flows
  - Access rule lifecycle flows
  - Sync preview fixture output stability
- No schema migrations in Phase 8.
- No key path changes:
  - `config/keys-sync`
  - `config/keys-sync.pub`

## Residual risks

- Some legacy Bootstrap 3 components (`panel`, `input-group-addon`, assorted settings form layouts) remain and still require targeted migration in later phases.
- Mixed class conventions can still create minor visual inconsistencies across older untouched sections.
- JavaScript behavior still relies on Bootstrap 3 plugin APIs; full Bootstrap 5 runtime adoption remains out of scope for Phase 8.

## Rollback strategy (Phase 8)

- Revert Phase 8 commits in reverse order (`2fd3f13` back to `df1526a`) to restore previous markup incrementally.
- Compatibility layer from Phase 7 is additive and can remain in place even if selected page slices are rolled back.
