# SKA Incremental Migration Roadmap

Date: 2026-02-09

Principles:
- Small PR-sized phases.
- Behavior-preserving first, structural modernization second.
- Add compatibility tests before high-uncertainty refactors.

## Phase Map (PR-sized)

### Phase 0 (current): Inventory + contracts + plan
- Deliverables:
  - `docs/modernization-plan.md`
  - `docs/modernization-risks.md`
  - `docs/modernization-roadmap.md`
  - `docs/compatibility-contract.md`
- Rollback:
  - Documentation-only; revert docs commit if needed.

### Phase 1: Tooling and quality gate baseline
- Objective:
  - Introduce non-invasive developer tooling and CI checks.
- PR scope:
  - Add `phpstan`, `phpcs`/`php-cs-fixer`, and baseline configs.
  - Add make/composer scripts for lint/static-analysis/checks.
  - Extend CI with composer/platform/docker validation jobs.
- Effort estimate: 1-2 days
- Risk: Low
- Rollback strategy:
  - Revert tooling/CI commit; runtime behavior unaffected.

### Phase 2: Compatibility smoke harness
- Objective:
  - Make critical workflows testable before deeper refactors.
- PR scope:
  - Add smoke test scripts/checklists for:
    - LDAP login/auth
    - key upload/delete
    - access add/remove
    - sync trigger + keyfile verification
  - Add deterministic fixture data and scriptable checks for sync output diffs.
- Effort estimate: 2-3 days
- Risk: Medium
- Rollback strategy:
  - Revert test harness files only; app runtime unchanged.

### Phase 3: Request/auth boundary stabilization
- Objective:
  - Centralize auth/session/CSRF handling while preserving routes and behavior.
- PR scope:
  - Introduce request context/auth guard abstraction used by `requesthandler.php`.
  - Remove duplicated auth/session logic from views where possible.
  - Keep existing routes, redirects, and LDAP assumptions unchanged.
- Effort estimate: 3-5 days
- Risk: Medium-High
- Rollback strategy:
  - Keep legacy path behind adapter toggle during rollout.
  - Revert boundary shim commits and restore prior handler flow.

### Phase 4: Model safety pass (access/key/user hot paths)
- Objective:
  - Reduce risk in high-impact model operations without schema breaks.
- PR scope:
  - Introduce typed service/repository wrappers for access and key lifecycle.
  - Preserve existing SQL semantics; add compatibility assertions.
- Rollback:
  - Route wrappers back to legacy model calls.

### Phase 5: Sync subsystem hardening
- Objective:
  - Strengthen host verification and operational safety in sync execution.
- PR scope:
  - Isolate SSH/jumphost trust model and explicit validation behavior.
  - Add dry-run and diff diagnostics as first-class outputs.
- Checkpoint:
  - See `docs/phase-5-checkpoint.md` for completed slices and validation evidence.
- Rollback:
  - Keep legacy sync executor callable; revert hardened path if mismatch found.

### Phase 6: Security header/CSP hardening
- Objective:
  - Move and strengthen security headers pragmatically.
- PR scope:
  - Central response-layer headers, CSP report-first rollout, encode/validate audit.
- Checkpoint:
  - See `docs/phase-6-checkpoint.md` for completed slices and validation evidence.
- Rollback:
  - Revert header policy changes; restore previous header set.

### Phase 7: Frontend compatibility layer for Bootstrap 5
- Objective:
  - Enable mixed Bootstrap 3/5 migration with minimal disruption.
- PR scope:
  - Add compatibility CSS/utilities and migrate shared layout primitives.
- Checkpoint:
  - See `docs/phase-7-checkpoint.md` for completed slices and validation evidence.
- Rollback:
  - Revert compatibility layer and affected templates.

### Phase 8: Page-by-page frontend migration
- Objective:
  - Migrate high-value pages incrementally.
- PR scope:
  - Login, home, server/account/access/public-key pages in separate small PRs.
- Checkpoint:
  - See `docs/phase-8-checkpoint.md` for completed slices and validation evidence.
- Rollback:
  - Revert page-specific template/CSS commits.

### Phase 9: Legacy global-state reduction
- Objective:
  - Replace global state usage in touched areas with explicit dependencies.
- PR scope:
  - Directory/service construction via container/factory shim.
- Checkpoint:
  - See `docs/phase-9-checkpoint.md` for completed slices and validation evidence.
- Rollback:
  - Keep fallback global initialization path until parity confirmed.

### Phase 10: Final cleanup and docs refresh
- Objective:
  - Consolidate modernization, remove deprecated paths, update runbooks.
- PR scope:
  - Remove transitional shims, finalize docs and operations guides.
- Checkpoint:
  - See `docs/phase-10-checkpoint.md` for completed slices and validation evidence.
- Rollback:
  - Re-enable shims via tagged rollback release if needed.

## First 3 Implementation Phases (Requested Proposal)
1. Phase 1: Tooling and quality gate baseline
   Effort: 1-2 days
   Risk: Low
2. Phase 2: Compatibility smoke harness
   Effort: 2-3 days
   Risk: Medium
3. Phase 3: Request/auth boundary stabilization
   Effort: 3-5 days
   Risk: Medium-High
