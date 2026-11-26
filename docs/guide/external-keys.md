# Unmanaged keys

The sync writes SKA's key files under `/var/local/keys-sync/` and does not touch `~/.ssh/authorized_keys`.
If sshd on a target server still reads `~/.ssh/authorized_keys`, keys placed there by hand work alongside SKA
and bypass its access rules. SKA calls these *external keys*. It does not manage them, but it looks for them,
lists them for review and removes the ones you deny from those files.

This is done by `scripts/supervise_external_keys.php`, which cron runs every 5 minutes.

## What the scan looks at

Each run connects as `keys-sync` to every server whose key management is set to SSH Key Authority
(servers set to another system, to disabled or to decommissioned are skipped). On each server it:

1. Decides whether `~/.ssh/authorized_keys` is in use at all (see [How the sshd check works](#how-the-sshd-check-works)).
   If not, or if the server's key supervision setting is "Disabled", no files are read.
2. Reads `/etc/passwd` and skips accounts whose shell is `/bin/false`, `/usr/bin/false` or a `nologin`
   shell in `/bin`, `/sbin`, `/usr/bin` or `/usr/sbin`.
3. For each remaining account (or only `root`, see below) reads `<home>/.ssh/authorized_keys` and
   `<home>/.ssh/authorized_keys2`. A missing file is not an error.
4. Parses every line that is not empty and does not start with `#`. Key options in front of the key type are
   accepted. The key comment is kept.
5. Removes keys you have denied from these files.
6. Writes the [status file](#status-file-and-monitoring) and refreshes the server facts used by
   [Troubleshoot access](troubleshoot-access.md) when they are missing, at least 15 minutes old, or a
   refresh has been requested.

A key is identified by its base64 key data only. The same key in several accounts or on several servers is
one entry in SKA with several *occurrences* (account, server, comment).

### Key supervision setting per server

Administrators set this on the server page, in the Settings tab, under "Key supervision":

| Setting | Stored value | Accounts scanned |
| --- | --- | --- |
| Scan keys of root and all user accounts | `full` | every active account in `/etc/passwd` |
| Scan only keys of the root account, no other user accounts | `rootonly` | only the account named `root`, if its shell is active |
| Disabled - Do not scan any keys | `off` | none |

New servers, whether added one by one or imported, get the value of `[general] default_key_supervision`.
`rootonly` gives `rootonly`. `off`, an empty value and the other values PHP's ini parser turns into an empty
string (`no`, `false`, `none`) give `off`. Every other value gives `full`. The key has no built-in default:
without it, adding a server fails with an error. Changing the default later does not affect existing servers. See [Configuration reference](../reference/configuration.md#general).

With "Disabled" the script still connects to the server, writes the status file and collects server facts;
it only skips reading `authorized_keys` files.

### How the sshd check works

The script reads `/etc/ssh/sshd_config` and then every file in `/etc/ssh/sshd_config.d/` in sorted order. It
looks at each `AuthorizedKeysFile` line and keeps the result of the last one it sees: if the value contains
`.ssh/authorized_keys`, the files are considered in use, otherwise not. Without any `AuthorizedKeysFile`
line the files are considered in use, as that is the sshd default.

!!! warning
    This check is simpler than sshd. sshd uses the first `AuthorizedKeysFile` it finds and honours `Include`
    position and `Match` blocks; this check uses the last one, reads every file in `sshd_config.d/` (not only
    `*.conf`) after the main file, and treats `AuthorizedKeysFile` lines inside `Match` blocks like global
    ones. On some configurations it decides that
    `authorized_keys` is not in use when sshd does read it, and then nothing is scanned and no error is
    shown. The [Troubleshoot access](troubleshoot-access.md) tool evaluates the sshd configuration the way
    sshd does and is the better source for a single login.

## What `keys-sync` needs on the target server

The scan runs as `keys-sync`, not as root, and SKA does not change any permissions for it. For each scanned
account, `keys-sync` must be able to:

- enter the home directory and `~/.ssh`,
- read `authorized_keys` and `authorized_keys2` if they exist,
- write them, so that denied keys can be removed.

Anything else is reported as a [scan error](#scan-errors): a home directory that cannot be entered gives
"Permission denied" even when there is no key file, and every key file that `keys-sync` can read but not
write gives a "not writable" warning, whether or not it holds a denied key. On most systems this means granting access
explicitly, for example with POSIX ACLs. If you do not want that, use "Scan only keys of the root account"
or "Disabled" for the server.

See [Prepare target servers](../getting-started/target-servers.md) for the rest of the target server setup.

## Reviewing keys

The **Public keys** page has four tabs. "Managed keys" lists the keys uploaded to SKA. The other three list
external keys:

| Tab | Contents | Buttons |
| --- | --- | --- |
| New keys | keys found by the scan that nobody has decided on yet, followed by the scan errors of all servers whose key management is SSH Key Authority | Allow, Deny |
| Allowed keys | keys marked as allowed | Deny |
| Denied keys | keys marked as denied | Allow |

Each entry shows the key type and data and a table of occurrences: `account@hostname` and the key comment
found there.

A decision applies to the key everywhere: allowing or denying a key affects every account and every server
it is on, now and in the future.

!!! warning
    The page is in the main menu for every user, and the view does not check for administrator rights before
    accepting Allow or Deny. Any user who can log in to SKA can allow or deny an external key. SKA records no
    entry in its activity log for these decisions; only "Allow" sends an email (see below).

### Allow

The key's status becomes "allowed". SKA leaves it in place wherever it appears and sends an email with the
subject "Key has been allowed" to `[email] report_address`, naming who allowed it and linking to the Allowed
keys tab.

### Deny

The key's status becomes "denied". No email is sent. On the next scan (cron starts one every 5 minutes, and
skips a start while the previous run is still going), the script
rewrites each scanned `authorized_keys` and `authorized_keys2` file that contains the key without the lines
holding it. Each removal is printed to the script's output, which cron appends to
`/var/log/ska/supervise_external_keys.log` in the shipped crontab:

```text
2026-11-26T10:15:02+00:00 /home/alice/.ssh/authorized_keys: Removed key with comment alice@laptop
```

The removal only reaches files the scan reads: accounts skipped by the server's setting, accounts with an
inactive shell, servers where the sshd check says `authorized_keys` is not in use, and other paths named in
`AuthorizedKeysFile` are left alone. If a file cannot be written, the key stays and the failure is recorded
as a scan error.

## How keys come and go

After reading all servers, the script compares what it found with the database:

| Situation | What SKA does |
| --- | --- |
| Key found that SKA has never seen | stored with status "new", an email is sent |
| Known key found (any status) | occurrences updated: new locations added, vanished ones removed; no email |
| Key with status "new" no longer found anywhere | the entry is deleted |
| Allowed or denied key no longer found anywhere | the status is kept, the occurrence list becomes empty |

A deleted "new" key that turns up again later counts as never seen: it is stored again and emailed again.
An allowed or denied key keeps its status, so a denied key that is added again is removed again on the next
scan.

!!! note
    A server that cannot be scanned in a run contributes no occurrences to that run. Its occurrences disappear
    from the list until the next successful scan, and a "new" key found only on that server is deleted and
    reported again by email once the server is reachable. The same happens when a server's setting is changed
    to "Disabled".

## Email about new keys

When a key is stored for the first time, one email goes out per key. It contains the key type and data and,
depending on how many places it was found in, the account and comment, or a table of accounts and comments
per server. Subjects are "New ssh key appeared on account@host", "New ssh key appeared on host" or
"New ssh key appeared on multiple servers".

Recipients:

- **To**: the leaders of the affected accounts that exist as active server accounts in SKA, and the leaders
  of each affected server (members of active leader groups included). A server's leaders move to Cc when
  every active account SKA has for that server is affected and has leaders of its own, and also when SKA
  has no active accounts for that server.
- **Cc**: `[email] report_address`, always.

Mail is only sent when `[email] enabled` is set, and `[email] reroute` applies as for all SKA mail. See
[Configuration reference](../reference/configuration.md#email).

A known key that appears in a further account or on a further server does not trigger an email. Check the
New keys tab, or rely on the monitoring warning below.

## Scan errors

Problems found during a scan are stored per server and shown in two places:

- on the server page, as a red box "Failed to supervise external keys on this server",
- on the Public keys page, at the bottom of the New keys tab, under "Errors while scanning for new keys".

Both places show only servers whose key management is SSH Key Authority. The errors are replaced on every
run and disappear after a run without errors. Typical messages:

| Message | Cause |
| --- | --- |
| `Exception while reading keys of <host>: ...` | connection failed, or `sshd_config`, a file in `sshd_config.d/` or `/etc/passwd` could not be read |
| `Could not read <file>: Permission denied` | `keys-sync` cannot enter the home directory or read the key file |
| `The file <file> is not writable for the keys-sync user. ...` | `keys-sync` can read the file but not write it, so denied keys cannot be removed from it |
| `Line <n> in <file> has an invalid format.` | the line is not a key line the scan understands |
| `Removing 'denied' keys from <file> failed: ...` | writing the cleaned file failed |
| `Could not save status info in <file>: ...` | the status file could not be written |

The line number in "invalid format" messages does not count lines removed in the same run, so it can be
too low when a denied key was removed above it.

The parser only accepts key types beginning with `ssh-` or `ecdsa-`. Lines with other types, such as the
security key types `sk-ssh-ed25519@openssh.com` and `sk-ecdsa-sha2-nistp256@openssh.com`, are reported as
invalid and are not listed as external keys, so they cannot be denied or removed through SKA.

## Status file and monitoring

Both the sync and this script write a JSON status file on the target server, by default
`/var/local/keys-sync.status` (`[monitoring] status_file_path`, see
[Configuration reference](../reference/configuration.md#monitoring)). Relevant to unmanaged keys are:

- `key_supervision_error`: the stored scan errors, or `null`,
- `accounts_with_unnoticed_keys`: accounts holding a key that is still "new" and was first seen there at
  least 96 hours ago,
- `expire`: the time of writing plus `[monitoring] status_file_timeout` seconds (7200 if the key is
  missing; the example configuration sets 93600).

`keys-sync` must be able to write the file; see
[Prepare target servers](../getting-started/target-servers.md#status-file). If writing fails, the error is
added to the server's scan errors.

`monitoring/checkmk_script.py` is a Checkmk local check that reads `/var/local/keys-sync.status` (the path is
fixed in the script). It reports WARN when there is a scan error, when the file has expired, when the sync
status is not "sync success", and when there are unnoticed keys:

```text
1 keys_sync_status - There have been new external keys for at least 96 hours on following accounts: (root, alice) - Please allow or deny them in the SKA web ui.
```

Because the scan rewrites the file every 5 minutes on reachable servers whose key management is SSH Key
Authority, an expired file usually means SKA could not connect for longer than the timeout, or the server's
key management was changed. See [Monitoring and logs](../operations/monitoring.md#checkmk-local-check).

## Running the scan by hand

Run the script as the same user as the cron job, from any directory:

```bash
php /srv/keys/scripts/supervise_external_keys.php
```

It prints one block per server ("Reading external ssh keys from ...", the `sshd_config.d` files it reads and
any removals). Scan errors are mostly not printed; they are stored for the server pages as described above.
Which user runs the job depends on the installation: see [Install with Docker](../getting-started/docker.md#who-reads-the-private-key)
and [Install without Docker](../getting-started/bare-metal.md#cron-jobs).
