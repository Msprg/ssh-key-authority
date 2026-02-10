# Smoke Test Harness (Phase 2)

This harness validates critical SKA workflows while modernization work is in progress.

## Covered workflows

- LDAP login/auth flow
- Public key add/remove flow (for the authenticated user)
- Access rule add/remove flow (for a target server account)
- Sync preview output diff against a recorded fixture

## Commands

- Dry-run script validation (safe for CI):

```bash
make smoke-dry-run
```

- Run all smoke checks:

```bash
make smoke
```

- Run only web workflows:

```bash
make smoke-web
```

- Run only sync fixture comparison:

```bash
make smoke-sync
```

- Record/update sync fixture snapshot:

```bash
make smoke-sync-record
```

## Required environment variables

### Web workflows (`make smoke-web` or `make smoke`)

- `SKA_SMOKE_BASE_URL` (example: `http://localhost:8080`)
- `SKA_SMOKE_USERNAME`
- `SKA_SMOKE_PASSWORD`
- `SKA_SMOKE_ACCESS_SERVER_HOSTNAME`
- `SKA_SMOKE_ACCESS_ACCOUNT_NAME`
- `SKA_SMOKE_ACCESS_SOURCE_USER`

Notes:
- The authenticated account must have privileges to manage target access rules.
- `SKA_SMOKE_ACCESS_SOURCE_USER` must not already have access to the target account before the run.

### Sync preview snapshot (`make smoke-sync`, `make smoke-sync-record`, or `make smoke`)

- `SKA_SMOKE_SYNC_SERVER_ID`

Optional:
- `SKA_SMOKE_SYNC_USERNAME` (limit preview to one account)
- `SKA_SMOKE_SYNC_FIXTURE` (custom fixture file path)

## Fixture behavior

Sync preview fixtures are stored under `scripts/smoke/fixtures/sync/`.

During comparison, the harness normalizes output by stripping:
- ANSI color escape sequences
- timestamp prefixes

That keeps fixture diffs focused on keyfile content behavior.

## CI behavior

CI runs only `composer run smoke:dry-run`, which validates harness scripts without requiring LDAP/SSH or environment-specific credentials.

## Sync troubleshooting

If sync process spawning fails due to timeout utility differences across environments, inspect runtime detection output:

```bash
php scripts/sync.php --diagnostics
```
