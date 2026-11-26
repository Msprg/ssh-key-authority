# Users, groups and access rules

SKA stores three kinds of things that access can be granted between: **users**, **server accounts** and
**groups**. An **access rule** says that one of them (the source) may log in to another (the destination).
The sync turns those rules into one key file per account on each server. This page describes each part and
then exactly how SKA works out whose keys end up in which file.

| Thing | What it is | Can be a rule source | Can be a rule destination |
|---|---|---|---|
| User | A person, taken from LDAP. Owns public keys. | Yes | No |
| Server account | A login name on one server, for example `root@web1.example.com`. Can own public keys. | Yes | Yes |
| Group | A set of users and/or server accounts. | Yes | Yes |

## Users

### Where users come from

Users are not created by hand. A user record appears in SKA the first time the person logs in, or the first
time someone types their username into a form (for example when granting access or adding a group member).
In both cases SKA looks the username up under `dn_user` in LDAP and stores name, email, account status and
admin flag. A user whose LDAP entry has no name or email address cannot be created. Users are never deleted
from SKA.

The periodic LDAP sync (`scripts/ldap_update.php`, run from cron) refreshes every known user: status, admin
flag, name, email and membership of LDAP-linked groups. See [LDAP and Active Directory](../operations/ldap.md)
for the attributes involved.

### Active and inactive

A user is **active** or **inactive** according to LDAP. The status attribute and how it is read are set with
`user_active` and one of `user_active_true`, `user_active_false` or `user_active_bitmask` (see the
[Configuration reference](../reference/configuration.md)). A user that the LDAP sync can no longer find in
the directory is set to inactive. If an LDAP entry lacks an attribute SKA cannot do without, the sync leaves
that user unchanged, carries on with the other users and exits with status 1 at the end.

An inactive user:

- cannot log in to the web interface, and an existing session ends on the next request;
- gets no keys written anywhere: every access rule that covers them writes no key lines for them;
- is removed from all LDAP-linked groups by the next LDAP sync. Membership of local SKA groups and access
  rules that name the user stay in place, so access returns if the user becomes active again.

### Disable account (override LDAP)

On a user's **Settings** tab an administrator can choose between "Use status from LDAP" and "Disable
account (override LDAP)". The override sets a local flag (`force_disable`) that LDAP never changes.

What it does:

- The login form refuses the user with "Your account has been disabled in SSH Key Authority".
- A session the user already has is ended on their next request.

What it does **not** do:

- It does not make the user inactive. The sync checks only the LDAP status, so the user's keys are still
  written to every account they have access to.
- It does not remove the user from any group or access rule.

!!! warning
    "Disable account (override LDAP)" only locks the user out of the SKA web interface. To stop SSH access,
    disable the account in LDAP (and wait for the LDAP sync), delete the user's public keys, or remove the
    access rules and group memberships that cover them.

### Administrators

A user is an SKA administrator if LDAP reports them as a member of a group whose `cn` equals
`admin_group_cn`. With `indirect_group_memberships = 1` (Active Directory only) membership through nested
LDAP groups counts too. The admin flag is refreshed at every login and every LDAP sync; it cannot be set by
hand in SKA. The LDAP sync also keeps a system group of the same name in SKA.

Administrators can do everything, including things nobody else can: create groups, link LDAP groups, change
group and server settings, add and remove server leaders and group administrators, override a user's status,
and use **Troubleshoot access**.

### The keys-sync user

SKA creates an internal user `keys-sync` ("Synchronization script"). It is recorded as the actor of
automatic changes, such as group memberships made by the LDAP sync. It is not a real person. Separately, the account name `keys-sync` on target servers is reserved: SKA never writes a key file
for it (see [How keys reach servers](key-distribution.md)).

## Public keys

Users and server accounts own public keys. A user adds keys to their own profile; an administrator can add
keys for another user and delete them. Keys of a server account are managed on the account page by its
leaders and are used when that account is the source of an access rule (server-to-server access). A key
that is deleted is kept for the record but is never written to a server again. Minimum key sizes are set in
the `[security]` section of the [Configuration reference](../reference/configuration.md).

### Destination rules

A key can carry **destination rules** (the key's "Destination restrictions" tab). Each rule is a pair of an
account name pattern and a hostname pattern. `*` matches any text; every other character is literal, and the
match ignores case.

- A key without destination rules is written everywhere its owner has access.
- A key with destination rules is written only to accounts where at least one rule matches both the account
  name and the server hostname.

Destination rules only narrow down where a key goes. They never grant access. The key owner (for a key of a
server account: a leader of that account or of its server) or an administrator can manage them.

### Signatures

Whoever can manage a key's destination rules can also upload a GPG signature for it. SKA checks that the file looks
like a signature and records the signing key's fingerprint and date. It cannot check who made the
signature, because it has no keyring of trusted signers. Signatures are shown with the key and have no
effect on the sync. Uploading one needs the PHP `gnupg` extension, which the Docker image does not install.
The `[gpg]` section of the configuration is unrelated: it is for signing outgoing email.

## Servers and server accounts

### Key management

Each server has a **Key management** setting. Only servers set to "SSH keys managed and synced by SSH Key
Authority" get key files. "Disabled - server has been decommissioned" makes the next sync remove every key
file on the server except the one for `keys-sync`, and the keys of its accounts are no longer written where
those accounts were granted access. The two other settings ("Disabled - server has no key management",
"Disabled - SSH keys managed by another system") make the sync leave the server alone.

### Accounts setting

For a managed server, **Accounts** decides which key files exist:

| Setting | Key files written |
|---|---|
| All accounts on the server are manually created | One file per server account added in SKA. |
| Accounts will be linked to LDAP and created automatically on the server | The server account files, plus one file per SKA user with a key for this server (see [LDAP user files](#ldap-user-files)). |
| Accounts will be based on LDAP usernames but created manually on the server | As above, but a user's file is only written if that Linux account exists on the server at sync time. |

The access request forms on the server page are only shown for the first setting, and only to users who are
neither administrators nor leaders of the server.

### Server accounts

A server account is a login name on one server. Server leaders add accounts on the server page. A name may
not be empty or start with `.`; slashes are dropped from the key file name. Accounts listed under
`[defaults] account_groups` are created on every new server, added to the named group, and cannot be
removed.

"Removing" an account only deactivates it: it gets no key file, but its rules are kept. Adding an account
with the same name again reactivates it together with its old access rules.

## Groups

A group can hold users and server accounts, in any mix. What a group means in a rule depends on which side
it is on:

- As a **source**, it stands for its users and server accounts: their keys are let in.
- As a **destination**, it stands for its server accounts: the rule applies to every account in the group.
  Users in a destination group play no part.

### Local and system groups

**Local groups** are created by an administrator, who names a first group administrator. Their members are
edited by hand.

**System groups** have a membership that SKA maintains itself and that cannot be edited in the web
interface. They cannot be deactivated. There are three kinds:

- **LDAP-linked groups**, added by an administrator from the LDAP tree on the Groups page. The LDAP sync adds
  each active SKA user whose LDAP group memberships include the group, and removes users who are no longer in it
  or are inactive. Group-in-group nesting in LDAP is followed only with `indirect_group_memberships = 1`. The
  SKA group is renamed when the LDAP group is.
- The **admin group** named by `admin_group_cn`, kept by the LDAP sync.
- **Default account groups** from `[defaults] account_groups` (for example `root-accounts`), which receive
  the default account of each new server. If the group does not exist yet when a server is added, SKA
  creates it as a system group; an existing local group of that name stays a local group.

### Active and inactive groups

An administrator can deactivate a local group in its settings. The rules of an inactive group are not
applied, whichever side of the rule the group is on. The details, including what happens to inactive groups
nested inside other groups, are in [How SKA decides who has access](#how-ska-decides-who-has-access).

### Nested groups

The database allows a group to be a member of another group, and the sync still honours such memberships.
The web interface no longer lets you add one, so nesting only exists in data created earlier. LDAP-linked
groups are always flat in SKA.

### Group administrators

Administrators can make users (not groups) administrators of a group. A group administrator can add and
remove members, except in system groups, and grant or revoke access to the group's resources. To add a
server account to a group they must also lead that account or its server. Group administrators receive
email when members or access rules are added.

## Leaders

### Server leaders

A server leader is a user or a group, assigned by an administrator. A user counts as a leader if they are
assigned directly or are a direct member of a leader group. Server leaders can add and remove accounts,
manage access rules, public keys and access requests for every account on the server, and appoint account
leaders. They can reset the server's host key only if `host_key_reset_restriction = 0`.

Notification emails go to leader groups' members only while the group is active. Deactivating a leader group
stops those emails, but its members are still treated as leaders.

### Account leaders

An account leader is a user assigned to one server account by a server leader or an administrator. They can
manage the access rules, public keys and access requests of that account only. Access requests and grant
notifications for an account go to its account leaders, with the server leaders in copy; if the account has
no leaders, they go to the server leaders.

## Access rules

### Source and destination

An access rule has one source (a user, a server account or a group) and one destination (a server account or
a group). Rules on an account are managed on the account page by its account leaders, the server leaders and
administrators; rules on a group on the group page by its group administrators and administrators. There is
at most one rule per source and destination: granting the same access again keeps the existing rule and its
options. Every grant and removal is logged; grants are also announced by email.

### Access options

A rule can carry SSH options. They are written in front of every key line that the rule produces, including
the lines of every member of a source group.

| Option | Form label | Effect in `authorized_keys` |
|---|---|---|
| `command="..."` | Specify command | Forces this command whatever the client asks for. |
| `from="..."` | Restrict source address | Accepts the key only from hosts matching the pattern list (hostnames or IPs, `*` and `?` allowed). |
| `no-port-forwarding` | Disallow port forwarding | Disables port forwarding. |
| `no-X11-forwarding` | Disallow X11 forwarding | Disables X11 forwarding. |
| `no-pty` | Disable terminal | Allocates no terminal. |

The form's presets fill in these fields: "Command", "DB backup" and "CheckMK" set a command together with
`no-port-forwarding`, `no-X11-forwarding` and `no-pty`. The database also accepts `no-agent-forwarding`, but
the form does not offer it. Options can be changed later from the rule's edit page.

Servers with LDAP accounts have a separate set of **LDAP access options** in their settings, with the same
choices, that apply to every LDAP user file on that server. Only administrators can change them. SKA can
also add an `environment="..."` option containing the username to user key lines; see
[History username environment](key-distribution.md#history-username-environment).

### Access requests

On a server whose accounts are created manually, any user can request access to an account for themselves,
for a server account (server-to-server) or for a group. If the account does not exist yet, it is created in
a "proposed" state, which gets no key file until access to it is granted.

- The request is emailed to the account leaders (server leaders in copy), or to the server leaders if the
  account has none.
- Account leaders, server leaders and administrators approve or reject it on the account page. Approval
  creates an access rule without options. When the request was for a user, that user is told the outcome
by email; requests for a server account or a group send no reply.
- Rejecting the last request for a proposed account deactivates the account again.

SKA does not check that the person requesting access for a server account or a group has anything to do with
it. The approver has to judge that.

## How SKA decides who has access

All of this is implemented once, in `keyfile_common.php`. The sync, **Troubleshoot access**, the permissions
report and the inherited rules shown on server and account pages all use the same functions to work out
rules, grantees and key file content.

### Which accounts get a key file

On a managed server, every server account gets a key file except accounts that were removed, accounts that
are still only proposed, and `keys-sync`. If two account names map to the same file name after slashes are
dropped, the last one wins.

### Which rules apply to an account

The rules for an account are collected in this order:

1. The rules of every group the account is a member of, directly or through other groups. A group reached
   through more than one chain of memberships is listed once per chain.
2. The rules on the account itself.

The rules of an inactive group are left out. A group still counts if the account is only in it through an
inactive group: if account `db1` is in inactive group B, and B is in active group A, A's rules apply to
`db1`, B's do not.

### Whom a rule covers

- A user or server account source stands for itself.
- A group source stands for its members, and the groups among them for theirs. Only the group the rule names
  must be active. If it is inactive the rule covers nobody; inactive groups further down are expanded as
  normal.
- Within one rule each group is expanded once, by name, so groups that contain each other do not loop.
- A user or server account reached in several ways is listed each time, and their keys are written each time.

### What is written for each grantee

- An active user: each of their keys that is not deleted and whose destination rules match this account and
  hostname, prefixed by the rule's options.
- An inactive user: no keys. With key file comments enabled, a `# Account disabled` line.
- A server account: its matching keys, unless its server is decommissioned. Whether the source account itself
  has been removed from its server is not checked, so remove or change the rules of an account you retire.

SKA does not merge or deduplicate lines. If the same key reaches an account through two rules with different
options, both lines are written, and sshd reads the file from the top.

### LDAP user files

On servers whose accounts are linked to LDAP, SKA also writes a file named after each user's username, for
every SKA user who has at least one key matching that server. These files hold only the user's own keys with
the server's LDAP access options; access rules and groups play no part. Every user known to SKA qualifies,
not only those who were granted something. An inactive user's file holds no keys. If the server has a server
account of the same name that gets a key file (see above), that account's file is written instead.

## Checking the result

To see the outcome for a particular person, server and login name, use
[Troubleshoot access](../guide/troubleshoot-access.md). It shows the rules and groups involved, the key file
the sync would write and where access breaks. For how the files are delivered and what the target server
must allow, see [How keys reach servers](key-distribution.md).
