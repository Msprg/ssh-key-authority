# Troubleshoot access

"Troubleshoot access" answers one question: can this person (or this key) log in as this account on this
server, and if not, where does it break? It works through what the sync does when it builds the key file of
the account, then compares the result with what SKA last saw on the server: whether the key file has
arrived, and whether the sshd configuration there accepts it.

The tool is for administrators only; other users are refused. If you are a user and cannot log in,
ask an administrator to run it for you, giving your user name or key fingerprint, the server and the login
name you use with `ssh`.

## Where to find it

- **Tools** in the menu bar, then **Troubleshoot access** (the **Tools** entry is only shown to administrators).
- On a user's page, in the **Access** section: the **Troubleshoot access** link (shown to administrators) fills
  in the user.
- On a server account page, on the **Access** tab: **Troubleshoot access to this account** fills in the
  server and the login. This button is only there when the server's keys are managed by SKA.

A link fills in only part of the form. The analysis runs once all three fields are filled in.

## Input

| Field | What to enter |
| --- | --- |
| Who is logging in | A SKA user name, a public key line in OpenSSH format (options in front of the key are fine), or a key fingerprint. |
| Server | The hostname as it is registered in SKA. |
| Linux account | The login name as used with `ssh`, for example `root`. It cannot contain `/`. |

Fingerprints are recognised as `SHA256:...` or as MD5 (`MD5:aa:bb:...` or just `aa:bb:...`), also when they
sit in the middle of a line copied from `ssh-keygen -l` or the sshd log. A bare SHA256 fingerprint without
the `SHA256:` prefix (the way SKA shows fingerprints) is also accepted, unless a user of that name exists.

The form is sent with POST, so a pasted public key does not end up in URLs or web server logs. The result
page is then addressed by a URL that holds the user name or the key fingerprint (never the pasted key) together
with the server and the login, so you can share or bookmark it. Input that is neither a user name nor a recognisable key is dropped with an error, in case
somebody pasted the wrong thing (such as a private key). An unknown user name is not looked up in LDAP.

User name or key makes a difference:

- **User name**: all active keys of the user are examined.
- **Key or fingerprint**: only that key is examined. The verdict is about "the holder of this key", and the
  report links to a run with all keys of the user who registered it. If the same key is registered for
  several users or accounts, the report says so and links to the other owners.

## Reading the result

The result has four parts, from top to bottom: the verdict, the access path diagram, a row of links to the
report sections, and the collapsed **Detailed report**. On servers whose keys are managed by SKA, the
**Sync this server now and re-check** button sits between the links and the report.

### The verdict

The coloured box at the top sums everything up in one sentence, followed by the reasons.

| Colour | Meaning |
| --- | --- |
| Green | SKA grants the access, the key file on the server is current, and nothing in the sshd configuration stands in the way. |
| Blue | SKA grants the access, but the server side could not be checked completely, or the information from the server is more than an hour old. |
| Yellow | SKA grants the access but something may stop it: the key file on the server is missing or outdated, syncs to the server are failing, there is a warning about the server, or the key only gets in through another registration of it. Also used when SKA denies access but the login may work anyway: the key was found in a file SKA does not manage, or the server still has an old or unmanaged key file for the account. |
| Red | SKA does not grant the access, or SKA grants it but the server refuses the login (for example there is no such Linux account, or the sshd configuration does not accept the key). |

When SKA denies access, some reasons come with what can be done about it, for example a link to the
**Access** tab where a rule could be added.

### The access path diagram

Below the verdict, the diagram shows the way from whoever logs in to the login, as a chain of boxes:

1. The key (only when a key or fingerprint was entered).
2. The user, or the server account for a server-to-server key (no box for a key that is not registered).
3. The groups that lead to the account: the user's nested groups, the group that holds the access rule, and
   the groups the account is in.
4. The login account (`login@server` if it is not a managed account).
5. The key file `/var/local/keys-sync/<login>`.
6. The server.
7. The login itself, `ssh login@server`.

The arrows between the boxes are labelled with the relation, for example "member of", "has access to",
"contains", "keys written", "synced to" and "sshd". Where the chain breaks and there is something to say
about the break, the arrow is marked with a cross and a short reason, such as "nobody has been granted
access" or "destination rules keep the key away". Otherwise the boxes on either side show what is wrong.

Each box has a state:

| State | Look | Meaning |
| --- | --- | --- |
| OK | Green border | This step works. |
| Warning | Yellow | Needs attention: it may stop the login, or the access only works through another registration of the key. |
| Problem | Red, bold caption | This is where access breaks. |
| Unknown | Grey, dashed border | SKA could not check it, for example because it has no current information from the server. |
| Not reached | Grey, dotted border | Comes after a break. Shown for orientation only. |

The diagram follows the verdict. For example, when SKA writes the key file and it was in place when SKA last
looked, but that information is more than an hour old or syncs to the server are failing, the server box shows
"unknown" rather than "OK", and so does the login box if nothing was found wrong there.

### The Detailed report

The report is collapsed by default. The row of links above it jumps to a section and opens the report. The
browser remembers whether you last opened or closed the report by its own heading, and the next search
starts that way (stored in `localStorage`; without it, the report starts closed).

Only sections with findings are shown. Each finding has an icon: OK, problem, warning, unknown or note.

| Section | What it covers |
| --- | --- |
| Who is logging in | The user and their keys, or the key and its owner. Inactive users, users without keys, deleted keys, unknown keys or users, the same key registered for others. |
| Server | Key management setting of the server, LDAP authorization, last sync result, a sync waiting to run. |
| Login account | Whether SKA manages the account, or, on servers with LDAP authorization, whose user key file it is. |
| Access rules | The groups the account is in, which rules cover the user, and why others do not. Contains the table **All access rules that apply to this account**, opened when SKA does not grant access. |
| Keys written for this access | Destination rules of the keys, access options added to the key lines. |
| Key file on the server | Whether the file exists on the server and is identical to what SKA generates now. |
| Server configuration (sshd) | The Linux account and the sshd settings that affect key logins. |
| Access outside of SKA | Other ways in that SKA does not control. |
| Other accounts on this server | Other accounts on the same server that the user does have access to, each with a link to explain it. Useful when somebody "always had access", but to a different account. |

Two more collapsible blocks hold raw data, when it is available:

- **Content that SKA writes to `/var/local/keys-sync/<login>`**: the key file as the sync would generate it
  now, with the lines of the examined keys highlighted.
- **Settings that SKA read from the sshd configuration of the server**: the collected directives, per file
  and with line numbers. Files that could not be read are labelled as such.

## What the tool knows and what it does not

The web process cannot open SSH connections to the servers; it cannot read the sync key. Everything the tool
says about a server comes from a snapshot that the sync and supervision scripts collect while they are
connected anyway, stored in the `server_host_facts` table:

- OS release and SELinux mode, whether `/etc/nologin` or `/run/nologin` exists.
- Owner and mode of `/var/local` and `/var/local/keys-sync`, and of each key file, with a SHA-1 of its
  content (up to 300 files).
- For the login accounts: whether they exist, uid, gid, home directory, shell (and whether it is executable)
  and groups (up to 100 logins).
  The accounts looked up are the accounts SKA has for the server and, on servers with LDAP authorization, the
  names of the key files found.
- The sshd configuration, starting with `/etc/ssh/sshd_config` (or `/usr/etc/ssh/sshd_config` where that is
  the only one), following `Include` in `/etc/ssh/`, `/usr/etc/ssh/`, `/etc/crypto-policies/` and
  `/usr/share/crypto-policies/`. An `Include` elsewhere is not followed, and the report says that the result may
  be incomplete.
  Only directives that matter for key logins are kept; paths and commands of `AuthorizedKeysCommand`,
  `TrustedUserCAKeys`, `RevokedKeys`, `ChrootDirectory` and the like are not stored, only whether they are set.

The snapshot is refreshed:

- At the end of each sync that gets through to the server, if the sync took less than 20 seconds. After a
  longer sync, the snapshot is marked as outdated and the next supervision run renews it.
- By `scripts/supervise_external_keys.php` (the shipped cron entry runs it every 5 minutes), at most every
  15 minutes per server, or on its next run if the snapshot was marked as outdated. It only visits servers whose
  keys are managed by SKA.

Collection has a time budget of 25 seconds and never makes a sync fail. If renewing fails, SKA keeps the
older complete snapshot and the report says that the renewal failed. The report shows when the snapshot was
collected and how old it is, and the re-check button shows the collection time; above an hour, the verdict treats
it as possibly out of date.

Limits to keep in mind:

- The tool reads the configuration on disk. sshd only uses it after a reload.
- The files are read as the `keys-sync` user. If the sshd configuration or an included file is not readable
  for it, the report says so, and findings from the configuration that would block the login are reported as
  warnings.
- `Match` blocks are evaluated for `User` and `Group`. Criteria that depend on the connection (`Address`,
  `Host`, `LocalPort`, ...) cannot be decided, so their settings are reported as "depending on the
  connection".
- SKA cannot read `/etc/shadow`, so it cannot tell whether a password is locked, and it cannot check SELinux
  labels or the content of a `RevokedKeys` file.
- A login that is neither an account SKA has for the server nor (on servers with LDAP authorization) the name
  of a key file there is not looked up, so the tool has no information about that Linux account.

### Sync this server now and re-check

For servers whose keys are managed by SKA, a button below the diagram and the section links requests a sync
of the server. The page then reloads every 3 seconds while the sync request is pending, for about a minute,
and shows the result with the information collected by that sync. This needs the sync daemon to be running (see
[How keys reach servers](../concepts/key-distribution.md)). If the sync takes 20 seconds or more, the key file
is current but the snapshot is only renewed by the next supervision run, within a few minutes.

## Findings the tool can report

### On the SKA side

- **Inactive user**: SKA writes no keys for inactive users. A user blocked from logging in to SKA itself
  still gets keys distributed; the report notes this separately.
- **No keys, deleted key, unknown key or unknown user.**
- **Server not managed**: decommissioned, managed by another system, or key management disabled. For a
  server-to-server key, a decommissioned server of the key's own account also stops it.
- **Account not managed by SKA**, only requested (no approved access request yet), or removed. The account
  `keys-sync` is reserved for SKA itself.
- **No access rule**, or none covering the user. For group rules, the report says whether membership comes
  from LDAP. Rules of inactive groups are ignored by the sync, and the report says so.
- **Key destination rules** that keep a key away from `login@server`. A second upload of the same key without
  the restriction still gets written.
- **Another registration of the same key**: the key is in the file because someone else who has access
  registered it too. Whoever holds the private key can log in.
- **LDAP user files**: on servers with LDAP authorization, every SKA user with a key that may be used there gets
  a key file under their own user name, without any access rule. That file only ever contains that user's keys. With "manual
  LDAP" authorization, the sync only keeps it if the Linux user exists on the server.
- **Sync status**: the last sync failed or the server has not been synced yet.

See [Users, groups and access rules](../concepts/access-model.md) for how rules and groups combine.

### Key file on the server

- The file does not exist on the server yet.
- The file differs from what SKA generates now (a sync is outstanding or failed, or the snapshot predates the
  change). Until it is replaced, the file on the server counts.
- A file exists although SKA would not write one. Its content is unknown and may still let somebody in.
- The directory listing was incomplete or the file was unreadable, so it could not be compared.

### In the sshd configuration

Settings are evaluated the way sshd does: the first value wins, `Include` is read in place, a matching
`Match` block overrides global values, and `AllowUsers`, `DenyUsers`, `AllowGroups` and `DenyGroups`
accumulate.

| Finding | Effect |
| --- | --- |
| No Linux account of that name | Blocks the login. |
| Login shell missing or not executable (unless `ChrootDirectory` is set) | Blocks. A `nologin` or `false` shell is a warning; `ForceCommand internal-sftp` is noted. |
| `AuthorizedKeysFile` does not include `/var/local/keys-sync/%u` | Blocks. Other settings that are ignored or do not apply are listed with it. |
| `PubkeyAuthentication no` | Blocks. |
| `StrictModes` on (the default) and the key file or a directory above it is owned by someone other than root or the account, or is writable by group or others | Blocks. If ownership could not be checked, a warning. |
| Key file or key directory not readable for a non-root account | Warning (644 and 711 expected). |
| `PermitRootLogin no`, or `forced-commands-only` without a `command=` option, for a uid 0 login | Blocks. |
| Account in `DenyUsers` or `DenyGroups`, or not in `AllowUsers` or `AllowGroups` | Blocks. A `USER@HOST` entry, or group lists when the groups are unknown, give a warning. |
| `AuthenticationMethods` that requires a step besides the key | Warning. Without `publickey` at all, blocks. |
| Key type not accepted by `PubkeyAcceptedAlgorithms` (DSA keys are not in the default set) | Blocks. RSA keys on servers that refuse SHA-1 signatures get a note about client versions. |
| RSA key shorter than `RequiredRSASize` | Blocks. |
| `RevokedKeys` is set | Warning: SKA cannot read that list. |
| `UsePAM` not `yes` | Warning: sshd then refuses accounts with a locked password. If both `/usr/etc/ssh/sshd_config` (shipped defaults) and `/etc/ssh/sshd_config` exist, the report explains that the latter replaces the former. |
| `/etc/nologin` exists (or `/run/nologin` with PAM), for a non-root login | Warning. |
| `StrictModes`, `UsePAM` or `PermitUserEnvironment` inside a `Match` block other than `Match all` | Warning: sshd refuses to start with that. |
| A `Match` block that depends on the connection sets one of the relevant settings | Warning. A blocker on the same setting is downgraded to a warning. |
| Key lines set `environment="..."` but `PermitUserEnvironment` is off | Note: the login works, the variable is missing. |
| SELinux enforcing | Note: the key directory needs the label `ssh_home_t`. |
| `MaxAuthTries` of 3 or less | Note: a client offering several keys may be cut off first. |

How to set up a server so that these pass is described in
[Prepare target servers](../getting-started/target-servers.md#what-a-managed-server-needs).

### Access outside of SKA

Listed for information, because they work independently of SKA:

- Other key files that sshd reads, such as `~/.ssh/authorized_keys`. If the examined key was found in one
  by the external key scan, the report names its status, and, if SKA denies access, the verdict says the login
  may work anyway.
  See [Unmanaged keys](external-keys.md).
- `AuthorizedKeysCommand` and `TrustedUserCAKeys` (SSH certificates).
- Password logins that are not switched off.
- That the key scan of the server is off (or root only), or could not read all files, so SKA cannot rule
  out such a key.

## Worked example

Alice reports that `ssh deploy@web01.example.com` asks for a password. An administrator enters `alice`,
`web01.example.com` and `deploy`.

The verdict is red: "SKA gives alice access to `deploy@web01.example.com`, but the server does not let the
login through." The reason underneath reads: "sshd does not read the SKA key file
`/var/local/keys-sync/deploy` for this account. In effect is `AuthorizedKeysFile .ssh/authorized_keys`
(`/etc/ssh/sshd_config:42`)."

The diagram shows the chain intact up to the last box:

```text
alice (1 public key)
  -- member of -->     developers
  -- has access to --> web-servers
  -- contains -->      deploy@web01.example.com (managed account)
  -- keys written -->  /var/local/keys-sync/deploy (1 line written by SKA)
  -- synced to -->     web01.example.com (key file is in place · 5 minutes ago)
  -- sshd -->          ssh deploy@web01.example.com   [Problem: refused by the server configuration]
```

So the access rule and the sync are fine, and the problem is on the server. Opening **Server configuration
(sshd)** in the Detailed report shows the same finding, with a pointer to the required value. After adding
`/var/local/keys-sync/%u` to `AuthorizedKeysFile` and reloading sshd, the administrator clicks
**Sync this server now and re-check**. Once the sync has run and collected the new configuration, the verdict
turns green, provided nothing else stands in the way.

Had the diagram instead gone straight from alice to the account, with a cross and "no access rule covers this
user" on the arrow, the fix would have been on the SKA side: the **Access rules** section would list the rules on the account and its
groups, and which groups alice is not a member of.
