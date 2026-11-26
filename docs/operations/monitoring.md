# Monitoring and logs

SKA does not send alerts when a sync fails or a server cannot be reached. What it offers is a sync status per server in the web interface and in JSON,
a status file on every managed server for a local monitoring agent, a Checkmk check that reads that file,
and plain-text logs. This page lists them and suggests what to watch.

## Where the logs are

### Docker

| Source | Where it goes |
| --- | --- |
| Sync daemon (`syncd.php`) and the output of every sync it starts | container output (`docker compose logs ska-app`) |
| nginx access and error log | container output |
| supervisord's own messages | container output |
| php-fpm master log | `/var/log/php8.2-fpm.log` inside the container (Debian's default, not changed by the image) |
| `scripts/ldap_update.php` (every minute) | `/var/log/ska/ldap_update.log` |
| `scripts/supervise_external_keys.php` (every 5 minutes) | `/var/log/ska/supervise_external_keys.log` |

supervisord passes the stdout and stderr of its four programs (php-fpm, nginx, cron, keys-syncd) on as the
container output. The cron jobs append to the files in `/var/log/ska`, which is a
volume. Nothing in the image rotates these two files. `ldap_update.php` prints a block for every user and
group on every run, so `ldap_update.log` grows quickly; rotate it on the host. The health check requests
(every 30 seconds) also appear in the nginx access log.

### Without Docker

- The systemd unit `services/systemd/keys-sync.service` sends the daemon's output to the journal:
  `journalctl -u keys-sync`. Started without `--systemd`, the daemon writes to `/var/log/keys/sync.log`.
- The cron jobs write wherever your crontab redirects them; the example uses the same two files in
  `/var/log/ska`.
- nginx and php-fpm log to the locations your distribution configures.

See [Install without Docker](../getting-started/bare-metal.md#cron-jobs) for the crontab and the
ownership the log files need.

!!! warning
    If a cron job's log file is not writable by the user the job runs as, the shell cannot open it and the
    job never runs. Nothing in the web interface shows this. Check that the log files keep growing.

### What the sync output looks like

Each sync prints lines prefixed with an ISO 8601 timestamp and the hostname (the error line "Sync command
execution failed for ..." has only the hostname):

```text
2026-03-02T09:15:01+00:00 web1.example.com: Preparing sync.
2026-03-02T09:15:01+00:00 web1.example.com: Attempting to connect.
2026-03-02T09:15:02+00:00 web1.example.com: No changes required for root
2026-03-02T09:15:02+00:00 web1.example.com: Updated deploy
2026-03-02T09:15:03+00:00 web1.example.com: Sync finished
```

A failed connection prints the reason instead of "Sync finished". The daemon itself logs "Daemon started",
"Sync process spawning for: <server id>/<account>", "MySQL server has gone away" and "MySQL connection
re-established" when it loses and regains the database, and "Received exit signal" on shutdown. Run with
`--systemd`, as it is in the image and in the systemd unit, the daemon's own lines carry no timestamp. It
prints the output of a sync process when that process ends; if the process wrote anything to stderr, only
the stderr text is printed.

### Syslog

Every logged event (changes to users, groups, servers, accounts and access, and every change of a server's
sync status message) is written to the database and to syslog with the ident `keys` and facility `auth`. A
line looks like this:

```text
keys: KeysScope="server:web1.example.com" KeysRequester="keys-sync" KeysAction="Sync status change" KeysValue="Synced successfully"
```

Double quotes inside values are removed. On a Debian host with rsyslog's default rules these lines land in
`/var/log/auth.log`. Applied database migrations are logged with the ident `dnsui`.

The Docker image runs no syslog daemon, so inside the container these lines are lost. The same events
are still kept in the database and shown on the server, account and group pages and on the **Activity** page.

### Web application errors

When a page fails, SKA logs the exception through PHP's error log, with every line prefixed by a number (a
Unix timestamp). The error page's "provide additional information" mail link carries that number in its
subject. Users with the developer flag instead see the exception and its stack trace on the page. To find the entry, search for the
number in the container output and in `/var/log/php8.2-fpm.log` inside the container, or in your php-fpm and
nginx logs on a host install.

## Sync status in the web interface

- For a server whose key management is "Managed by SSH Key Authority", the **server page** shows the
  message of the last sync status change, "Pending" with a spinner while a
  request is due, and an **Explain** link to the help for warnings and errors. It polls until the sync has
  finished. **Sync now** queues a full sync of the server.
- Each account on the server page shows "Synced", "Failed", "Not synced", "Requested" or "Pending".
- The **Servers** list can be filtered by sync status: "Sync success", "Sync warning", "Sync failure" and
  "Not synced yet".
- Errors from the search for unmanaged keys are shown on the server page and at the bottom of the **New
  keys** tab of the Public keys page. See [Unmanaged keys](../guide/external-keys.md#scan-errors).

The meaning of each status message is described in
[How keys reach servers](../concepts/key-distribution.md#failures-and-retries).

## JSON status endpoints

These URLs answer with JSON. They use the normal web session: there are no API tokens, so a script that
calls them must hold the session cookie of a logged-in LDAP user. Any logged-in user can read them for any
server.

| URL | Content |
| --- | --- |
| `/servers/<hostname>/sync_status` | Server status, last status change, pending flag, accounts |
| `/servers/<hostname>/accounts/<account>/sync_status` | One account's status and pending flag |
| `/servers/<hostname>/status.json` | Server record including the last status change |

`/servers/web1.example.com/sync_status` returns, for example:

```json
{
  "sync_status": "sync success",
  "last_sync": {"date": "2026-03-02 09:15:03", "details": "Synced successfully"},
  "accounts": [
    {"name": "root", "sync_status": "sync success", "pending": false}
  ],
  "pending": false
}
```

- `sync_status` is one of `not synced yet`, `sync success`, `sync failure` and `sync warning`; for
  accounts also `proposed`. The current sync never sets `sync warning`.
- `last_sync` is the last *change* of the status message, not the last sync. A message is only logged when
  it differs from the previous one, so a server that has synced successfully every day for a month still
  shows the date of its first "Synced successfully". `last_sync` is `null` before the first sync. Dates are
  UTC.
- The server's `pending` counts only requests that are due now. A retry scheduled 30 minutes ahead after a
  failure does not count. An account's `pending` counts every request for the server or the account,
  including such a scheduled retry.

`/servers/<hostname>/status.json` returns `uuid`, `hostname`, `key_management`, `sync_status`, `host_key`
and `last_sync_event` with `date` and `details`. Here `details` is the raw event as a JSON string, for
example `"{\"action\":\"Sync status change\",\"value\":\"Synced successfully\"}"`. Any other extension than
`.json` returns the normal server page.

More endpoints are listed in [CLI scripts and JSON endpoints](../reference/cli-and-api.md).

## Status file on target servers

After every sync that got connected, and on every run of `supervise_external_keys.php` that could connect,
SKA writes a JSON file to each managed server. Its path and expiry are set in `[monitoring]`
(`status_file_path`, default `/var/local/keys-sync.status`, and `status_file_timeout`); see the
[Configuration reference](../reference/configuration.md#monitoring).

```json
{
  "warn_below_version": 1,
  "sync_status": "sync success",
  "sync_status_message": "Synced successfully",
  "key_supervision_error": null,
  "accounts_with_unnoticed_keys": [],
  "expire": "Mon, 02 Mar 2026 11:15:03 +0000"
}
```

| Field | Meaning |
| --- | --- |
| `warn_below_version` | Lowest version of the check script that understands the file (currently 1) |
| `sync_status` | The server's status in SKA |
| `sync_status_message` | Message of the last status change, or `null` |
| `key_supervision_error` | Stored errors of the unmanaged key search, or `null` |
| `accounts_with_unnoticed_keys` | Accounts with an unmanaged key that is still "new" and was first seen at least 96 hours ago |
| `expire` | Time of writing plus `status_file_timeout` seconds (RFC 2822) |

Things to know:

- The file is only written over a working connection. When SKA cannot reach a server, the file keeps
  saying whatever it said last, including "sync success", until `expire` passes. The expiry is what tells
  you that SKA has lost contact.
- `status_file_timeout` is 7200 seconds when the key is missing from `config.ini`; the example
  configuration sets 93600 (26 hours). The 5-minute supervision run normally rewrites the file, so a few
  hours is usually enough. There is no scheduled full sync, so if the supervision job cannot connect, the
  file is only renewed when a change or a retry triggers a sync, and a long timeout is needed to avoid
  false alarms.
- The supervision job only handles servers whose key management is "Managed by SSH Key Authority". A
  decommissioned server only gets the file when a decommission sync connects to it (normally once, again
  after a failed attempt is retried), and then it expires; remove the check there.
- `keys-sync` must be able to write the file. It normally cannot create files in `/var/local`, so create
  the file beforehand and give it to `keys-sync`. A failed write does not fail the sync; it is
  recorded as a key supervision error on the server. See
  [Prepare target servers](../getting-started/target-servers.md#status-file).
- The supervision job reads `config/keys-sync`. If the user it runs as cannot read the key, it fails on
  every server and never renews the file. See
  [Install with Docker](../getting-started/docker.md#who-reads-the-private-key).

## Checkmk local check

`monitoring/checkmk_script.py` is a Checkmk local check for target servers. Copy it into the agent's local
check directory (usually `/usr/lib/check_mk_agent/local/`) on each managed server and make it executable.
Its shebang is `#! /usr/bin/env python`; on systems without a `python` command, change it to `python3`.

It reads `/var/local/keys-sync.status`. The path is fixed in the script and does not follow
`status_file_path`; edit the script if you changed it. It reports one service, `keys_sync_status`, and
gives WARN when:

- `warn_below_version` in the file is higher than the script's version (1),
- `sync_status` is anything other than `sync success`, including `not synced yet`,
- `key_supervision_error` is set,
- `expire` has passed,
- `accounts_with_unnoticed_keys` is not empty,
- the script itself fails, for example because the file is missing, unreadable or still empty.

All reasons are joined into one line. Otherwise it reports OK:

```text
0 keys_sync_status - The keys-sync status is OK and up-to-date
1 keys_sync_status - The keys-sync status has expired (Got no update from ssh-key-authority before the expire-time was reached) - Please check the machine's error in the SKA web ui
```

The script never reports CRIT. The empty file created while preparing a server makes the check WARN until the first sync writes it.

For other monitoring systems, read the same file with whatever local check mechanism you use.

## Container health check

The Dockerfile defines this check, run every 30 seconds (5 second timeout, 30 second start period, 3
retries):

```bash
curl -fsS http://127.0.0.1:8080/
```

The compose files in the repository replace it with the same check against `http://localhost:8080/`, with
the same timings.

Without a session the request gets a redirect to `/login`, which counts as healthy. The check shows that
nginx and PHP answer. It does not check the sync daemon, the cron jobs, LDAP or any target server. The
container can be "healthy" while no key is distributed.

To see whether the daemon and cron are running inside the container:

```bash
docker compose exec ska-app supervisorctl status
```

supervisord restarts any of its programs that exits. A program that exits within 5 seconds of being started
is retried a few times (supervisord's default is 3) and then left in the `FATAL` state, where it stays until
you start it again or restart the container.

## What to alert on

| Condition | How to see it |
| --- | --- |
| Container unhealthy, or not running | Docker health status |
| `keys-syncd` or `cron` not running | `supervisorctl status` in the container; `systemctl status keys-sync` without Docker |
| A server's sync failed | Checkmk WARN on that server; Servers list filtered on "Sync failure"; `sync_status` endpoint |
| SKA has lost contact with a server | Expired status file (Checkmk WARN) |
| Unmanaged keys left unreviewed for 96 hours | `accounts_with_unnoticed_keys` (Checkmk WARN) |
| Scan errors on a server | `key_supervision_error` (Checkmk WARN); New keys tab |
| LDAP sync problems | `ERROR` and `WARNING` lines in `ldap_update.log`; a summary line "N user(s) could NOT be synced" at the end of a run. In that case the script also writes the summary to stderr and exits with status 1. |
| Cron jobs silently not running | Log files in `/var/log/ska` no longer growing |
| Log volume filling up | Size of `/var/log/ska` |

A run of `ldap_update.php` whose output does not reach the "LDAP Update Script Finished" banner stopped
before it was done. (The "could NOT be synced" summary, when there is one, comes after that banner.)
A failed sync retries itself every 30 minutes, so a failure that persists means something on the server or
the network needs fixing; [Troubleshoot access](../guide/troubleshoot-access.md) and the help page linked by
**Explain** help find what.

## Running a sync by hand

`scripts/sync.php` syncs from the command line. Run it as `keys-sync`, which can read the sync key:

```bash
# Docker
docker compose exec --user keys-sync ska-app php /srv/keys/scripts/sync.php --host web1.example.com

# Without Docker
sudo -u keys-sync php /srv/keys/scripts/sync.php --host web1.example.com
```

A manual run records the sync status and writes the status file like the daemon does, and a failure
schedules the usual retry. The exit status is 0 even when a sync fails, so read the output or the server's
status instead. All options and exit codes are in
[CLI scripts and JSON endpoints](../reference/cli-and-api.md#syncphp).

From the web interface, **Sync now** on a server page queues the same full sync for the daemon.
