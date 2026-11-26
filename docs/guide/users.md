# For users

This page is for people who use SSH Key Authority (SKA) to get their SSH keys onto servers. You upload a public
key once; SKA writes it to every server account you have been given access to.

If you lead servers, also read [For server leaders](server-leaders.md).

## Logging in

Open SKA in your browser, for example `https://ska.example.com/`. Every page except the login page needs a
session, so you are sent to the login page first and returned to the page you asked for after a successful
login.

Log in with your directory (LDAP or Active Directory) username and password. The username may contain only
letters, digits, dots, hyphens and underscores, so log in with the short account name, not with an email
address or `DOMAIN\name`. The first time you log in, SKA creates your user from the directory entry.

### Login error messages

| Message | What it means |
|---|---|
| Invalid username or password. | The username does not exist, the password is wrong, or the directory refused the login for a reason SKA does not pass on (for example a locked account). SKA deliberately does not tell these cases apart. |
| Invalid username format. ... | The username contains a character other than letters, digits, `.`, `-` and `_`. |
| Please enter both username and password. | The password was empty. An empty username gets the "Invalid username format" message instead. |
| Too many login attempts. Please try again in N minutes. | See [Failed attempts and lockout](#failed-attempts-and-lockout). |
| Invalid request token. Please refresh the page and try again. | The login form was stale, for example opened in another tab or kept open too long. Reload the page. |
| Your password is correct, but your directory account has no email address ... | SKA needs an email attribute on your directory entry. Ask your directory administrator to add it. The same kind of message names any other required attribute that is missing. |
| Your password is correct, but your account was not found in the part of the directory that SSH Key Authority is configured to use. | Your entry is outside the user tree SKA searches. Contact an administrator. |
| Your account has been disabled in SSH Key Authority. | An SKA administrator has disabled your user. |
| Your directory account is disabled. | The directory reports your account as disabled, or SKA has marked you inactive. |
| Your directory account has expired. | Active Directory reports the account as expired. |
| Your directory password has expired. / ... requires a password change ... | Change your directory password with your usual tools, then log in again. SKA cannot change it for you. |
| Your directory account is not permitted to log in at this time of day. | Active Directory logon hours forbid the login now. |
| Your directory account is restricted to specific workstations ... | Active Directory "log on to" restrictions exclude the SKA server. |
| The directory server could not be reached ... | SKA cannot reach LDAP. Try again later. |
| SSH Key Authority cannot use the directory because of a configuration problem. | A problem on the SKA side. Contact an administrator. |
| Login failed because of an internal error (reference ...). | Pass the reference to an administrator; it marks the matching lines in the PHP error log. |

The messages that start with "Your password is correct" and the Active Directory account messages are only shown
after the directory has accepted your password. When the directory check refuses a login, the cause is written to
the PHP error log with the username shortened to its first three characters, so administrators can look it up; see
[LDAP and Active Directory](../operations/ldap.md#logs). Refusals for a stale form, a bad username format or too
many attempts are not logged.

### Failed attempts and lockout

After 5 failed logins for the same username, SKA refuses further attempts for that username for 15 minutes,
even with the correct password. Only "Invalid username or password" counts as a failed attempt; directory
outages and account problems do not.

The 15 minutes count from the last failed attempt. The counter is cleared only by a successful login, so once
the 15 minutes have passed you can try again, but another wrong password locks the username again for 15 minutes.

!!! note
    The counter is kept in the browser session, not on the server per account. It slows down guessing in one
    browser; it is not an account lockout. Your directory may apply its own lockout policy on top of it.

### Session timeout and logout

A session ends after a period without activity: 8 hours by default. Every page you load restarts the timer.
Administrators set the length with `session_timeout` in the `[security]` section (0 turns the timeout off), see
[Configuration reference](../reference/configuration.md#session-timeout).

A session can also end sooner: PHP deletes session data that has been idle for longer than its own
`session.gc_maxlifetime`, which is 24 minutes in a stock `php.ini`, unless the administrators have raised it;
see [Security](../operations/security.md#session-files-can-expire-earlier).

Log out with "Logout" in the menu under your name at the top right. If an administrator disables your user, or
the directory sync marks you inactive, you are logged out on your next page load.

## Generating a key pair

You need an SSH key pair: a private key that stays on your computer and a public key that you upload to SKA.
Always protect the private key with a passphrase. The same instructions are in SKA itself, on the home page and
under Help.

### Linux and macOS

```bash
ssh-keygen -t ed25519 -C 'jdoe 2026-11-26'
```

Replace the comment with your own; your username and the date make the key easy to recognise later. Give the
key a passphrase when prompted, then print the public key and copy it:

```bash
cat ~/.ssh/id_ed25519.pub
```

On Debian-based Linux systems without `ssh-keygen`, install it with `sudo apt-get install openssh-client`.
Under Cygwin or MSYS bash on Windows, the same commands work.

### Windows with MobaXterm

1. Install MobaXterm (Home Edition) and start it.
2. Open Tools > MobaKeyGen (SSH Key Generator).
3. Choose EdDSA with the ED25519 variant (preferred). ECDSA with the nistp384 or nistp521 curve, or RSA with
   4096 bits, are also accepted.
4. Click Generate and move the mouse over the blank area until the key is generated.
5. Enter a comment (your username and the date) and a passphrase.
6. Save the private key.
7. Copy the whole text from the box "Public key for pasting into OpenSSH server" at the top of the window. It
   scrolls; make sure you select all of it.

If you connect with a client other than MobaXterm, you may need to export the private key to OpenSSH format
from the Conversions menu.

## Uploading a public key

On the home page, paste the public key into the "Public key" box and click "Add public key". If you already
have keys, click "Add another public key" first; the "Help" button next to the form shows the key generation
instructions.

SKA accepts one key in OpenSSH format: a single line such as `ssh-ed25519 AAAA... comment`. Line breaks
inside the pasted text are removed. Text in other formats (PuTTY's `---- BEGIN SSH2 PUBLIC KEY ----` block, a
private key) is rejected. If the key has no comment, SKA sets one from your username and the current date.

### Accepted key types and minimum sizes

| Key type | Default minimum | Config key |
|---|---|---|
| `ssh-ed25519` | 256 bits (every Ed25519 key) | `min_ed25519_bits` |
| `ecdsa-sha2-nistp256/384/521` | 384 bits, so nistp256 is refused | `min_ecdsa_bits` |
| `ssh-rsa` | 4096 bits | `min_rsa_bits` |

The minimums are set in the `[security]` section, see
[Configuration reference](../reference/configuration.md#key-size-minimums). Other types are not accepted, including
FIDO security keys (`sk-ssh-ed25519@openssh.com`, `sk-ecdsa-sha2-nistp256@openssh.com`). DSA (`ssh-dss`) keys are
checked against `min_rsa_bits`, so they are refused with the default settings.

If the upload fails, SKA shows "Public key upload failed" with one of these reasons:

- "The public key you submitted is of insufficient strength; it has X bits but must be at least Y bits." Generate
  a stronger key, for example Ed25519.
- "The public key you submitted doesn't look valid." The text is not a supported OpenSSH public key.

## The home page

The home page ("Keys management") has these parts.

**Getting started** is all you see while you have no keys: the key generation instructions and the upload
form. The other sections appear once you have uploaded a key.

**Your public keys** lists every key you have uploaded, including deleted ones:

- Type, fingerprint, creation and deletion date, size and comment.
- A pencil icon if the key has signatures, a pin icon if it has destination restrictions.
- "Manage public key" opens the key page; "Delete public key" deletes it.

The Fingerprint column header has a selector for MD5 or SHA256. Your choice is remembered in the browser.

**Your servers** appears only if you are a leader of at least one server; see
[For server leaders](server-leaders.md).

**Your allowed access** lists the server accounts (`account@server`) that access rules grant to you, directly or
through a group you are a member of. Accounts on decommissioned servers are left out.

!!! warning
    This list is a summary, not a guarantee that you can log in. It follows only groups you are a direct member
    of, so access through a group nested in another group is not shown. It does not consider destination
    restrictions on your keys, inactive groups, or whether the server has synced and is configured to read SKA's
    key files. If you cannot log in to an account that is listed, ask an administrator to run
    [Troubleshoot access](troubleshoot-access.md).

## Requesting access to a server account

1. Open Servers in the menu and click the server.
2. Under "Request access to account", enter the account name you need, for example `root` or `deploy`. The box
   suggests the accounts SKA already knows on that server.
3. Click "Request access".

SKA shows "Access requested to deploy@server.example.com." and emails the leaders of that account, or the
server leaders if the account has no leaders of its own (with the server leaders in copy otherwise). Requesting
the same access twice does not create a second request. If you name an account that SKA does not know on that
server yet, SKA adds it as a proposed account for the leaders to review.

A leader approves or rejects the request. Either way you get an email ("Your request for access to ... has been
approved" or "... rejected"). After approval your keys reach the account with the next sync.

The same server page has two more forms:

- **Request server-to-server access**: access for an account on another server (From `account@hostname`) to an
  account on this server. Both server and account must already exist in SKA.
- **Request group access**: access for an SKA group to an account on this server.

The request forms appear only on servers with manual account management, and only if you are neither an SKA
administrator nor a leader of that server. Servers whose accounts come from LDAP show "Access to this server is based on LDAP accounts"
instead; on "manual LDAP" servers, contact the server leaders. You need an uploaded public key for any granted
access to work.

## The key page

Click a fingerprint or "Manage public key" to open the key page at `/pubkeys/<id>`, also reachable as
`/users/<username>/pubkeys/<id>`. Any logged-in user can open the Information tab of any key; it shows:

- The key data (the full OpenSSH line, ready to compare or copy)
- Creation and deletion date, key size
- MD5 and SHA256 fingerprint, each with its randomart

To check that a key in SKA is the one on your computer, compare the SHA256 fingerprint with:

```bash
ssh-keygen -lf ~/.ssh/id_ed25519.pub
```

SKA shows the SHA256 fingerprint without the `SHA256:` prefix. For MD5, use `ssh-keygen -E md5 -lf` and compare
without the `MD5:` prefix.

### JSON and TXT formats

| URL | Content |
|---|---|
| `/pubkeys/<id>.txt` | The key as one OpenSSH line |
| `/pubkeys/<id>.json` | Key data, type, size, both fingerprints (`fingerprint` is the MD5 one), creation and deletion date, owner |
| `/users/<username>/pubkeys.txt` | All keys of a user, one per line; deleted keys are prefixed with `# (deleted) ` |
| `/users/<username>/pubkeys.json` | All keys of a user, without owner details |

The Information tab has a "JSON" button that opens the key's JSON format. These URLs need a logged-in session
like every other page; see [CLI scripts and JSON endpoints](../reference/cli-and-api.md#public-keys).

`/pubkeys/<fingerprint>` finds a key by its MD5 or SHA256 fingerprint (without prefix) and redirects to its page;
if more than one key has that fingerprint, it redirects to the public key list filtered by it. The MD5 form is the
safer one in a URL: a SHA256 fingerprint that contains `/` does not match the URL, and a `+` in it must be written
as `%2B`.

### Destination restrictions

The owner of a key (and administrators) also see a "Destination restrictions" tab while the key is not deleted.
Without rules, the key is
written to every account you have access to. Add a rule with an account name and a hostname, each of which may
contain `*` as a wildcard, and the key is only written to accounts and servers that match at least one rule.
Matching ignores case. Restrictions only narrow your access; they never grant any.

## Deleting a key

Click "Delete public key" on the home page. There is no confirmation step and no way to undo it in the web
interface; to use the key again, upload it again.

A deleted key is not removed from SKA. It stays in your list, greyed out with its deletion date, and its key
page says "This key has been deleted". It is no longer written to any server, and SKA queues a sync for every
account you have access to. Delete a key as soon as you stop using it or suspect the private key is compromised.

## What happens after an upload

- **Email.** You receive "A new SSH public key has been added to your account (...)" with the key's fingerprints,
  randomart and a link to its page. The reporting address configured by the administrators gets a copy. If an
  administrator added the key for you, the email says who did. If you did not add the key, contact the
  address named in the email right away. Deleting a key sends no email. Emails are only sent if the
  administrators have enabled them (`enabled` in the `[email]`
  section, see [Configuration reference](../reference/configuration.md#email)).
- **Audit.** The upload or deletion is recorded as an event on your user.
- **Sync.** SKA queues a sync for every server account you have access to, and for your account on servers that
  use LDAP accounts. The sync daemon then writes new key files to those servers. Until that has run, the change
  has not reached the servers, and a server that cannot be reached keeps its old key file. How keys get there is
  described in [How keys reach servers](../concepts/key-distribution.md).

Your keys are only written to servers while your user is active. If the directory sync marks you inactive, your
keys are left out of all key files.
