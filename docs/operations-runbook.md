# SKA Operations Runbook

Date: 2026-02-10

## 1. Purpose

This runbook defines practical day-to-day workflows for operating and validating SKA after modernization phases 0-10.

## 2. Environment setup

1. Copy config template and configure:
   - `config/config.ini.example` -> `config/config.ini`
2. Ensure sync key pair paths exist:
   - `config/keys-sync`
   - `config/keys-sync.pub`
3. Use Docker deployment as the default runtime path.

## 3. Local validation workflow

Run all baseline quality gates as a non-root user:

```bash
make ci-check
```

If you run checks in Docker, prefer user mapping so Composer does not run as root:

```bash
docker compose run --rm --user "$(id -u):$(id -g)" ska-app make ci-check
```

Security note:
- Avoid `COMPOSER_ALLOW_SUPERUSER=1` for local/dev workflows.
- If a locked-down CI runner requires root, document why and keep the CI image ephemeral, minimal, and network-restricted.

Run smoke harness script validation:

```bash
make smoke-dry-run
```

Run full smoke workflows:

```bash
make smoke
```

If using a local env file for smoke variables:

```bash
source testenvs.env
make smoke
```

## 4. Smoke prerequisites

Required variables for web smoke:
- `SKA_SMOKE_BASE_URL`
- `SKA_SMOKE_USERNAME`
- `SKA_SMOKE_PASSWORD`
- `SKA_SMOKE_ACCESS_SERVER_HOSTNAME`
- `SKA_SMOKE_ACCESS_ACCOUNT_NAME`
- `SKA_SMOKE_ACCESS_SOURCE_USER`

Required variable for sync smoke:
- `SKA_SMOKE_SYNC_SERVER_ID`

Reference: `docs/smoke-tests.md`

## 5. Deployment checklist

1. Build and start containers.
2. Run quality gates (`make ci-check`).
3. Run smoke harness (`make smoke`).
4. Verify app login manually (`/login`).
5. Verify one sync preview result against expected output.
6. Verify no secret files are staged for commit.

## 6. Incident triage hints

- `500` on login or account views:
  - Verify `config/config.ini` and DB connectivity.
  - Check web/PHP logs for class loading or migration errors.
- Smoke `404`/`500` in access add/remove:
  - Verify `SKA_SMOKE_BASE_URL` path and target server/account variables.
  - Confirm target account page loads directly.
- Sync mismatch:
  - Run `php scripts/sync.php --diagnostics`.
  - Re-run `make smoke-sync` and inspect fixture drift.

## 7. Rollback guidance

1. Revert latest modernization commit(s) in reverse order.
2. Re-run `make ci-check` and `make smoke`.
3. If rollback targets frontend migration slices, keep compatibility assets in place unless they are the known root cause.
4. If rollback targets runtime-state changes, use the tagged pre-change commit where global fallback behavior is known-good.
