# CLI scripts and JSON endpoints

## CLI scripts

The scripts live in `scripts/` (`/srv/keys/scripts` in the Docker image). Each one loads `core.php`, so it
reads `config/config.ini`, connects to the database and applies any pending schema migrations before doing
its work. `scripts/ssh.php` and `scripts/sync-common.php` are libraries used by the others and are not run
on their own.

| Script | Run as | Started by | Needs the sync key |
|---|---|---|---|
| `sync.php` | `keys-sync` | `syncd.php`, or by hand | Yes |
| `syncd.php` | `keys-sync` | supervisord (Docker), systemd or init script | Yes, through `sync.php` |
| `ldap_update.php` | `www-data` | cron, every minute | No |
| `supervise_external_keys.php` | `www-data` in the shipped crontab; `keys-sync` recommended on a host install | cron, every 5 minutes | Yes |
| `pubkey_update.php` | `www-data` | by hand | No |

The sync key is `config/keys-sync`. Only the users that run the sync daemon and the external key scan
should be able to read it. The shipped `etc/cron.d/ska` runs both cron jobs as `www-data`, so in the Docker
image `www-data` needs read access too (see
[Install with Docker](../getting-started/docker.md#who-reads-the-private-key)). On a host install, run the
scan as `keys-sync` instead (see [Install without Docker](../getting-started/bare-metal.md#cron-jobs)).

### sync.php

Builds the key files for one or more servers and writes them to `/var/local/keys-sync/` on each server over
SSH. How a sync works is described in [How keys reach servers](../concepts/key-distribution.md).

```text
sync.php [OPTIONS]
```

| Option | Meaning |
|---|---|
| `-a`, `--all` | Sync every server whose key management is "keys" or "decommissioned". |
| `-h`, `--host=HOSTNAME` | Sync the named server. Several names may be given, separated by commas. |
| `-i`, `--id=ID` | Sync one server, given by its database id. |
| `-u`, `--user=NAME` | Write only the key file for this account name. |
| `-p`, `--preview` | Change nothing; print the content of the key files. |
| `--help` | Print the usage text and exit. |

Exactly one of `--all`, `--host` and `--id` must be given. Giving both the short and the long form of the
same option is an error.

Behaviour to be aware of:

- With `--all` and `--host`, only servers whose key management is set to "keys" or "decommissioned" are
  synced; others are skipped without a message. Each server is synced in its own `sync.php --id` process,
  up to 20 at a time, each wrapped in `timeout` with a limit of 60 seconds. The `timeout` variant is chosen
  by `[general] timeout_util` (see the [Configuration reference](configuration.md#general)).
- The 60 second limit only stops a sync that has not connected yet. Once the SSH connection is up, the sync
  ignores the SIGTERM that `timeout` sends and runs to the end, so a key file is never left half written.
- `--id` syncs in the current process. Run by hand, it has no 60 second limit. A server with key management "none" or
  "other" is left alone.
- `sync.php` starts its child processes by running the file itself, so it must stay executable and
  `/usr/bin/php` (its interpreter line) must exist.
- For a decommissioned server the sync removes every file in `/var/local/keys-sync/` except `keys-sync` and
  `.hostnames`. `--user` is ignored for such a server.
- `--preview` does not connect to the server and does not change any sync status. It prints each key file
  with terminal colour codes. For a decommissioned server it prints one line saying that all key files except
  `keys-sync` and `.hostnames` would be removed; it does not list them.
- `--user` limits which key file is written. Files of other accounts are neither updated nor removed, and
  unknown files in the key directory are not cleaned up. With `--preview`, `--user` only narrows the LDAP user
  files; the files of all managed accounts are still printed.
- A sync without `--user` removes files in the key directory that SKA did not generate, except `keys-sync`
  and `.hostnames`.
- The account `keys-sync` never gets a key file from SKA.

The result of each server is printed as timestamped lines and recorded as the server's sync status in SKA.
It is not reflected in the exit status.

| Exit status | Meaning |
|---|---|
| 0 | Finished, or `--help`. Also returned when `config/keys-sync` or `config/keys-sync.pub` is missing: the script prints "Sync cannot start - ... not found." and stops. |
| 1 | Not exactly one of `--all`, `--host` and `--id`; the short and long form of an option both given; or a hostname given to `--host` is not in SKA. Unknown options are ignored. |
| other | The PHP process failed (uncaught error). |

Examples:

```bash
# Show what would be written, without connecting
sudo -u keys-sync /usr/bin/php /srv/keys/scripts/sync.php --host server1.example.com --preview

# Sync two servers now
sudo -u keys-sync /usr/bin/php /srv/keys/scripts/sync.php --host server1.example.com,server2.example.com

# In the Docker image
docker compose exec --user keys-sync ska-app php /srv/keys/scripts/sync.php --all
```

### syncd.php

The sync daemon. It reads the pending sync requests from the database (they are created when access changes
in the web interface), marks each one as in progress and starts `sync.php --id <server>` for it, with
`--user <account>` when the request is for one account. About 20 syncs run at the same time. It waits for
the syncs it started, then checks for new requests again after one second. When a sync process exits with a
non-zero status (for example because `timeout` stopped it), the server's status is set to
"sync failure" with "Internal error during sync" and a new sync is scheduled. The request is deleted
afterwards. If the database connection is lost ("MySQL server has gone away"), the daemon reconnects every
5 seconds until it succeeds.

| Option | Meaning |
|---|---|
| `--systemd` | Stay in the foreground and log to standard output without timestamps. Used by supervisord in the Docker image and by `services/systemd/keys-sync.service`. |
| `--user=NAME` | Without `--systemd`: the user to switch to after forking. Required in that mode. |

Without `--systemd` the daemon must be started as root. It takes the lock `/var/run/keys-sync.lock`, forks,
writes the child's pid to `/var/run/keys-sync.pid`, opens `/var/log/keys/sync.log` for appending (still as
root, so the directory `/var/log/keys` must exist) and then switches to the given user. In `--systemd` mode no
lock is taken, so nothing prevents two daemons from running at once.

!!! warning
    The shipped `services/init.d/keys-sync` starts `syncd.php` without any arguments (its `--user` is an
    option of `start-stop-daemon`, not of the script). The daemon then exits with "--user parameter must be
    provided". Pass `--user keys-sync` to the script, or use the systemd unit.

The daemon stops after SIGTERM or SIGINT, at the end of its current loop.

| Exit status | Meaning |
|---|---|
| 0 | Stopped by a signal; the parent process after a successful fork; or the lock is held by another daemon ("Could not establish lock, process already running?"). |
| 1 | Without `--systemd`: `--user` missing, not run as root, the user does not exist, or the fork failed. |

Setup for each init system is in [Install with Docker](../getting-started/docker.md) and
[Install without Docker](../getting-started/bare-metal.md).

### ldap_update.php

Brings the users and groups that SKA already knows up to date with LDAP. It takes no options.

- Creates the group named in `[ldap] admin_group_cn` if it does not exist, and stores its LDAP GUID if none
  is stored yet and the group is found in LDAP.
- For each LDAP user in SKA: refreshes name, email and active state from LDAP (and the superior, if
  `user_superior` is configured), then the group memberships. A user that is no longer found in LDAP is set
  inactive.
- When a user changes from active to inactive in a run and a server the user led is left without any active
  leader, an email about the orphaned server is sent to the user's nearest active superior (when
  `user_superior` is configured) with a copy to the report address. If no such superior is found, it goes to
  the report address only.
- If an entry lacks the name or email attribute and SKA has a stored value, the stored value is kept and a
  warning is printed. If an attribute SKA cannot work without is missing, the user is left unchanged and the
  run is reported as failed.
- Renames groups that have an LDAP GUID when their name in LDAP has changed.

The output is verbose: one block per user and per group. Directory setup is covered in
[LDAP and Active Directory](../operations/ldap.md).

| Exit status | Meaning |
|---|---|
| 0 | All users were processed. |
| 1 | At least one user could not be synced. The list is printed at the end and also written to standard error. |
| other | The PHP process failed, for example because LDAP or the database could not be reached. |

### supervise_external_keys.php

Scans every server with key management "keys" for SSH keys that SKA does not manage. It takes no options.
For each server it connects with the sync key and, depending on the server's "Key supervision" setting, reads
`~/.ssh/authorized_keys` and `~/.ssh/authorized_keys2` of root only or of every account in `/etc/passwd`
whose shell is not `false` or `nologin`. Nothing is read when the setting is "Disabled" or when the last
`AuthorizedKeysFile` line in `/etc/ssh/sshd_config` and `/etc/ssh/sshd_config.d/` does not contain
`.ssh/authorized_keys`.

- A key seen for the first time is stored as "new" and an email goes to the account and server leaders,
  with a copy to the report address.
- Lines with a key whose status is "denied" are removed from the files it reads.
- Errors per server are stored and shown on the Public keys page; they are not printed as failures and do
  not change the exit status.
- It rewrites the status file on each server it reached (`[monitoring] status_file_path`, see the
  [Configuration reference](configuration.md#monitoring)) and refreshes the server facts used by
  [Troubleshoot access](../guide/troubleshoot-access.md) when they are older than 15 minutes or a sync has
  asked for a refresh.

The script always ends with status 0 unless the PHP process itself fails. The handling of found keys is
described in [Unmanaged keys](../guide/external-keys.md).

### pubkey_update.php

Re-reads every stored public key and saves the type, key size, fingerprints and randomart computed from it
again. The minimum key size is not enforced here, so no existing key is rejected for being too short. A key
that cannot be parsed is reported as `Invalid public key <id>` and left as it is. It takes no options and
does not contact any server and is not scheduled. The exit status is 0 unless the PHP process fails.

## JSON and text endpoints

Several pages have a machine-readable form, selected by a `.json` or `.txt` suffix. They are read-only `GET`
requests.

### Authentication

There are no API tokens and no anonymous access. Every route except `/login` requires a logged-in session of
an LDAP user who is active in SKA. A request without one is answered with a `303` redirect to `/login`, not
with `401`, so check for the redirect in scripts. Any logged-in user may read every endpoint below; the only
difference for administrators is the `admins` field in `/servers.json`.

A script can log in through the login form. The form needs the session cookie and the `csrf_token` from the
login page:

```bash
jar=$(mktemp)
token=$(curl -s -c "$jar" https://ska.example.com/login \
  | sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p')
curl -s -b "$jar" -c "$jar" -o /dev/null \
  --data-urlencode "csrf_token=$token" \
  --data-urlencode "username=svc-ska-reader" \
  --data-urlencode "password=$SKA_PASSWORD" \
  https://ska.example.com/login
curl -s -b "$jar" https://ska.example.com/servers.json
```

The session expires after `[security] session_timeout` seconds without a request (see the
[Configuration reference](configuration.md#session-timeout)). Wrong credentials count towards the login
form's limit: after five failed attempts for a username, it is refused for 15 minutes from the last one. That
counter is kept in the session (see [For users](../guide/users.md#failed-attempts-and-lockout)).

!!! warning
    `POST` requests from a logged-in session are accepted without a CSRF token when they carry the header
    `X-Bypass-CSRF-Protection: 1`. The session cookie is `SameSite=Strict`. See
    [Security](../operations/security.md).

### Endpoints

| Path | Returns |
|---|---|
| `/pubkeys.json` | All public keys, without key data |
| `/pubkeys/{id}.json`, `/pubkeys/{id}.txt` | One public key |
| `/users/{username}/pubkeys.json`, `.txt` | The keys of one user |
| `/users/{username}/pubkeys/{id}.json`, `.txt` | One public key (same as `/pubkeys/{id}`) |
| `/servers/{hostname}/accounts/{account}/pubkeys.json`, `.txt` | The keys of one server account |
| `/servers.json` | Servers, filtered like the Servers page |
| `/servers/{hostname}/status.json` | One server and its last sync result |
| `/servers/{hostname}/sync_status` | Sync state of a server and its accounts |
| `/servers/{hostname}/accounts/{account}/sync_status` | Sync state of one account |
| `/groups/{group}/members.json` | Direct members of a group |

An unknown user, server, account, group or key id gives the `404` HTML page. A suffix other than the ones
listed returns the normal HTML page, except on `/servers/.../accounts/.../pubkeys.*`, which then returns an
empty response. JSON is sent as `application/json`, except `/pubkeys.json`, which is sent as `text/plain`.

### Public keys

Each key in a JSON response has these fields:

| Field | Content |
|---|---|
| `keydata` | The key in OpenSSH format: type, key, comment |
| `creation_date` | When it was added |
| `type`, `keysize` | Algorithm and size in bits |
| `fingerprint_md5` | Colon-separated hex |
| `fingerprint_sha256` | Base64, without the `SHA256:` prefix and without padding |
| `fingerprint` | Same as `fingerprint_md5`, kept for old clients |
| `deletion_date` | `null`, or when the key was deleted |
| `owner` | `type` (`User` or `ServerAccount`), `name`, and `uid` for a user or `hostname` for a server account |

- `/pubkeys/{id}.json` has all fields. `.txt` is the key as one line.
- `/users/{username}/pubkeys.json` and the server account variant return an array of keys without `owner`.
  Deleted keys are included, after the others. In the `.txt` form each key is one line and deleted keys are
  prefixed with `# (deleted) `, which makes them comment lines.
- `/pubkeys.json` returns `{"public_keys": [...]}` without `keydata`, deleted keys included. It accepts the
  filters of the Public keys page as query parameters: `fingerprint` (exact MD5 value, or SHA256 value
  without the `SHA256:` prefix), `type`
  (for example `ssh-ed25519`), `keysize-min` and `keysize-max`.
- Only a numeric id gives a JSON or text answer. Any other value in `{id}` is looked up as a fingerprint and
  redirected to the HTML page of the key, or to the search, and the suffix is lost. To find a key by
  fingerprint, use `/pubkeys.json?fingerprint=...`.
- In `/users/{username}/pubkeys/{id}`, the username is not checked against the key's owner.

### Servers

`/servers.json` returns `{"servers": [...]}`. Each server has `uuid`, `hostname`, `port`, `jumphosts` (an
array), `key_management`, `sync_status`, `last_sync_event` and, for administrators only, `admins`: the
user names of the server's leader users plus the direct user members of its active leader groups. Inactive
leader groups and groups nested in a leader group are not expanded.

Without parameters it lists servers with key management "keys" in any sync status. The query parameters are
those of the Servers page:

| Parameter | Filter |
|---|---|
| `hostname` | MySQL regular expression matched against the hostname |
| `ip_address` | Exact IP address |
| `key_management` | `keys`, `none`, `other`, `decommissioned`; several separated by `;` |
| `sync_status` | `not synced yet`, `sync success`, `sync warning`, `sync failure`; several separated by `;` |

An invalid `hostname` expression gives an empty list. When a parameter is given as an array or equals its
default, SKA redirects (`303`) to a shorter URL first, so let the client follow redirects (`curl -L`):

```bash
curl -sL -b "$jar" 'https://ska.example.com/servers.json?key_management=keys;decommissioned&sync_status=sync%20failure'
```

`/servers/{hostname}/status.json` returns `uuid`, `hostname`, `key_management`, `sync_status`, `host_key` and
`last_sync_event`. The server may also be given by its UUID; that redirects to the HTML page of the server.

In both, `last_sync_event` is `null` or has `date` and `details`. `details` is the stored event as a JSON
string, for example `{"action":"Sync status change","value":"Synced successfully"}`, so it has to be decoded
a second time. A sync event is only stored when the sync message differs from the previous one, so `date`
(UTC) is when the message last changed, not when the server was last synced.

### Sync status

The server and account pages poll these endpoints to update the sync indicator. They have no suffix.

`/servers/{hostname}/sync_status` returns:

```json
{
  "sync_status": "sync success",
  "last_sync": {"date": "2026-11-26 10:15:02", "details": "Synced successfully"},
  "accounts": [{"name": "root", "sync_status": "sync success", "pending": false}],
  "pending": false
}
```

`last_sync` is the same event as `last_sync_event` above (`null` if there is none); here `details` is already
the plain message. The server `pending` is true while any sync request for the server or one of its accounts
is due, including one that is being processed. An account's `pending` is true while any request exists for
that account or for the whole server, even one scheduled for later. `accounts` lists the active accounts of
the server. Account sync status can also be `proposed`.

`/servers/{hostname}/accounts/{account}/sync_status` returns only `sync_status` and `pending` for the account.

### Group members

`/groups/{group}/members.json` returns:

```json
{
  "users": [{"uid": "alice", "email": "alice@example.com"}],
  "server_accounts": [{"name": "deploy", "hostname": "server1.example.com"}]
}
```

Only direct members are listed, whatever their active state. Groups that are members of the group are left
out, and their members are not expanded. To see who actually gets access, use the Report page or
[Troubleshoot access](../guide/troubleshoot-access.md).

### Other JSON responses

The Groups page loads the LDAP tree for its "add group" dialog from `/groups?get_ldap_groups&guid=...`. It is
an internal helper of that dialog, not a stable interface.
