# Security

SKA decides who can log in to which account on every managed server, so the SKA host, its database and its
sync key are as sensitive as root access to those servers. This page describes what SKA does to protect
logins, sessions and the sync, which settings change that, and where its protections stop.

## Logging in

### How the password is checked

SKA looks up the entry whose `user_id` attribute matches the typed username under `dn_user`, over its own
directory connection (`bind_dn`), and then binds to the directory with that entry's DN and the typed password. The account whose
password was verified is the account that gets the session. Details of the login flow:

- The username may contain only letters, digits, `.`, `-` and `_`. Anything else is rejected before the
  directory is asked.
- An empty password is refused without contacting the directory, because an LDAP bind with an empty
  password is an unauthenticated bind that directories accept without checking anything.
- If the username matches no entry, or more than one, the login is refused like a wrong password and no bind
  is attempted.
- After the bind, SKA checks that the user details it loads come from the same DN. If they do not, the login
  is refused.
- A user disabled in SKA, or marked inactive, is refused even with a correct password.

See [LDAP and Active Directory](ldap.md) for the directory settings involved.

### Generic and specific error messages

Failures before the password has been verified all produce the same message, "Invalid username or password.",
so the login form cannot be used to find out which accounts exist. This includes an unknown username, a wrong
password and an Active Directory account lockout: AD reports a lockout whatever password was sent, so
showing it would confirm that the account exists.

Only after the directory has accepted the password does SKA say what is wrong, for example a missing email
attribute, an expired directory password or an account disabled in SKA. Directory outages and configuration
problems get their own messages, which do not depend on the account. The full list is in
[For users](../guide/users.md#login-error-messages).

Every refused login that gets past the form checks (request token, username format, empty fields and the
attempt limit below) is written to the PHP error log with its reason and the username shortened to its first
three characters (`ali***`). Unexpected errors are logged with a reference number that is also shown to the
user.

### Failed-attempt limit

After 5 failed logins for the same username, SKA refuses that username for 15 minutes, counted from the last
failed attempt. Only "Invalid username or password" counts; outages and account problems do not, so people
are not locked out for retrying. A successful login clears the counter.

!!! warning
    The counter is stored in the PHP session, that is, per browser cookie. A client that discards its session
    cookie starts again at zero. The limit slows down guessing from one browser; it is not protection against
    a password-guessing attack. Rely on your directory's lockout policy for that.

### CSRF protection of the login form

The login form carries a token generated with `random_bytes()` and stored in the session. A submitted form
whose token does not match is refused with "Invalid request token". The token is replaced after every
submission, so a form that was open in another tab has to be reloaded.

## Sessions

### Cookie and session settings

| Setting | Value |
| --- | --- |
| `HttpOnly` | Always set. Scripts in the page cannot read the session cookie. |
| `SameSite` | `Strict`. The cookie is not sent with requests started from other sites. |
| `Secure` | Set only when PHP sees `$_SERVER['HTTPS']` as `on`. |
| `session.use_strict_mode` | On. PHP does not accept a session ID it did not create itself. |
| Session ID on login | Regenerated after a successful login, and the old session is deleted. |
| Logout | The session data is cleared and the session destroyed. |

When TLS ends at a reverse proxy, PHP sees plain HTTP and the cookie is sent without `Secure`. On a bare-metal
install, pass `HTTPS=on` to PHP yourself, see
[Install without Docker](../getting-started/bare-metal.md#https-behind-a-proxy). The Docker image's internal
nginx does not do this, so behind a proxy the flag is not set, see
[Install with Docker](../getting-started/docker.md#https). In both cases, make sure SKA is only reachable over
HTTPS from users' browsers.

On every request SKA reloads the logged-in user from the database. A user who has been disabled in SKA, or
marked inactive by the LDAP sync, is logged out on the next page load.

### Session timeout

`[security] session_timeout` is the idle time in seconds after which SKA logs a user out (default `28800`,
8 hours). SKA compares it with the time of the user's last request; every page load restarts the timer. `0`
turns the timeout off. See [Configuration reference](../reference/configuration.md#security).

### Session files can expire earlier

`session_timeout` is checked by SKA, but PHP deletes session files on its own, and a deleted session file means
the user has to log in again. Three things are involved:

- **PHP's garbage collection** deletes session files that have been idle longer than
  `session.gc_maxlifetime`. `core.php` makes it run on about one request in 1000
  (`gc_probability = 1`, `gc_divisor = 1000`).
- **`core.php` changes `gc_maxlifetime` only if `config.ini` has a `[session]` section with a `timeout`
  key.** This key is not in `config.ini.example` and is separate from `[security] session_timeout`. With a
  value above 0 it is used as `gc_maxlifetime`; with `0` or a negative value, 28800 is used. Without the
  section, PHP's own setting applies, which is 1440 seconds (24 minutes) unless you changed it.
- **Debian and Ubuntu PHP packages** also run a cleanup job (`/usr/lib/php/sessionclean`, from cron or a
  systemd timer) every 30 minutes. It deletes session files older than the largest `session.gc_maxlifetime`
  it finds in the `php.ini` files and their `conf.d` directories. It does not see values set with `ini_set()`
  or in a php-fpm pool. The Docker image is based on Debian 12 and runs cron, so this job is active there.

With the example configuration, a user can therefore be logged out after about 24 minutes of inactivity,
although `session_timeout` says 8 hours. To get the configured timeout, set `session.gc_maxlifetime` to at
least the same value in an ini file of the PHP SAPI that serves SKA, for example
`/etc/php/8.2/fpm/conf.d/99-ska-session.ini`:

```ini
session.gc_maxlifetime = 28800
```

This covers PHP's own garbage collection and the Debian job. Restart php-fpm afterwards. In Docker, mount
such a file into the container. If `session_timeout` is `0`, sessions still end when the file is deleted.

In the Docker image, sessions are stored in `/var/lib/php/sessions`, which is not a volume, so recreating the
container logs everybody out.

## Forms and page headers

Every form submitted (POST) by a logged-in user must carry the user's CSRF token, which SKA includes in all
its forms.
A request without the right token is refused with "Form submission failed" and nothing is changed.

Limits of this protection:

- The token belongs to the user, not the session. It is stored in the `user` table, created on first use and
  not rotated on login or logout. It is a SHA-512 hash of a single `mt_rand()` value, not output of a
  cryptographic random generator.
- A request with the header `X-Bypass-CSRF-Protection: 1` skips the check. It exists for scripts that call
  SKA with a session cookie. Browsers do not add custom headers to cross-site requests without a CORS
  preflight, and SKA sends no CORS headers.

Every HTML page is sent with `X-Frame-Options: DENY` and `Content-Security-Policy: default-src 'self'`. The
nginx configuration in the Docker image also adds `X-Content-Type-Options: nosniff`. SKA does not send
`Strict-Transport-Security`; add it in your TLS proxy if you want it.

Output in the web interface is HTML-escaped by default, including text collected from target servers. The
exception is `[web] footer`, which is inserted as HTML; only put trusted markup there.

## Public key requirements

When a key is uploaded, SKA checks its type and size with `ssh-keygen`:

| Key type | Setting | Default minimum |
| --- | --- | --- |
| `ssh-rsa` | `min_rsa_bits` | 4096 |
| `ecdsa-sha2-nistp256/384/521` | `min_ecdsa_bits` | 384 (nistp256 is refused) |
| `ssh-ed25519` | `min_ed25519_bits` | 256 (every Ed25519 key) |

`ssh-dss` keys are checked against `min_rsa_bits` and are therefore refused with the defaults. Other types,
including FIDO security keys (`sk-...`), are not accepted.

- The minimums are enforced only at upload. Raising them does not remove or block keys that are already
  stored; the "Managed keys" tab of the Public keys page only highlights key sizes below the current minimum.
- When an administrator adds a key to a server account, the form has an "Allow weak (< 4096 bits) key"
  option that skips the size check. Server leaders and users cannot skip it.

See [For users](../guide/users.md#accepted-key-types-and-minimum-sizes) and the
[Configuration reference](../reference/configuration.md#security).

## Connecting to the right server

If the sync reached the wrong machine, it would write one server's key files to another and give people access
they were never granted. SKA uses several checks to prevent this. They run on every sync connection, before
anything is written; the order is described in
[How keys reach servers](../concepts/key-distribution.md#connecting-to-a-server).

### Host key verification

On the first connection to a server, SKA stores the host key it receives (trust on first use). Later
connections are refused with "SSH host key verification failed" if the server presents a different key. The
key is checked before SKA authenticates. After adding a server, compare the host key shown on its Settings tab
with the server's actual host key.

### Who can reset a host key

After a legitimate change, such as a reinstalled server, the stored host key must be cleared so that the next
sync stores the new one. `[security] host_key_reset_restriction` decides who may do that:

| Value | Who can clear the host key |
| --- | --- |
| `1` (default) | Administrators only |
| `0` | Administrators and the server's leaders |

Hostname, port and jumphosts of a server can only be changed by administrators, whatever this setting says.
Clearing a host key is recorded in the server's log.

### Host key collisions

With `host_key_collision_protection = 1` (default), a sync is refused with "Multiple hosts with same host key."
if another server with key management enabled has the same stored host key. This catches a connection that has
been redirected to a server SKA already knows. Cloned virtual machines that share host keys trigger it too;
regenerate their host keys rather than turning the check off.

Independently of this setting, a sync is refused with "Multiple hosts with same IP address" if another server
with key management enabled resolves to the same IP address with the same port and jumphosts.

### Hostname verification

`[security] hostname_verification` asks the server itself to confirm the name it is synced under, through
`hostname -f` (level 1) or the file `/var/local/keys-sync/.hostnames` (levels 2 and 3). It is off (`0`) by
default; the levels are described in the
[Configuration reference](../reference/configuration.md#hostname-verification).

Level 3 is the strictest: SKA syncs only to servers where someone has explicitly allowed it under that name.
Create `.hostnames` before raising the level, or every server without it stops syncing. See
[Prepare target servers](../getting-started/target-servers.md#the-hostnames-file).

### SSH port redirection

Opera published an advisory for the original SKA,
[SSH port redirection attack](https://github.com/operasoftware/ssh-key-authority/wiki/SKA-security-advisory%3A-SSH-port-redirection-attack).
Against a sync connection that ends up at a different server than intended, the checks above work as
follows:

- A redirected connection presents a different host key and fails host key verification.
- If the stored host key is cleared, the new key is accepted on first use. If it belongs to another managed
  server, collision protection stops the sync. Keeping `host_key_reset_restriction = 1` limits who can clear
  keys.
- Hostname verification makes the server that answers confirm its own name.

Leave `host_key_reset_restriction` and `host_key_collision_protection` at `1`, and consider
`hostname_verification = 3` if you can roll out `.hostnames` files.

### Jumphosts

When a server is reached through jumphosts, the jumphosts' own host keys are not checked; SKA connects to them
with `StrictHostKeyChecking=off`. The target server's host key is checked as usual. The sync key is used to log
in to every hop. See [How keys reach servers](../concepts/key-distribution.md#jumphosts).

## The sync key

`config/keys-sync` is the private key SKA uses to log in as `keys-sync` on every managed server. It must
not have a passphrase, because SKA loads it without one.

Anyone who has this key can write the key file of every account that SKA manages on every server, including
`/var/local/keys-sync/root`, and so give themselves login access to those accounts. Treat it like root
credentials for the whole fleet:

- Make it readable only by the accounts that run the sync daemon and `supervise_external_keys.php`. See
  [Install with Docker](../getting-started/docker.md#step-3-sync-key-pair) and
  [Install without Docker](../getting-started/bare-metal.md#sync-key-pair).
- Keep it out of backups that other people can read, and out of version control.
- If it leaks, generate a new pair, install the new public key as `/var/local/keys-sync/keys-sync` on every
  server, and remove the old one.

The database deserves the same care: whoever can change the `access` and `public_key` tables changes what the
next sync writes.

### What keys-sync does on a server

`keys-sync` is an ordinary system account, but it has a shell and owns `/var/local/keys-sync`. Over SSH and
SFTP, SKA uses it to:

- write, replace and delete the files in `/var/local/keys-sync/` (except `keys-sync` and `.hostnames`) and
  `chown` them to `keys-sync`;
- run `sha1sum`, `ls`, `rm`, `id`, `hostname -f` and a few read-only shell commands that collect host facts
  for [Troubleshoot access](../guide/troubleshoot-access.md);
- read `/etc/passwd`, `/etc/ssh/sshd_config`, the files in `/etc/ssh/sshd_config.d/`, `/etc/uuid` and
  `.hostnames`;
- write the status file (`[monitoring] status_file_path`);
- when key supervision is on, read users' `authorized_keys` files where its permissions allow, and remove keys
  that have been marked as denied on the Public keys page from those files where it can write them. See
  [Unmanaged keys](../guide/external-keys.md).

!!! warning
    Allowing and denying unmanaged keys is not limited to administrators. Every user who can log in to SKA
    sees the Allow and Deny buttons on the Public keys page, and the view does not check for administrator
    rights. A denied key is removed from `authorized_keys` files on the next scan.

Because the account has a shell, the sync key also allows running any command as `keys-sync`. What it can
reach beyond the files above depends on the permissions you give it on each server.

## Audit trail

### Event tables

Changes made through SKA are recorded in the database with the acting user and a UTC timestamp:

- `entity_event`: events for users, groups and server accounts (keys added and removed, access granted and
  removed, members and leaders added and removed, setting changes).
- `server_event`: events for servers (setting changes, including host key resets, leaders added and removed,
  accounts added, sync status changes).

Administrators see all events on the Activity page. Users who lead at least one server or group see a narrower
list there; others cannot open it.

### Syslog

Every event is also sent to syslog with the ident `keys` and the `auth` facility, as `key="value"` pairs:

```text
keys: KeysScope="account:deploy@web1.example.com" KeysRequester="alice" KeysAction="Access add" KeysValue="user:bob"
```

These events are logged at `warning` level: keys added, access granted, members added to a group, leaders
of servers, server accounts and groups added or removed, a user, server account or group set active, and
admin rights given to a user. All other events are logged at `info`. Double quotes are removed from values.

The Docker image does not run a syslog daemon. Unless you provide one, for example by mounting the host's
`/dev/log` into the container, these messages are lost and the database tables are the only record.

### What is not in the audit trail

- Logins and failed logins are not events. They appear only in the PHP error log, with the username
  shortened, and logins refused by the form checks or the attempt limit are not logged at all.
- Allowing or denying an unmanaged key on the Public keys page is not recorded as an event. Only "Allow"
  sends an email.
- Changes made directly in the database or LDAP are not recorded by SKA. Changes in group membership that the
  LDAP sync applies are recorded as events.

See [Monitoring and logs](monitoring.md) for the log files of the scripts.

## Email reports

SKA mails a copy of security-relevant changes to `[email] report_address`:

- a public key added to a user (the user is mailed too) or to a server account;
- a leader added to a server or server account, an administrator added to a group;
- a member added to a group, access granted to a server account or group;
- an unmanaged key allowed on the Public keys page, and the reports of
  [unmanaged keys](../guide/external-keys.md) found on servers;
- a server left without active leaders after the LDAP sync marked its last leader inactive.

Removals of keys, access, members and leaders, and denied unmanaged keys, are not mailed. The removals are
logged as events.

When a user's key is added by someone else, the mail says who did it and asks the user to contact
`admin_address` if they did not request it. Mail is sent with PHP's `mail()`, so the host needs a working
`sendmail`. With `enabled = 0` nothing is sent; `reroute` sends every mail to one address. Mails can be
GPG-signed if the PHP `gnupg` extension is installed and `[gpg] key_id` is set. See
[Configuration reference](../reference/configuration.md#email).

## Checklist

- Serve SKA over HTTPS only, and pass `HTTPS=on` to PHP where you can.
- Set `session.gc_maxlifetime` in php.ini to at least `session_timeout`.
- Keep `host_key_reset_restriction = 1` and `host_key_collision_protection = 1`; consider
  `hostname_verification = 3`.
- Restrict read access to `config/keys-sync`, `config/config.ini` (it contains the database and LDAP bind
  passwords) and database backups.
- Make sure `report_address` reaches someone who reads it, and that syslog messages are kept.
- Use your directory's lockout policy; SKA's attempt limit is per browser session.
