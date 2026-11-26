# For administrators

This page covers what SKA administrators do in the web interface: adding servers, managing users and groups,
mailing users, and reviewing who has access. Access rules themselves are explained in
[Users, groups and access rules](../concepts/access-model.md), the work of server leaders in
[For server leaders](server-leaders.md).

## Who is an administrator

A user is an administrator if their directory entry is a member of the LDAP group whose `cn` equals
`admin_group_cn` in the `[ldap]` section (`Key Admins` in the example file; the key has no built-in default). SKA looks for groups under `dn_group` whose
`group_member` attribute contains the user's `group_member_value`. Membership through nested groups only counts
when `indirect_group_memberships = 1`, which works with Active Directory only. See
[Configuration reference](../reference/configuration.md#ldap).

The flag is refreshed from the directory at every login and on every run of `scripts/ldap_update.php` (every
minute in the Docker image). Removing someone from the group therefore takes effect at the next LDAP update,
without a new login. `ldap_update.php` also creates a system group of the same name in SKA, so the admin group
shows up on the **Groups** page.

The user list also contains `keys-sync` ("Synchronization script"). SKA uses it as the actor for automatic
changes, such as the LDAP update adding users to LDAP groups. It is not a person.

## What administrators can do

| Task | Administrators | Others |
|---|---|---|
| Add servers, one or several | yes | yes, every logged-in user |
| Bulk actions on servers, add and remove server leaders | yes | no |
| Change server settings (hostname, port, key management, ...) | yes | leaders can only clear the host key, and only with `host_key_reset_restriction = 0` |
| Server **Notes** and **Contact** tabs | yes | not shown |
| User **Settings** tab, delete or add keys for another user, reassign servers | yes | no |
| Create groups, link LDAP groups, group settings and group administrators | yes | no |
| Add and remove group members, group access rules | yes | group administrators |
| **Tools** menu: bulk mail, Troubleshoot access | yes | no |
| **Activity** | the newest 100 events of everything | server leaders and group administrators see events for their own servers, accounts and groups |
| **Users**, **Public keys**, **Report** pages | yes | yes, every logged-in user |

Administrators can also approve or reject access requests and change access rules on every server account,
and manage signatures and destination restrictions on any user's key.

## Servers

### Adding a server

On **Servers**, open **Add server** and enter the hostname, the SSH port, optional jump hosts, and at least one
leader. Type a user or group name in the leaders field and press Enter for each one. If any name is neither a
user nor a group, nothing is created. The hostname may contain letters, digits, `-`, `.`, `_` and non-ASCII
characters.

A new server gets key management by SKA, manual account management, key supervision from
`default_key_supervision` (see [Configuration reference](../reference/configuration.md#general)) and the
standard accounts from `[defaults] account_groups`. SKA requests a sync right away, and each leader is sent an
email. Before the sync can succeed, the server has to be prepared as described in
[Prepare target servers](../getting-started/target-servers.md).

A leader name that is not yet a user in SKA is looked up in the directory, and the user is created if found.
The same happens when you name someone as a group member or open `/users/<uid>` for them.

### Adding several servers

**Add multiple servers** takes CSV text, one server per line, without a header line:

| Column | Content |
|---|---|
| 1 | Hostname |
| 2 | SSH port; empty means 22 |
| 3 | Jump host list, may be empty; quote it if it contains commas |
| 4 | Leaders: user login names and group names, separated by semicolons; at least one |

```text
host1.example.com,,"root@j1.example.com:7022,keys-sync@j2.example.com",leader1
host2.example.com,2222,,leader1;ld_group4
host3.example.com,22,,ld_group4;leader2
```

Every line is checked before anything is imported: column count, hostname, port (digits only, at most 65535),
jump host format and that every leader exists, as a user first and as a group otherwise. If any line has an
error, no server is imported and the errors are listed with their line numbers.

A hostname that already exists in SKA is counted as "already known" and left unchanged; its leaders are not
touched. New servers are set up exactly like servers added one at a time.

### Bulk actions on servers

Administrators see a checkbox in front of each server in the server list (the one in the header selects all).
Tick servers and click **Perform a Bulk action on selected servers**. The next page offers two actions:

- **Add a new leader to all selected servers**: enter a user or group name. It is added to each selected server
  that does not already have it. One email lists the servers it was added to; for a group it goes to the
  group's user members. The reporting address gets a copy.
- **Remove leaders from all selected servers**: a table lists every leader of any selected server with the
  number of selected servers it leads. **Remove leader** removes it from all of them. No email is sent.

Both are logged on each affected server. A server without leaders shows "Server has no leaders" in the
leaders column of the server list, highlighted in red if its keys are managed by SKA; nothing stops you from
removing the last one.

### Server settings

The **Settings** tab on a server page lets administrators change the hostname, port, jump hosts, key
management, key supervision, account management, the LDAP access options and the history username
variable. **Clear** next to the host key makes the next sync accept and store whatever host key the server
presents. Saving the form requests a sync of the server.

What account management and the LDAP access options do is described in
[How keys reach servers](../concepts/key-distribution.md), key supervision in
[Unmanaged keys](external-keys.md), and the history variable in
[Configuration reference](../reference/configuration.md#history-username-environment).

Key management has four values:

| Setting | What the sync does |
|---|---|
| SSH keys managed and synced by SSH Key Authority | Writes key files, the normal case |
| Disabled - server has no key management | Nothing |
| Disabled - SSH keys managed by another system | Nothing |
| Disabled - server has been decommissioned | Removes all key files, see below |

!!! warning
    The two "Disabled" settings that are not "decommissioned" leave the server as it is. Key files that SKA
    wrote earlier stay in `/var/local/keys-sync/` and keep granting access. Decommission the server first if
    the keys have to go.

### Decommissioning a server

Set key management to **Disabled - server has been decommissioned (remove all user access keys)** and save.
The sync that this requests logs in as `keys-sync` and deletes the files in `/var/local/keys-sync/` except
`keys-sync`, so SKA keeps its own access. Files whose names start with a dot, such as `.hostnames`, are not
touched. The sync status then reads "Decommissioned: removed
N key files (keys-sync access preserved)".

- If SKA cannot connect, cannot read the key directory or cannot delete a file, the sync is reported as failed
  and rescheduled, like any failed sync. If the machine no longer exists and there is nothing to clean up, set
  key management to "Disabled - server has no key management" instead.
- The server is not deleted; SKA has no way to delete a server. It disappears from the default server list and
  is shown again when you tick **Decommissioned** in the filter.
- Accounts on a decommissioned server are marked inactive in group member lists, and their keys are left out
  of key files on other servers. Those other servers only change when they are next synced; decommissioning
  requests a sync of the decommissioned server only.
- Setting key management back to "managed and synced" writes the key files again on the next sync.

### Notes and contact

The **Notes** tab holds free-text notes about the server, with author and date. It is only shown to
administrators.

The **Contact** tab sends an email about the server. It can come from you or from the system address (with
the administrators' address as reply-to), and go to one of:

- the server leaders, including the user members of active leader groups;
- all users with access to `root` on the server;
- all users with access to any account on the server.

Only active users are mailed. The last two choices count only users who were granted access to an account
directly; users who have access through a group are not included. **Hide recipient list** sends the mail as
Bcc.

## Users

**Users** lists every user SKA knows with the number of active keys; inactive users are greyed out. Users get
into SKA when they first log in or when a lookup by name (see above) finds them in the directory. They are
marked inactive when the directory reports them disabled or no longer finds them. SKA writes no keys for
inactive users. How the active status is read is described in [LDAP and Active Directory](../operations/ldap.md#account-status).

On a user's page, administrators see more than other users: the user's groups, the access granted to the user
directly (not through groups; use [Troubleshoot access](troubleshoot-access.md) for the full picture), and the
servers the user leads.

### Disabling a user

The **Settings** tab, shown for LDAP users only, has two choices: **Use status from LDAP** and **Disable
account (override LDAP)**. A disabled user cannot log in ("Your account has been disabled in SSH Key
Authority.") and is logged out on their next page load. Saving the settings also refreshes the user's details
from the directory when it can be reached.

!!! warning
    Disabling a user here only blocks the SKA web interface. Their keys are still written to servers. To cut
    off SSH access, delete their keys or access rules, or disable the account in the directory so that SKA
    marks the user inactive.

### Adding a key for another user

Open `/users/<uid>/pubkeys` (the **Public keys** heading on the user's page) and paste the key under **Add public
key for ...**. The form is not shown on your own page; add your own keys from the home page. The same type and
size checks apply as for the user's own uploads. Without a comment in the key, SKA uses `<uid>-<date>`. The user
gets an email naming you as the one who added the key, the reporting address gets a copy, and the upload is
logged on the user.

### Deleting another user's key

The key table on `/users/<uid>` has a **Delete public key** button for administrators. Deleting is immediate,
without confirmation. The key is kept as deleted, so it still appears under "Show all public keys" and in the
key list. SKA requests a sync of the server accounts the user has access to and logs the removal on the user;
no email is sent.

### Reassigning servers

If the user leads servers, **Reassign servers** under **Server management** adds checkboxes and a **Reassign
to** field. Enter a user or group name and click **Reassign selected servers**: the new leader is added to each
selected server and this user is removed from it. The new leader is emailed for each server.

The list also shows servers the user leads only through a leader group. Reassigning such a server adds the
new leader, but the user stays a leader through the group.

## Groups

### Local groups

Under **Groups**, **Add group**, **Create local group** takes a name (without `/`) and a first group
administrator, who must be a user. Group administrators manage the group's members and access rules. Only SKA
administrators can add or remove group administrators (**Administrators** tab) and change the group's name or
disable it (**Settings** tab). Disabling a group takes its access rules out of effect; see
[Users, groups and access rules](../concepts/access-model.md).

**Add multiple server accounts** on the **Members** tab takes one `account@hostname` per line. If a line cannot
be resolved, or a group administrator lists an account they do not lead, nothing is added. Unlike adding a single member, this form
does not request a sync; the new members' keys reach servers at their next sync.

### LDAP groups

**Connect ldap group** shows the directory as a tree, starting at `dn_group`, with organizational units as
folders and objects of class `group_class` as checkboxes. Tick groups and click **Connect selected groups**.
Each becomes a system group in SKA, named after its `cn` and linked by its `group_num` attribute. If a group of
that name already exists in SKA, it is reported and not linked.

The tree is read with Active Directory attributes (`distinguishedName`), and `dn_group` itself has to be an
organizational unit. With other directories the tree may stay empty.

Membership of a linked group is managed by SKA, not by hand:

- On each run, `ldap_update.php` adds every active SKA user who is a member in the directory and removes
  every other user, including users who became inactive. A new user is placed in their groups as soon as SKA
  creates them.
- Directory members who are not SKA users yet are not listed.
- The membership list cannot be edited, and system groups cannot be disabled.
- If the directory name of the group changes, `ldap_update.php` renames the SKA group to match.

The groups created from `[defaults] account_groups` (for example `root-accounts`) are system groups as well, so
their members cannot be edited on the group page either.

## Bulk mail

**Tools**, **Bulk mailer** sends one email to either all active users or all server leaders. "Server leaders"
here means users named directly as leader of a server that is not decommissioned; members of leader groups and
account leaders are not included. Recipients are put in Bcc, the visible recipient is "Undisclosed recipients",
and replies go to `admin_address`. The body is prefilled with a line telling recipients why they get the mail.

Emails are only sent when `[email] enabled` is set, and `reroute` sends everything to a single address for
testing. See [Configuration reference](../reference/configuration.md#email).

## Activity

**Activity** lists recent events across SKA: changes to servers, server accounts, users and groups, such as
leaders added, keys uploaded or members removed, with the user who did it and the date in UTC. Administrators
see the newest 100 events of all servers and entities. Server leaders and group administrators see the newest
100 events of the servers they are named leader of directly, of the accounts on those servers and of the
accounts and groups they administer. Group administrators who lead no server can open `/activity` too,
although the menu entry is not shown to them. The full history of one server or group is on the **Log** tab of
its page.

For logs outside the web interface see [Monitoring and logs](../operations/monitoring.md).

## Permissions report

**Report** (`/report`) has three sections, each covering only servers whose keys are managed by SKA:

- **Server leaders**: the effective leaders of each server and the leaders of its accounts.
- **Access rights**: for each managed account, the users that access rules let in.
- **Server-to-Server accesses**: for each managed account, the server accounts that access rules let in.

Servers with identical results are grouped together, largest group first. The lists are worked out the same
way as the key files the sync writes: members of nested groups are included, rules of inactive groups are not,
and a rule counts even when it is restricted by options.

Limits of the report:

- It lists everyone a rule covers, whether or not they currently have a key, and does not look at destination
  restrictions on keys.
- It covers managed accounts only. The per-user key files on servers with LDAP accounts are not part of it.
- Every logged-in user can open it.

For one person and one account, [Troubleshoot access](troubleshoot-access.md) gives an exact answer.

## Public keys and weak keys

The **Managed keys** tab of **Public keys** lists every key stored in SKA, from users and server accounts,
including deleted ones (marked "Deleted"). The filters take an exact MD5 fingerprint (`aa:bb:...`) or SHA256
fingerprint (base64, without the `SHA256:` prefix), an exact key type such as `ssh-rsa`, and a key size range.
For example, key type `ssh-rsa` with a maximum size of 3072 finds all RSA keys of 3072 bits or less.

The size is shown in red when a key is smaller than the configured minimum for its type:

| Key type | Config key in `[security]` | Value used for the red mark |
|---|---|---|
| `ssh-rsa` and other `ssh-*` | `min_rsa_bits` | the setting if at least 2048, otherwise 4096 |
| `ecdsa-sha2-*` | `min_ecdsa_bits` | the setting if at least 256, otherwise 384 |
| `ssh-ed25519` | `min_ed25519_bits` | the setting if at least 256, otherwise 256 |

Uploads are checked against the same settings, but as written, without the lower bounds in the table. Set them
at or above those bounds so the list and the upload check agree. See
[Configuration reference](../reference/configuration.md#key-size-minimums).

!!! note
    The minimum sizes only apply to new uploads. Keys stored before you raised a minimum are still written to
    servers until they are deleted. Use the list to find them.

When adding a key to a server account, administrators can tick **Allow weak (< 4096 bits) key** to skip the
size check. There is no such override for user keys.

The other tabs (**New keys**, **Allowed keys**, **Denied keys**) are about keys that SKA found on servers but
does not manage; see [Unmanaged keys](external-keys.md).

## Tools

The **Tools** menu is shown to administrators only and has two entries:

- **Bulk mailer**, described [above](#bulk-mail).
- **Troubleshoot access**, which explains why a user or key can or cannot log in to an account on a server;
  see [Troubleshoot access](troubleshoot-access.md).

JSON versions of the server, group member and key lists are described in
[CLI scripts and JSON endpoints](../reference/cli-and-api.md).
