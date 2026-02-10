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

- Environment-specific SSH/jumphost behavior can still vary by host OpenSSH tooling and known-hosts state.
- Sync status messages are now more structured; downstream parsers relying on exact legacy text should be verified.

## Migration note: Jumphost strict host checking default change

- Default changed:
  - `jumphost_strict_host_key_checking` is now `on` by default (`1`) in `config/config.ini.example`.
- Why:
  - Disabling Jumphost strict host checking (`off`) increases man-in-the-middle risk in tunnel setup.
- Migration steps for existing environments:
  1. Ensure jumphost and target host keys are present in your configured known-hosts file.
  2. Set `jumphost_known_hosts_file` to a managed file path (for example `/etc/ssh/ssh_known_hosts`).
  3. Enable `jumphost_strict_host_key_checking = 1`.
  4. Run `php scripts/sync.php --diagnostics` and validate expected trust settings.
  5. Run `make smoke-sync` (or full `make smoke`) before rollout.
- Temporary compatibility mode:
  - You can temporarily set Jumphost strict host checking to `off` (`0`) for emergency compatibility only.
  - Security warning: `off` should be treated as temporary because it weakens host authenticity guarantees and increases MITM exposure.
  - Deprecation intent: plan to remove long-term reliance on `off` in a follow-up hardening cycle.

## Rollback strategy (Phase 5)

- Revert Phase 5 commits in reverse order if needed:
  - spawn failure handling
  - non-fatal status-file classification
  - failure classification helper
  - jumphost diagnostics/trust options
  - runtime timeout abstraction
- Existing legacy sync execution path remains callable via `scripts/sync.php`.
