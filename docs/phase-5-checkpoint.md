# Phase 5 Checkpoint: Sync Subsystem Hardening

Date: 2026-02-09
Branch: `bootstrap5-upgrade`

## Objective

Strengthen sync execution safety, diagnostics, and operational clarity while preserving key distribution behavior and existing keyfile outputs.

## Completed slices

1. Runtime timeout abstraction + diagnostics
   Commit: `2aa0c63`
   - Added `scripts/sync-runtime.php`.
   - Centralized timeout command generation in `SyncProcess`.
   - Added `php scripts/sync.php --diagnostics`.

2. Jumphost trust diagnostics + tunnel failure summaries
   Commit: `661e442`
   - Added configurable jumphost trust options with compatibility defaults.
   - Surfaced jumphost trust settings in diagnostics output.
   - Improved tunnel failure reason visibility.

3. Failure classification + retry metadata
   Commit: `36f3538`
   - Added `scripts/sync-failure.php` for classified sync failure reporting.
   - Standardized messages with `code=<...>` and `retry=30m` on rescheduled failures.
   - Applied classification in sync and sync worker paths.

4. Non-fatal monitoring status write handling
   Commit: `71a3ca0`
   - Classified status file write failures as non-fatal sync issues.
   - Prevented monitoring write problems from surfacing as generic worker failures.

5. Final hardening pass: worker spawn failure handling
   - Added explicit handling for `proc_open` spawn failure in `SyncProcess`.
   - Ensures spawn failures are surfaced consistently and classified through existing failure paths.

## Validation evidence

- Repeated full gate pass:
  - `composer validate --strict`
  - `composer audit`
  - `composer check-platform-reqs`
  - `docker compose config -q`
  - `composer run qa`
- Repeated smoke checks:
  - `make smoke-dry-run`
  - multiple live `make smoke` runs in target environment with sync fixture match.

## Compatibility status

- Preserved:
  - LDAP auth flow
  - Public key lifecycle flows
  - Access rule lifecycle flows
  - Sync preview keyfile output behavior (fixture stable)
- No key path changes:
  - `config/keys-sync`
  - `config/keys-sync.pub`
- No schema migrations introduced in Phase 5.

## Residual risks

- Jumphost strict host checking is still compatibility-default (`off`) unless explicitly enabled.
- Environment-specific SSH/jumphost behavior can still vary by host OpenSSH tooling and known-hosts state.
- Sync status messages are now more structured; downstream parsers relying on exact legacy text should be verified.

## Rollback strategy (Phase 5)

- Revert Phase 5 commits in reverse order if needed:
  - spawn failure handling
  - non-fatal status-file classification
  - failure classification helper
  - jumphost diagnostics/trust options
  - runtime timeout abstraction
- Existing legacy sync execution path remains callable via `scripts/sync.php`.
