# Phase 9 Checkpoint: Legacy Global-State Reduction

Date: 2026-02-10
Branch: `bootstrap5-upgrade`

## Objective

Replace runtime `global` dependencies in core request/model paths with explicit runtime-state lookups while preserving SKA behavior for LDAP auth, key lifecycle, access lifecycle, sync scheduling, and audit/event logging.

## Completed slices

1. Runtime state container and bootstrap wiring
   - Added runtime-state service container and seeded request/runtime context from bootstrap.
   - Kept `$GLOBALS` compatibility fallback during transition.

2. Request/auth/csrf boundary dependency pass
   - Routed auth guard and CSRF guard through explicit request context.
   - Preserved route behavior and session/auth flows.

3. Database bootstrap service-map pass
   - Refactored database setup to return explicit service map.
   - Preserved legacy initialization ordering to avoid constructor regressions.

4. Model runtime dependency migration (incremental)
   - Replaced `global` usage in touched models (`Entity`, `Record`, `PublicKey`, `ServerAccount`, `User`, `Report`, directory/event helpers).
   - Finalized remaining model `global` removals in:
     - `model/server.php`
     - `model/group.php`
   - Result: no remaining `global` usage under `model/`.

## Validation evidence

- Quality gate pass:
  - `source testenvs.env && COMPOSER_ALLOW_SUPERUSER=1 make ci-check`
- Smoke harness pass:
  - `source testenvs.env && make smoke-dry-run`
  - `source testenvs.env && make smoke`
- Result: login, key add/delete, access add/remove, and sync preview fixture verification remained green.

## Compatibility status

- Preserved:
  - LDAP login/auth behavior
  - Public key lifecycle flows
  - Access rule lifecycle flows
  - Sync preview fixture stability
  - Audit/event logging semantics
- No schema migrations introduced in Phase 9.
- No key path changes:
  - `config/keys-sync`
  - `config/keys-sync.pub`

## Residual risks

- Runtime-state fallback still coexists with `$GLOBALS` compatibility paths; full removal is deferred to later cleanup.
- Some non-model legacy globals may remain in scripts/views and should be addressed in a dedicated follow-up with compatibility checks.
- Constructor-time dependency ordering remains sensitive in bootstrap paths; keep smoke and CI checks mandatory for further refactors.

## Rollback strategy (Phase 9)

- Revert Phase 9 commits in reverse order if regressions appear.
- Runtime-state adoption is compatibility-wrapped; rollback restores global-only behavior without schema/data migration.
