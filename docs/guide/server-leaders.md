# For server leaders

This page is for people who are responsible for servers or server accounts in SSH Key Authority (SKA). It
explains what a leader can do, how to manage accounts and access rules, and how to tell whether your changes
have reached the server. For uploading your own keys and requesting access, see [For users](users.md).

## Server leaders and account leaders

SKA has two kinds of leadership below the SKA administrators.

| | Server leader | Account leader |
|---|---|---|
| Assigned to | A user, or a group | A user only |
| Assigned by | An SKA administrator | A server leader or an SKA administrator |
| Scope | Every account on the server | One account on one server |

A user is a server leader if they are listed as a leader themselves or are a direct member of a group that is
listed. Membership through a nested group does not count. Server leaders are set on the server page or when the
server is added (see [For administrators](administrators.md)); a leader cannot add or remove server leaders.

Your home page lists the servers you lead under "Your servers", with their configuration, leaders and sync
status. Decommissioned servers are left out of that list. Accounts you lead on a server you do not lead appear
under "Managed accounts" on that server's page.

### What leadership allows

| Action | Server leader | Account leader | Administrator |
|---|---|---|---|
| Create and delete accounts on the server | Yes | No | Yes |
| Grant, configure and remove access on an account | Yes | Own account | Yes |
| Approve and reject access requests | Yes | Own account | Yes |
| Add and delete public keys of an account (outbound access) | Yes | Own account | Yes |
| Add and remove account leaders | Yes | No | Yes |
| Add and remove server leaders | No | No | Yes |
| Change server settings | No (see [Host key reset](#host-key-reset)) | No | Yes |
| Read and write server notes | No | No | Yes |
| Send mail to the users of a server (Contact tab) | No | No | Yes |
| Use "Troubleshoot access" | No | No | Yes |

The account page (`/servers/<hostname>/accounts/<account>`) and the page for an access rule's options are only
open to the server's leaders, the account's leaders and administrators. On the account page, anyone else sees
"Access Denied" with a link to the server page; on the options page they get a plain "Access denied" error.

The server page itself is open to every logged-in user. Users who are neither server leaders nor administrators
do not get the tabs. They see the sync status (on managed servers), the accounts they lead under "Managed
accounts" (if any) and, on servers whose accounts are all created manually, the accounts they have access to and
the forms to request access.

## Accounts

The "Accounts" tab of the server page lists the active accounts. For each account it shows the sync status (on
managed servers), a badge with the number of pending access requests, and everyone who has access, with "Direct"
for rules on the account itself and "via <group>" for rules inherited through a group the account belongs to
(see [Inherited group access](#inherited-group-access)).

### Adding an account

Enter the name under "Create account" ("Create non-LDAP account" on LDAP servers) and choose **Manage this
account with SSH Key Authority**. The name cannot be empty or begin with a dot. Leading and trailing spaces are
removed. If an inactive account with the same name exists, SKA reactivates it instead of creating a new one.

Creating the account in SKA does not create the Unix account on the server. SKA only writes the key file for it;
the account must exist on the server for the keys to be used.

On servers whose accounts are linked to LDAP ("automatic LDAP" or "manual LDAP"), LDAP users get their key
files without an account in SKA; add accounts here only for logins that are not LDAP usernames, such as `root`
or a service account. See [How keys reach servers](../concepts/key-distribution.md).

### Deleting an account

**Delete account** marks the account inactive and queues a sync. The account no longer appears in the list.

Accounts that SKA creates on every server (the `account_groups` entries in the `[defaults]` section of
`config.ini`; the example configuration sets `root`) have no delete button and cannot be made inactive. See the
[Configuration reference](../reference/configuration.md).

## The account page

Open an account with **Manage account**. The page has these tabs:

| Tab | Content |
|---|---|
| Access | Direct access rules, pending requests, forms to grant access, and the rules inherited from groups. Only shown when the server's key management is "SSH keys managed and synced by SSH Key Authority". |
| Public keys | Keys this account uses for outgoing server-to-server connections. |
| Outbound access | Other accounts and groups this account has been granted access to. |
| Leaders | Account leaders. Server leaders and administrators can add and remove them here. |
| Log | Changes to this account. |

On servers that SKA does not manage keys for, there is no Access tab, so access cannot be granted.

## Granting access

The Access tab has three forms:

- **Add user to account**: one SKA user, by username.
- **Add server-to-server access to account**: another account, given as account name and hostname. That
  account's public keys (its "Public keys" tab) are then allowed to log in to this account.
- **Add group access to account**: an SKA group. Every member of the group gets access, including members of
  nested groups. See [Users, groups and access rules](../concepts/access-model.md).

A note on the page points out that single-user and server-to-server rules tend to be confusing and suggests a
group rule instead.

After you submit a form, SKA shows a "Grant access" page. Open **Advanced options** to restrict the access (see
[Access options](#access-options)), then choose **Confirm access**. SKA then:

- stores the rule with your username and the date,
- sends an email to the account leaders with the server leaders in copy (or to the server leaders if the account
  has no leaders), with the `report_address` from the `[email]` section in copy,
- writes a log entry and queues a sync of the account.

If the user, group or account already has a direct rule on this account, no second rule is created, no email is
sent and the options you chose are not applied. To change the options of an existing rule, use **Configure
access**.

### Access options

The options of a rule are written in front of each key that the rule puts into the account's key file, as
`authorized_keys` options.

| Option | Effect |
|---|---|
| `command="..."` | Only this command can be run with the key. |
| `from="pattern-list"` | Only connections from matching hosts or IP addresses are accepted. Patterns are separated by commas and may contain `*` and `?`. |
| `no-port-forwarding` | Port forwarding is refused. |
| `no-X11-forwarding` | X11 forwarding is refused. |
| `no-pty` | No terminal is allocated. |

The presets fill in the form: **Default** clears all options; **Command** enables `command`, `no-port-forwarding`,
`no-X11-forwarding` and `no-pty` and leaves the command for you to type; **DB backup** and **CheckMK** do the same
with a backup command and `/usr/bin/check_mk_agent` filled in. Check the command before you confirm.

To change options later, choose **Configure access** next to the rule, edit the options and choose
**Modify access**. This queues a sync of the account.

### Removing access

**Remove access** deletes a direct rule, writes a log entry and queues a sync. No email is sent.

Rules inherited from a group are not listed among the direct rules and cannot be removed on the account page.
Change them on the group's page, or remove the account from the group; that needs a leader of the group or an
administrator.

## Access requests

On servers whose accounts are all created manually, users who are neither server leaders nor administrators can
request access to an account for themselves, for a server account (server-to-server access) or for a group. A
request for an account name that does not exist yet creates the account with the status "Requested". Server
leaders and administrators do not see the request forms; they grant access directly instead.

The request is mailed to the account leaders with the server leaders in copy, or to the server leaders if the
account has no leaders. Your home page and the Accounts tab show a badge with the number of pending requests.

Pending requests appear at the bottom of the access table on the account page:

- **Approve** creates an access rule without options and removes the request. A user who requested access for
  themselves gets an email. Add options afterwards with **Configure access** if needed.
- **Reject** removes the request. A user who requested access for themselves gets an email. If the account was
  created by the request and no other requests remain, the account is made inactive and no longer appears in
  the server's account list.

Requests for groups and server accounts send no email to the requester on approval or rejection.

## Inherited group access

An account can be a member of groups, for example `root` in the group named in `account_groups`. Access rules on
those groups apply to the account as if they were set on it.

- On the server page, the Accounts tab lists these rules next to the direct ones, marked "via <group>".
- On the account page, the "Group access rules" section lists each group with its rules. An inactive group is
  marked "Inactive" and its rules are not applied. A group without rules is shown with a note that it grants no
  access.

Both views use the same rules the sync uses to write the key file. Rules of inactive groups are left out.

## Public keys of an account

Keys on the "Public keys" tab are used for **outgoing** connections from this account to accounts it has been
granted access to. They do not give anyone access to this account. Adding a key sends an email to the server
leaders, with the `report_address` in copy, and writes a log entry. The minimum key size applies; only an
administrator can override it for a weak key. The tab also links to the keys in TXT and JSON format.

## Account leaders

On the account's Leaders tab, server leaders and administrators can add a user as account leader. The new leader
gets an email with a link to the account, with the `report_address` in copy. Adding and removing account leaders
is logged.

Account leaders receive the emails about access requests and new access rules for their account, with the server
leaders in copy.

## Server settings

Leaders see the server's settings on the Settings tab but cannot change them:

- SSH port number and jumphosts
- Key management (managed by SKA, no key management, managed by another system, or decommissioned)
- How accounts are created (manually, automatic LDAP or manual LDAP)
- LDAP access options, on servers with LDAP accounts
- History username environment setting, on managed servers

Hostname, port, host key, jumphosts, key management, key supervision (scanning for unmanaged keys), account
type, LDAP access options and the per-server history username environment setting can only be changed by an
administrator, apart from clearing the host key (below). Ask one if a setting is wrong. The global defaults for
the history username environment are described in the [Configuration reference](../reference/configuration.md).

### Host key reset

SKA stores the server's SSH host key at the first connection and refuses to sync ("SSH host key verification
failed") if the key later changes. After a legitimate change (for example a reinstalled server) the stored key
must be cleared.

If `host_key_reset_restriction = 0` is set in the `[security]` section of `config.ini`, server leaders see the
host key on the Settings tab with a **Clear** button. Clear it and choose **Change settings**: SKA queues a sync
and stores the key the server presents at the next connection. With the value `1` (the example configuration) or
without the setting, only administrators can do this.

!!! warning
    Clear the host key only when you know why it changed. A changed host key can also mean that the hostname
    now points to a different machine.

## Sync status

On managed servers, the top of the server page shows the sync status: the message of the last sync, or "Not
synced yet". While a sync is pending, the page shows "Pending" and checks again until the sync has run. Unless the
last sync succeeded, an **Explain** button links to the matching section of the help page.

Each account has its own status:

| Status | Meaning |
|---|---|
| Synced | The last sync of the account succeeded. |
| Not synced | The account has not been synced yet. |
| Failed | The last sync of the account failed. |
| Requested | The account was created by an access request and nothing has been approved yet. |
| Pending | A sync of the account or the whole server is queued. |

The page also warns when the hostname resolves to the same IP address (and port) as another managed server, when
another managed server has the same host key, and when the scan for unmanaged keys failed. See
[Unmanaged keys](external-keys.md).

### Triggering a sync

Granting, configuring and removing access, deleting or reactivating an account, clearing the host key and most
other setting changes queue a sync on their own. Creating a new account does not. To sync the whole server by
hand, choose **Sync now** next to the sync status. It appears once no sync is pending. This is useful on "manual LDAP"
servers after you create a user's account on the server, since the key file is only written for accounts that
exist at the time of the sync.

The button queues a request; the sync daemon carries it out. The status updates on the page when it has run. The
button is shown to every user who opens the server page, not only to leaders.

## Orphaned servers

When the LDAP update (`scripts/ldap_update.php`) marks a user inactive, SKA checks the servers that user still
leads after the update, except decommissioned servers. If no active leader remains, it sends the email "Server
<hostname> has been orphaned":

- If `user_superior` is set in the `[ldap]` section, the email goes to the user's superior, or the next active
  superior up the chain, with the `report_address` in copy.
- If no active superior is found, or `user_superior` is not set, it goes to the `report_address` with
  "NO SUPERIOR EMPLOYEE FOUND" added to the subject.

The email asks for a replacement leader and says the server will otherwise be registered for decommissioning.
SKA itself does not change the server; an administrator has to assign a new leader or decommission it. The
check runs only when the LDAP update deactivates a user, not when a leader is removed by hand.

Administrators see "Server has no leaders" in the server list for servers without any leader. See
[For administrators](administrators.md) and [LDAP and Active Directory](../operations/ldap.md).

## The log

The server's Log tab lists changes to the server and to all of its accounts: setting changes, accounts added,
leaders added and removed, access granted, removed, requested, approved and rejected, public keys added and
removed, and sync status changes, each with the user who made the change and the time in UTC.
