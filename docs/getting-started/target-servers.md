# Prepare target servers

SKA logs in to every managed server as the `keys-sync` user, using the key pair in `config/keys-sync` and
`config/keys-sync.pub` on the SKA host. It writes one key file per login account into `/var/local/keys-sync/`,
and sshd on the server reads keys from there instead of `~/.ssh/authorized_keys`. This page lists what a
server needs for that, how the bundled install script sets it up, and what happens when you add the server
in SKA.

## What a managed server needs

| Item | Requirement |
| --- | --- |
| `keys-sync` user | A system account with home `/var/local/keys-sync` and a shell that can run commands (`/bin/sh`). The sync runs shell commands, so the account must not be limited to SFTP. |
| `/var/local/keys-sync/` | Owned by `keys-sync`, mode `0711`. Every account can reach its own file by name, but cannot list the directory. |
| `/var/local/keys-sync/keys-sync` | Contains the public key from `config/keys-sync.pub`. Owned by `keys-sync`, mode `0644`. |
| sshd `AuthorizedKeysFile` | Must include `/var/local/keys-sync/%u`. |
| sshd `StrictModes no` | Required, see below. |
| sshd `PermitUserEnvironment` | Needed only for the history username variable, see below. |
| SFTP subsystem | Required. SKA reads and writes files over SFTP. |
| Status file | `/var/local/keys-sync.status` (or the path in `[monitoring] status_file_path`), owned by `keys-sync`, mode `0644`. |
| `.hostnames` | Required if `hostname_verification` is 3, used if present at level 2. |
| Network | The SSH port must be reachable from the SKA host, or from the last jump host. |

The sync runs standard tools on the server as `keys-sync`, among them `/usr/bin/sha1sum` to compare existing
key files with the new content, `chown` and `rm`, `id` to look up accounts on "manual LDAP" servers, and
`/bin/hostname -f` for hostname verification at level 1, or at level 2 when `.hostnames` cannot be read. The
snapshot for Troubleshoot access (see below) uses further common tools such as `stat`, `grep`, `getent` and
`head`. If `/etc/uuid` exists, its content is read and can link the server to an inventory system
(`[inventory] url`).

### The key directory

Each file in `/var/local/keys-sync/` is named after a login account (`root`, `deploy`, ...) and holds the keys
SKA allows for that account. SKA writes the files over SFTP and then sets their owner to `keys-sync`.

The directory belongs to SKA. On a full sync (not one limited to a single account), SKA deletes every file in
it that it did not generate, except `keys-sync` and `.hostnames`. Files whose names start with a dot are not
listed and therefore not deleted. It never writes or deletes `keys-sync` itself, so its own access survives
every sync, including a decommission. Do not keep anything else in this directory.

### Why StrictModes must be off

With `StrictModes yes` (the sshd default), sshd ignores a key file unless the file and the directories above
it are owned by the login account or by root and are not writable by anyone else. The key files are owned by
`keys-sync`, so sshd would ignore them for every account except `keys-sync`.

!!! warning
    `StrictModes` is a global sshd setting. It cannot be set per user or in a `Match` block, so turning it off
    also relaxes the ownership checks on files such as `~/.ssh/authorized_keys` for all accounts.

### AuthorizedKeysFile

```text
AuthorizedKeysFile /var/local/keys-sync/%u
```

`%u` is the login name, so `root` reads `/var/local/keys-sync/root` and `keys-sync` reads
`/var/local/keys-sync/keys-sync`. With only this path, keys in `~/.ssh/authorized_keys` stop working. To keep
them working alongside SKA, list both:

```text
AuthorizedKeysFile .ssh/authorized_keys .ssh/authorized_keys2 /var/local/keys-sync/%u
```

sshd uses the first value it finds for a directive, so the line must come before any other
`AuthorizedKeysFile` in the configuration, including those in files pulled in by `Include`.

### PermitUserEnvironment and the history variable

When the history username option is enabled (`[privacy] history_username_env_default`, overridable per server),
SKA adds `environment="BASH_HISTORY_USERNAME=<uid>"` (or your configured format) to user key lines. sshd only
applies such options with `PermitUserEnvironment yes`; OpenSSH 7.8 and newer also accept a list of variable
names. Without it the login still works, the variable is just not set. See
[Configuration reference](../reference/configuration.md#history-username-environment).

### SFTP subsystem

SKA's SSH library writes key files, reads `.hostnames` and writes the status file over SFTP. sshd needs a
working `Subsystem sftp` line, either `internal-sftp` or the path to an installed `sftp-server` binary. Shell
commands still work without it, so the login succeeds, but no key file can be written: the server reports
"N accounts failed to sync", and the sync output for each account says the SFTP session failed and asks you
to check the sftp subsystem.

### Status file

After each sync SKA writes a small JSON document to `/var/local/keys-sync.status` (configurable with
`[monitoring] status_file_path`). It holds the sync status and message, any key supervision error, the
accounts with unreviewed unmanaged keys and an expiry time, so that a local monitoring check can read it.
`keys-sync` normally cannot create files in `/var/local`, so create the file beforehand and give it to
`keys-sync`. A failed write does not fail the sync; it is recorded as a key supervision error. See
[Monitoring and logs](../operations/monitoring.md#status-file-on-target-servers).

### The .hostnames file

With `[security] hostname_verification` set to 2 or 3, SKA reads `/var/local/keys-sync/.hostnames` and only
syncs if the server's name in SKA appears in it, one name per line. At level 2, if the file cannot be read,
SKA compares with `hostname -f` instead; at level 3 the sync stops with "Could not read
/var/local/keys-sync/.hostnames". Create the file as root, owned by `keys-sync`, mode `0644`:

```bash
echo "server1.example.com" > /var/local/keys-sync/.hostnames
chown keys-sync /var/local/keys-sync/.hostnames
chmod 0644 /var/local/keys-sync/.hostnames
```

See [Configuration reference](../reference/configuration.md#hostname-verification) for the levels.

## Set up a server by hand

Run as root, with the public key from `config/keys-sync.pub` on the SKA host copied to the server as
`keys-sync.pub`:

```bash
useradd --system --home-dir /var/local/keys-sync --shell /bin/sh keys-sync
install -d -o keys-sync -m 0711 /var/local/keys-sync
install -o keys-sync -m 0644 keys-sync.pub /var/local/keys-sync/keys-sync
install -o keys-sync -m 0644 /dev/null /var/local/keys-sync.status
```

On systems without `useradd`, `adduser --system --disabled-password --home /var/local/keys-sync --shell /bin/sh keys-sync`
does the same. The SKA web interface shows the key to install under Help, "How do I set up my server to sync
access for all accounts?".

Then add the sshd settings near the top of `/etc/ssh/sshd_config`, or in a drop-in that is included before
anything else sets them:

```text
AuthorizedKeysFile /var/local/keys-sync/%u
StrictModes no
PermitUserEnvironment yes
```

Check the configuration with `sshd -t`, check the effective values with
`sshd -T | grep -Ei 'authorizedkeysfile|strictmodes|permituserenvironment|subsystem'`, and reload sshd. Keep
your current session open until you have logged in again through a key that SKA has synced.

## The install script

`Install SKA management.sh` in the repository root automates the setup above on a single server. It is a
convenience script, not a configuration management tool: read it before you run it.

### Before you run it

!!! warning
    The script contains two hard-coded public keys, `SKA_PROD_PUBKEY` and `SKA_DEV_PUBKEY`. They belong to the
    maintainer's own SKA instances, and the selection menu names the maintainer's hosts. Replace
    `SKA_PROD_PUBKEY` with the content of your `config/keys-sync.pub` (and `SKA_DEV_PUBKEY` with a second
    instance's key, or the same key) before running it. Otherwise you give someone else's SKA access to the
    server.

The script needs bash, `whiptail` for its dialogs (it stops if `whiptail` is missing), and root. Run as
another user, it offers to re-run itself with `sudo`.

### What it does

1. Asks three questions: which of the two keys to install (PROD or DEV), the root login policy
   (`prohibit-password` or `no`), and whether to keep `~/.ssh/authorized_keys` working (default: no). It then
   shows the choices and asks for confirmation.
2. Creates the `keys-sync` system user (home `/var/local/keys-sync`, shell `/bin/sh`) if it does not exist, and
   makes sure the directory exists, owned by `keys-sync`, mode `0711`.
3. Writes the chosen key to `/var/local/keys-sync/keys-sync` (owner `keys-sync`, mode `0644`), replacing what
   was there.
4. Creates `/var/local/keys-sync.status` (mode `0644`) and gives everything matching `/var/local/keys-sync*` to
   `keys-sync`. This path is fixed in the script; it does not read `status_file_path`.
5. Prepends `Include /etc/ssh/sshd_config.d/*.conf` to `/etc/ssh/sshd_config` if the file has no such line,
   and repairs a broken first line left by older versions of the script.
6. Writes `/etc/ssh/sshd_config.d/00-apply-central-ssh-key-management.conf` with `AuthorizedKeysFile` (one or
   both forms, depending on the answer in step 1), `StrictModes no`, `PermitUserEnvironment yes`, a commented
   `PubkeyAcceptedAlgorithms +ssh-rsa` line, and `PasswordAuthentication no` for `keys-sync`.
7. Runs `sshd -T`. If no SFTP subsystem is configured, it adds `Subsystem sftp internal-sftp` to the drop-in. If
   the configured `sftp-server` binary does not exist, it only prints a warning.
8. Writes `/etc/ssh/sshd_config.d/00-only-key-auth-for-rootlogin.conf` with the chosen `PermitRootLogin` and
   `PasswordAuthentication no` for root.
9. Sets mode `0644` on every file in `/etc/ssh/sshd_config.d/` (not only its own) and on `sshd_config`, validates with `sshd -t` and reloads or restarts sshd
   (systemd units `sshd`, `ssh` or `openssh-server`, or the init scripts).
10. If SELinux is enforcing, labels `/var/local/keys-sync` as `ssh_home_t` with `semanage fcontext` and
    `restorecon`; it warns if those tools are missing. In permissive mode it only runs `restorecon`.
11. Runs a self-test (below) and reports success or failure, both in the terminal and in a dialog.

The drop-in names start with `00-` so that they sort first: sshd keeps the first value it reads.

!!! note
    If `sshd_config` already contains the `Include` line further down, the script leaves it where it is.
    Settings in `sshd_config` above that line then win over the drop-in. Check the result with `sshd -T`.

The `KEEP_HOME_AUTH_KEYS` variable at the top of the script can be set in the environment, but the dialog in
step 1 always overrides it.

### Self-test

The script creates a temporary key pair, appends its public key to `/var/local/keys-sync/keys-sync` between
`# SKA-VERIFY BEGIN` and `# SKA-VERIFY END` markers, and logs in as `keys-sync@localhost` with that key. If the
login works, it uploads and removes a probe file in `/var/local/keys-sync/` over SFTP (or, without an `sftp`
client, checks that the subsystem starts). It then removes the temporary key and files. If the `ssh` client or
`ssh-keygen` is missing, or no key pair can be created, the test is skipped and the script still reports
success.

The test connects to `localhost` on the default SSH port. On a server where sshd listens elsewhere, or is
limited to other addresses, the test fails even if the configuration is correct. A failed test makes the
script exit with an error but does not undo any change.

### Do not lock yourself out

The script changes how every account authenticates. Without the "keep home keys" option, keys in
`~/.ssh/authorized_keys` stop working as soon as sshd reloads, and no account except `keys-sync` has keys until
SKA has synced access to the server. Root can no longer log in with a password.

- Keep the session you ran the script in open, and open a second one to test.
- If the self-test login fails, the script prints and shows a lock-out warning. If only the SFTP part fails,
  it prints tips instead. In both cases fix the configuration before you close your session.
- If `sshd -t` fails, the script stops with the new files in place and sshd not reloaded. Fix or remove the
  drop-ins before sshd is restarted.
- Grant yourself access in SKA and confirm a successful sync and login before closing your last session.

## Add the server in SKA

On the **Servers** page, open **Add server** and enter:

- **Server hostname**: the name SKA connects to. Hostname verification compares against this name.
- **SSH port number**: default 22.
- **Jumphosts**: optional, see below.
- **Leaders**: at least one user or group who will manage access to the server.

**Add multiple servers** accepts the same fields as CSV, one server per line and no header line: hostname, port
(empty means 22), jump hosts (may be empty; quote the field if it contains commas) and a semicolon-separated
list of leader user or group names. The import is all or nothing: if any line is invalid, no server is added.

A new server has key management enabled ("SSH keys managed and synced by SSH Key Authority"), manual
authorization, and key supervision set from `[general] default_key_supervision`. SKA creates the standard accounts from `[defaults] account_groups` (for example `root` in the
`root-accounts` group) and requests a sync, which the sync daemon picks up. **Sync now** on the server page
requests another one. A failed sync is retried after 30 minutes.

### What the sync checks before writing

1. **IP address**: SKA resolves the hostname. If another managed server has the same IP address, port and jump
   hosts, both are refused with "Multiple hosts with same IP address".
2. **Host key**: on the first connection SKA stores the host key the server presents. Later connections must
   present the same key, or the sync fails with "SSH host key verification failed". The first key is trusted
   as it comes, so add servers over a network you trust.
3. **Login** as `keys-sync` with `config/keys-sync`. If the key is not accepted: "SSH authentication failed".
4. **Host key collision**: with `[security] host_key_collision_protection = 1` (the default), the sync stops if
   another managed server has the same host key, which happens with cloned machines. Regenerate the host keys
   on one of them.
5. **Hostname verification**, if `hostname_verification` is 1 or higher. A mismatch fails with "Hostname check
   failed (allowed: ...)".

If the host key changed for a known reason, clear it on the server's **Settings** tab (**Clear**, then save).
That triggers a sync, which stores whatever key the server presents next. Administrators can always do this;
server leaders only when `[security] host_key_reset_restriction = 0`. See
[Configuration reference](../reference/configuration.md#host-key-settings).

At the end of each sync that got this far, SKA writes the status file and collects a snapshot of the server's
sshd configuration, key directory and login accounts for [Troubleshoot access](../guide/troubleshoot-access.md).
If the sync took 20 seconds or more, the snapshot is left to the next run of `supervise_external_keys.php`.
The snapshot is read as `keys-sync`, so it is only complete if `keys-sync` can read the sshd configuration
files.

## Jump hosts

If the SKA host cannot reach a server directly, list one or more jump hosts, comma-separated, in order from SKA
to the target:

```text
root@host1.example.com,keys-sync@host2.local:2222
```

Each entry is `user@host` with an optional `:port`. If the target is known by a different name from the last
jump host, append an alias:

```text
keys-sync@jumphost.example.com -> aliasname.local
```

How it works and what it needs:

- SKA starts the `ssh` client on the SKA host and chains the hops with `ssh -W`, so the `ssh` client must be
  installed there and each jump host must allow TCP forwarding.
- Every hop authenticates with `config/keys-sync`. Each jump host must accept that key for the user named in
  the entry. A jump host that is itself managed by SKA already accepts it for `keys-sync`.
- The target is always logged in to as `keys-sync`, and its host key is checked as described above.
- The host keys of the jump hosts are not checked (`StrictHostKeyChecking=off`, no known hosts file).
- If a hop fails and the `ssh` client prints an error, the sync reports "The tunnel connection via
  jumphost(s) failed"; otherwise it reports a general connection failure.

## On the SKA host

When the sync daemon or `sync.php --all` / `--host` syncs servers, each server's sync runs in a child process
under `/usr/bin/timeout` (60 seconds) on the SKA host, not on the target. `[general] timeout_util` must match
the installed variant: `BusyBox` (Alpine) makes SKA call `timeout -t 60`; any other value, such as
`GNU coreutils` (Debian, Ubuntu and most others), calls `timeout 60s`. See [Configuration reference](../reference/configuration.md#general). Generating and
installing the sync key pair is covered in [Install with Docker](docker.md) and
[Install without Docker](bare-metal.md).
