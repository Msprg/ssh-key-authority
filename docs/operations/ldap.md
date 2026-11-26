# LDAP and Active Directory

SKA has no local passwords and no user management of its own. Every person who logs in is an LDAP user,
their name, email address, account status and admin flag come from the directory, and groups can be linked
to directory groups. This page describes the `[ldap]` section of `config.ini` and what SKA does with it.
Each key is also listed in the [Configuration reference](../reference/configuration.md#ldap).

## When SKA talks to the directory

| When | What happens |
|---|---|
| Login | SKA looks up the entry for the typed username, checks the password by binding as that entry, and refreshes the user's details and admin flag. |
| A username is used for the first time | When someone logs in for the first time, or a username is typed into a form (adding a group member, granting access, creating a group), SKA reads the entry under `dn_user` and stores the user. |
| Every minute (`scripts/ldap_update.php`) | Every known user is refreshed: name, email, status, admin flag and membership of LDAP-linked groups. Names of LDAP-linked groups are refreshed. |
| Groups page, "Connect ldap group" | An administrator browses `dn_group` and links directory groups to SKA. |

Users are never deleted from SKA. A user who is gone from the directory is set to inactive (see
[When someone leaves](#when-someone-leaves)).

## Connection

```ini
[ldap]
host = ldaps://ldap.example.com:636
starttls = 0
follow_referrals = 0
bind_dn = "CN=svc-ska,OU=Service Accounts,DC=example,DC=com"
bind_password = "..."
```

- `host` is passed to PHP's `ldap_connect()`, so it is an LDAP URI: `ldaps://host:636` for LDAP over TLS,
  `ldap://host:389` for plain LDAP.
- `starttls = 1` upgrades an `ldap://` connection with StartTLS before binding. Use it when the server
  does not offer LDAPS. If StartTLS fails, SKA treats the directory as unreachable.
- `follow_referrals` sets `LDAP_OPT_REFERRALS`. Leave it at `0` for Active Directory, where following
  referrals during searches of the whole tree causes errors.
- SKA always uses LDAP protocol version 3.

### Service account

`bind_dn` and `bind_password` are the account SKA uses for every search: finding users, reading their
attributes and searching groups. Leave `bind_dn` empty to search anonymously, which Active Directory
normally does not allow. The account needs read access to the user entries under `dn_user` (including the
status attribute) and to the groups under `dn_group`. It does not need write access.

The service account is used only for searches. A user's password is checked over a separate connection
that binds as that user (see [How login works](#how-login-works)).

!!! warning
    If the service account can bind but cannot see a user's entry, the search returns nothing, and the LDAP
    update treats the user as removed from the directory and makes them inactive. Test a changed account or
    permission set with `ldapsearch` before putting it into `config.ini`.

### Certificate trust for LDAPS and StartTLS

SKA sets no TLS options itself. Certificate verification is done by libldap, the library behind PHP's
LDAP extension, which reads its CA file from `/etc/ldap/ldap.conf`
(`/etc/openldap/ldap.conf` on RHEL-based systems). Without a CA file every TLS connection fails with
"Can't contact LDAP server". The repository and the Docker image ship `etc/ldap/ldap.conf`:

```ini
TLS_CACERT /etc/ssl/certs/ca-certificates.crt
```

This trusts certificates from the public CAs in the system bundle. For a directory with an internal CA,
point `TLS_CACERT` at a file that contains that CA, or add the CA to the system bundle. libldap reads the
file once per process, so restart php-fpm after a change. The cron jobs start a new PHP process on every
run and pick up the change on their own. Setup details are in
[Install with Docker](../getting-started/docker.md) and
[Install without Docker](../getting-started/bare-metal.md#ldaps-certificate-trust).

## Example configurations

### Active Directory

This is the setup `config/config.ini.example` is written for:

```ini
[ldap]
host = ldaps://dc1.example.com:636
starttls = 0
dn_user = "CN=Users,DC=example,DC=com"
dn_group = "OU=Groups,DC=example,DC=com"
follow_referrals = 0
bind_dn = "CN=svc-ska,OU=Service Accounts,DC=example,DC=com"
bind_password = "..."

user_id = sAMAccountName
user_name = displayName
user_email = mail
user_active = userAccountControl
user_active_bitmask = '!2'

group_member = member
group_member_value = dn
group_class = group
group_id = sAMAccountName
group_num = objectGUID
admin_group_cn = "Key Admins"
indirect_group_memberships = 1
```

The example file uses `sAMAccountName` for `user_name` too; `displayName` shows the full name instead.

### OpenLDAP

The commented alternatives in `config.ini.example` describe an OpenLDAP directory with `posixGroup` groups
and a status attribute holding a word:

```ini
[ldap]
host = ldap://ldap.example.com:389
starttls = 1
dn_user = "ou=users,dc=example,dc=com"
dn_group = "ou=groups,dc=example,dc=com"
follow_referrals = 0
bind_dn = "cn=ska,ou=services,dc=example,dc=com"
bind_password = "..."

user_id = uid
user_name = cn
user_email = mail
user_active = organizationalstatus
user_active_true = 'current'

group_member = memberUid
group_member_value = uid
group_class = posixGroup
group_id = cn
group_num = gidNumber
admin_group_cn = "ska-admins"
indirect_group_memberships = 0
```

For `groupOfNames` groups use `group_member = member` and `group_member_value = dn` instead.

!!! warning
    Parts of the group handling assume Active Directory. SKA treats the value of `group_num` as a binary
    GUID like `objectGUID`, and the group browser on the Groups page searches by `distinguishedName` and
    expects `dn_group` to be an `organizationalUnit`. With OpenLDAP and `gidNumber`, the admin flag and the
    admin group work, but connecting other directory groups from the Groups page and refreshing group names
    in the LDAP update do not.

## User attributes

| Key | Used for |
|---|---|
| `dn_user` | Subtree searched for users. Users outside it cannot log in and cannot be added. |
| `user_id` | Attribute matched against the username. Its value becomes the username in SKA and the account name in [LDAP user key files](../concepts/key-distribution.md#ldap-user-key-files). |
| `user_name` | Display name. Required. |
| `user_email` | Email address for notifications. Required. |
| `user_superior` | Optional. Attribute holding the username of the person's manager, used for orphaned-server emails. |

A user is found with the search `(<user_id>=<username>)` under `dn_user`. The login form accepts only
letters, digits, dots, hyphens and underscores, so users log in with the plain `user_id` value
(`jdoe`), not a UPN (`jdoe@example.com`) or `DOMAIN\jdoe`.

An entry without a `user_name` or `user_email` value cannot be created in SKA and cannot log in. The login
form tells the user which attribute is missing, but only after their password has been accepted.

`user_superior` must contain a value that is itself a username (a `user_id` value). SKA looks that name up
as a user and stores the link. Attributes that hold a DN, such as Active Directory's `manager`, do not
resolve to a user, so the user ends up without a superior. Leave `user_superior` unset unless your directory
has such an attribute.

## Account status

`user_active` names the attribute that says whether the account is active. Set exactly one of the three
rules below to say how it is read. If more than one is set, `user_active_true` wins over
`user_active_false`, which wins over `user_active_bitmask`.

| Setting | User is active when | Attribute missing |
|---|---|---|
| `user_active_true = 'current'` | the attribute equals the value | inactive |
| `user_active_false = 'former'` | the attribute does not equal the value | active |
| `user_active_bitmask = '2'` | the bit with value 2 is set | user is not synced, error |
| `user_active_bitmask = '!2'` | the bit with value 2 is not set | user is not synced, error |

For Active Directory, `user_active = userAccountControl` with `user_active_bitmask = '!2'` reads the
"account disabled" flag. The bitmask must be a number, optionally preceded by `!`.

- If `user_active` is not set at all, every user is active.
- If `user_active` is set but none of the three rules is (or the bitmask is not a number), every user is
  inactive.

Only this one attribute decides the status. With the Active Directory setting, an account that is locked
out or expired but not disabled stays active in SKA, and its keys keep being written to servers. Such a
user is refused at the SKA login, but that does not affect SSH access. Disable the account in the directory
to remove access.

## Groups

### How memberships are found

To find the groups of a user, SKA reads the user's `group_member_value` attribute (`dn` means the entry's
DN) and searches under `dn_group` for `(<group_member>=<value>)`. With `indirect_group_memberships = 1` the
search uses Active Directory's matching rule `1.2.840.113556.1.4.1941`, so groups the user belongs to
through nested groups are found as well. OpenLDAP does not support that rule; set `0` there. The search does
not filter on `group_class`.

`group_class` and `group_num` are used to link directory groups:

- `group_class` is the object class that the group browser on the Groups page shows as a group.
- `group_num` is the attribute that identifies a group permanently (`objectGUID`). A linked group is stored
  with this value, so it stays linked when it is renamed or moved within `dn_group`.
- `group_id` is the attribute matched against `admin_group_cn` to find the admin group's `group_num`.

### LDAP-linked groups

An administrator links directory groups under **Groups**, "Connect ldap group". The browser starts at
`dn_group`, which must be an organizational unit, and shows the organizational units and objects of class
`group_class` below it. A linked group gets the
group's `cn` as its name in SKA.

The members of a linked group are managed by the LDAP update and cannot be edited in SKA. On each run,
every active user who is a member in the directory is added and everyone else is removed. Only users SKA
already knows are considered: a directory member who has never logged in and was never added by name is
not a member in SKA. Access rules on a linked group are managed in SKA like on any other group, see
[Users, groups and access rules](../concepts/access-model.md).

If a linked group is renamed in the directory, the next run renames it in SKA (the new name is read from
the `name` attribute). If it is deleted, SKA keeps the group and the next run removes all its members.

### Administrators

There is no built-in admin account. A user is an SKA administrator when the group search above returns a
group whose `cn` is exactly `admin_group_cn` (case matters). The flag is set at every login and on every
run of the LDAP update, so removing someone from the group takes their admin rights away at the next run of
the update (every minute with the shipped crontab).

The LDAP update also creates a group named `admin_group_cn` in SKA if it does not exist and links it to the
directory group found by `(<group_id>=<admin_group_cn>)`. With Active Directory, this means `cn` and
`sAMAccountName` of the admin group must both equal `admin_group_cn`. If the second search finds nothing, the
admin flag still works, but the SKA group of that name has no members.

## How login works

1. An empty password is refused straight away. An empty password would be an unauthenticated bind,
   which directories accept without checking anything.
2. The service account searches `dn_user` for `(<user_id>=<username>)`. If there is no entry, or more than
   one, the login is refused as "Invalid username or password" and no bind is attempted.
3. SKA binds as the DN of that entry with the typed password. The account whose password was verified is
   always the account that gets the session; SKA never binds with a name built from the username.
4. The user's details are loaded from the same entry. If the entry changed between the two lookups, the
   login is refused.
5. The user is refused if an administrator has disabled them in SKA ("Disable account (override LDAP)") or
   if their directory status makes them inactive.

### Messages

Before the password is verified, every failure that could depend on the account looks the same: "Invalid
username or password". This includes unknown usernames, usernames with more than one entry and accounts
outside `dn_user`, so the form cannot be used to find out which accounts exist. Only this message counts
towards the failed-attempt limit; see [For users](../guide/users.md#failed-attempts-and-lockout).

Two kinds of failure are reported as such because they do not depend on the account:

- The directory cannot be reached (connection, TLS, timeout, server busy or unavailable): "The directory
  server could not be reached".
- The user's bind is refused for a reason that concerns the connection, not the account (LDAP result codes
  2, 7, 8 and 13, for example a server that demands TLS or signing), the service account cannot bind, or a
  search fails: "cannot use the directory because of a configuration problem".

Active Directory adds a sub-code to a refused bind (`data 532` in the diagnostic message). SKA turns the
following ones into specific messages, because AD sends them only when the password was correct:

| Sub-code | Meaning | Message to the user |
|---|---|---|
| `530` | logon hours | not permitted to log in at this time of day |
| `531` | workstation restriction | restricted to specific workstations |
| `532` | password expired | change your password, then log in again |
| `533` | account disabled | your directory account is disabled |
| `701` | account expired | your directory account has expired |
| `773` | password must be changed | change your password, then log in again |

All other sub-codes, including `525` (user not found) and `775` (locked out), are reported as "Invalid
username or password". AD reports a lockout whatever password was sent, so showing it would reveal that the
account exists.

### Logs

Each attempt is logged to the PHP error log (see [Monitoring and logs](monitoring.md#where-the-logs-are)),
with the username shortened to its first three characters:

```text
Authentication attempt for user: jdo***
LDAP bind refused for user jdo***: Could not bind to LDAP server: Invalid credentials (49): 80090308: ... data 52e ...
Login refused for user jdo***: invalid_credentials
```

The `Login refused` line names the reason (`invalid_credentials`, `directory_unavailable`,
`directory_config`, `password_expired`, `missing_attribute`, ...) and, for errors raised further down, the
exception behind it. A line `Found 0 directory entries for user jdo*** under dn_user (need exactly one);
refusing login` means the search found no entry for the username, usually a wrong `dn_user` or `user_id`.

## The LDAP update job

`scripts/ldap_update.php` keeps SKA in line with the directory. The Docker image runs it every minute from
`etc/cron.d/ska` as `www-data`, under `flock` so that runs do not overlap, and appends its output to
`/var/log/ska/ldap_update.log`. Nothing in the image rotates that file. Without Docker, install the same
cron line (see [Install without Docker](../getting-started/bare-metal.md#cron-jobs)).

To run it by hand:

```bash
docker compose exec --user www-data ska-app php /srv/keys/scripts/ldap_update.php
```

### What one run does

1. Creates the admin group if needed and links it to the directory (see [Administrators](#administrators)).
2. For every user in SKA whose realm is LDAP: reads the entry, updates name, email, status and admin flag,
   reads the superior if `user_superior` is set, and updates membership of all LDAP-linked groups. A user
   whose entry is not found is set to inactive.
3. For every LDAP-linked group: looks it up by its `group_num` value and updates its name.

The output lists every user and group with what was done. Changes are written to the audit log like changes
made in the web interface, and a change of status queues syncs of the user's access (see
[How keys reach servers](../concepts/key-distribution.md#sync-requests)).

### Missing attributes

A missing name or email address does not stop the update: SKA keeps the stored value, prints a `WARNING`
line, and still applies status and group changes. The user cannot log in until the attribute is set.

A missing `user_id` or `group_member_value` value, or a missing status attribute when a bitmask is
configured, means the user cannot be evaluated. The run prints an `ERROR` line, leaves that user exactly as
it was (status, admin flag, groups) and carries on with the others.

### Exit code

The run exits with status `1` if any user was left unchanged because of a missing attribute, and writes a
summary line naming those users to stdout and stderr. This is meant for monitoring wrappers; the shipped crontab only
appends the output to the log.

If the directory cannot be reached or a search fails, the run stops with an uncaught exception and a
non-zero exit code. Users processed before that point keep their updates; nobody is set to inactive because
the directory was down. Problems looking up a group's name are printed as `ERROR` lines and do not change
the exit code. See [Monitoring and logs](monitoring.md).

## When someone leaves

The normal way to remove a person's access is to disable or delete their directory account. Within a
minute, the LDAP update:

1. sets the user to inactive (status attribute says so, or the entry is no longer found under `dn_user`);
2. removes them from every LDAP-linked group;
3. queues syncs for every account they had access to and, on servers whose accounts are based on LDAP,
   for their own account name. The sync writes no keys for inactive users.

An existing web session ends on the user's next request. Local SKA groups and access rules that name the
user stay in place, so access returns if the account is activated again.

If the user was a leader of servers that now have no active leader, SKA sends an email "Server ... has been
orphaned" per server. It goes to the user's superior (or the first active superior further up), with a copy
to `[email] report_address`. Without `user_superior`, or when no active superior is found, it goes to
`report_address` with "NO SUPERIOR EMPLOYEE FOUND" in the subject. SKA does not change the server itself;
see [For server leaders](../guide/server-leaders.md#orphaned-servers).

"Disable account (override LDAP)" on a user's settings tab only blocks the SKA web login. It does not make
the user inactive, and their keys are still distributed. See
[Users, groups and access rules](../concepts/access-model.md#disable-account-override-ldap).

## Checking the configuration

Run the searches SKA runs with `ldapsearch`, which uses the same library and `ldap.conf`:

```bash
# The user entry, as found at login and by the update
ldapsearch -H ldaps://dc1.example.com -D "CN=svc-ska,OU=Service Accounts,DC=example,DC=com" -W \
  -b "CN=Users,DC=example,DC=com" "(sAMAccountName=jdoe)" dn sAMAccountName displayName mail userAccountControl

# The user's groups, including nested ones (Active Directory)
ldapsearch -H ldaps://dc1.example.com -D "CN=svc-ska,OU=Service Accounts,DC=example,DC=com" -W \
  -b "OU=Groups,DC=example,DC=com" "(member:1.2.840.113556.1.4.1941:=CN=John Doe,CN=Users,DC=example,DC=com)" cn objectGUID
```

The first search must return exactly one entry with a name and an email address. The second must list the
group whose `cn` is `admin_group_cn` for an administrator. After that, run the LDAP update by hand and read
its output.
