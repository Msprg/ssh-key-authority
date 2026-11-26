# Install without Docker

!!! warning "Docker is the recommended way to run SKA"
    The Docker image bundles nginx, php-fpm, cron and the sync daemon with the right users, permissions and
    paths already in place. Use [Install with Docker](docker.md) unless you cannot run containers. This page
    describes how to reproduce the same setup by hand on a Debian-like host.

The steps below assume SKA is installed in `/srv/keys`, the path used by the image and by every file shipped
under `etc/` and `services/`. If you install somewhere else, change the paths in those files to match.

## What runs where

A bare-metal installation has the same parts as the container:

| Part | Runs as | Needs |
|---|---|---|
| Web interface (nginx or Apache + php-fpm) | `www-data` | `config/config.ini`, `config/keys-sync.pub`, database, LDAP |
| `scripts/ldap_update.php` (cron) | `www-data` | `config/config.ini`, database, LDAP |
| `scripts/supervise_external_keys.php` (cron) | `keys-sync` | `config/config.ini`, `config/keys-sync`, database, SSH to target servers |
| `scripts/syncd.php` (daemon) and the `scripts/sync.php` processes it starts | `keys-sync` | `config/config.ini`, `config/keys-sync`, database, SSH to target servers |

The web interface never connects to target servers and does not need to read the private sync key. Everything
that opens an SSH connection reads `config/keys-sync` directly, so it must run as a user that can read that
file.

## Requirements

- PHP 8.2 or later, CLI and FPM.
- The PHP extensions listed in `composer.json`: `ldap`, `mbstring`, `mysqli`, `pcntl` and `ssh2`. Composer
  refuses to install without them. The image also installs `gmp` and `bcmath`, which phpseclib (the SSH library
  SKA uses) can use for faster key operations.
- Composer.
- MySQL or MariaDB. The Docker setup uses MariaDB 11.8.
- nginx or Apache.
- `/usr/bin/php` (the scripts use it as their interpreter), `/usr/bin/timeout` (each sync process is started
  under it) and `/usr/bin/flock` (used by the cron jobs).
- The OpenSSH client (`ssh`), if any server is reached through a jump host.
- A working local MTA, if `[email] enabled = 1`. SKA sends mail with PHP's `mail()` function.
- Optionally `git`, to show the version in the page footer (see [Version shown in the footer](#version-shown-in-the-footer)).

On Debian 12 the image installs the packages below, plus `supervisor`, `tini` and `curl`, which only the
container needs (process manager, init and health check):

```bash
apt-get install nginx php-fpm php-cli php-mysql php-ldap php-mbstring php-gmp php-xml php-zip php-curl \
    php-gd php-intl php-bcmath php-ssh2 composer cron ca-certificates openssh-client
```

## Get the code

Clone the repository outside the web root and install the PHP dependencies:

```bash
git clone https://github.com/Msprg/ssh-key-authority.git /srv/keys
cd /srv/keys
composer install --no-dev --prefer-dist --optimize-autoloader
```

Only `public_html/` is served to browsers. `config/`, the scripts and `vendor/` stay outside the document root.

## Users and groups

Create the `keys-sync` system user that runs the sync daemon, and a group that lets both it and the web server
read the configuration file:

```bash
useradd --system --home /var/lib/keys-sync --shell /usr/sbin/nologin keys-sync
mkdir -p /var/lib/keys-sync && chown keys-sync:keys-sync /var/lib/keys-sync
groupadd --system ska
usermod -aG ska www-data
usermod -aG ska keys-sync
```

This local `keys-sync` user is unrelated to the `keys-sync` account on target servers, which SKA logs in to (see
[Prepare target servers](target-servers.md)). The group name `ska` is only a suggestion.

## Database

Create a database and a user for SKA:

```sql
CREATE USER 'ska-user'@'localhost' IDENTIFIED BY 'change-me';
CREATE DATABASE `ska-db` DEFAULT CHARACTER SET utf8mb4;
GRANT ALL ON `ska-db`.* TO 'ska-user'@'localhost';
```

You do not load a schema. Every web request and every script checks the `migration` table when it connects
and applies any migrations that are missing, so the first request against an empty database creates all
tables. The database user therefore needs the right to create and alter tables.

## Configuration file

Copy the example and edit it:

```bash
cp /srv/keys/config/config.ini.example /srv/keys/config/config.ini
```

At a minimum set `[web] baseurl`, `[database]`, `[ldap]` (including `admin_group_cn`) and `[email]`. Each key is
described in the [Configuration reference](../reference/configuration.md), and the LDAP settings in
[LDAP and Active Directory](../operations/ldap.md).

The file contains the database and LDAP bind passwords. Make it readable only by root and the `ska` group:

```bash
chown root:ska /srv/keys/config /srv/keys/config/config.ini
chmod 0750 /srv/keys/config
chmod 0640 /srv/keys/config/config.ini
```

## Sync key pair

SKA logs in to target servers with one key pair, stored as `config/keys-sync` (private) and
`config/keys-sync.pub` (public). Generate it in PEM format:

```bash
ssh-keygen -t rsa -b 4096 -m PEM -N '' -C ska-sync -f /srv/keys/config/keys-sync
chown keys-sync:keys-sync /srv/keys/config/keys-sync
chmod 0600 /srv/keys/config/keys-sync
chown root:ska /srv/keys/config/keys-sync.pub
chmod 0644 /srv/keys/config/keys-sync.pub
```

The private key must have no passphrase, because SKA loads it without one. Only `keys-sync` needs to read it.
The web interface reads the public key to show it on the Help page and the Servers page, so that server
administrators can install it.

`scripts/sync.php` refuses to start if either file is missing.

## Web server

Point the web server at `/srv/keys/public_html` and send every request that is not an existing file to
`init.php`. SKA has its own login page and sessions, so do not put HTTP Basic Auth or an LDAP authentication
module in front of it.

Serve SKA at the root of its own host name (for example `https://ska.example.com/`) and set `[web] baseurl` to
that URL. SKA builds its links from the path in `baseurl`.

### nginx

`etc/nginx/nginx.conf` in the repository is the configuration used in the image. For a host install, a site
file along these lines does the same, with TLS added:

```nginx
server {
    listen 443 ssl;
    server_name ska.example.com;

    ssl_certificate     /etc/ssl/certs/ska.example.com.pem;
    ssl_certificate_key /etc/ssl/private/ska.example.com.key;

    root /srv/keys/public_html;
    index init.php;
    client_max_body_size 16m;

    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;

    location / {
        try_files $uri $uri/ /init.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|svg|ico)$ {
        expires 7d;
        access_log off;
    }
}
```

Adjust the php-fpm socket to your PHP version. The image uses TCP (`127.0.0.1:9000`) instead.

### Apache

Apache needs `mod_proxy_fcgi` to talk to php-fpm (`a2enmod proxy_fcgi setenvif`, or `a2enconf php8.2-fpm` on
Debian). `FallbackResource` comes from `mod_dir`.

```apache
<VirtualHost *:443>
    ServerName ska.example.com
    DocumentRoot /srv/keys/public_html

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/ska.example.com.pem
    SSLCertificateKeyFile /etc/ssl/private/ska.example.com.key

    <Directory /srv/keys/public_html>
        Require all granted
        AllowOverride None
        DirectoryIndex init.php
        FallbackResource /init.php
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
```

### HTTPS behind a proxy

SKA marks the session cookie `Secure` only when PHP sees `HTTPS=on`, and builds redirects back to the current
page with `https` only when `HTTPS` is set. If TLS ends at a load balancer or reverse proxy in front of the web server, pass that variable to
PHP yourself, for example `fastcgi_param HTTPS on;` in the nginx `location ~ \.php$` block.

## php-fpm

The default pool running as `www-data` works. Two settings are worth checking:

- **Session lifetime.** PHP deletes session files idle for longer than `session.gc_maxlifetime` (1440
  seconds by default), which logs users out before `[security] session_timeout` (8 hours by default) is
  reached. Set `session.gc_maxlifetime = 28800` in an ini file that php-fpm loads, for example
  `/etc/php/8.2/fpm/conf.d/99-ska.ini`, not in the php-fpm pool: Debian's session cleanup job does not read
  pool files. The details are in [Security](../operations/security.md#session-files-can-expire-earlier).
- **Restart after LDAP TLS changes.** libldap reads `/etc/ldap/ldap.conf` once per process (see
  [LDAPS certificate trust](#ldaps-certificate-trust)).

The image also sets `clear_env = no` and `catch_workers_output = yes`. SKA does not read environment
variables, so neither is required.

## LDAPS certificate trust

PHP's LDAP extension uses libldap, which needs to be told which CA certificates to trust. Without a trust
anchor every `ldaps://` or StartTLS connection fails with "Can't contact LDAP server". The image ships
`etc/ldap/ldap.conf` for this (see also
[LDAP and Active Directory](../operations/ldap.md#certificate-trust-for-ldaps-and-starttls)):

```ini
TLS_CACERT /etc/ssl/certs/ca-certificates.crt
```

Put the same line in `/etc/ldap/ldap.conf` on the host (`/etc/openldap/ldap.conf` on RHEL-based systems). If
your directory uses an internal CA, point `TLS_CACERT` at a file containing that CA instead, or add the CA to
the system bundle. Restart php-fpm afterwards. The cron jobs start a new PHP process on every run and pick
up the change on their own.

You can test outside PHP with `ldapsearch -H ldaps://ldap.example.com -x -b '' -s base` (package
`ldap-utils`), which uses the same library and file.

## Cron jobs

`etc/cron.d/ska` is the crontab used in the image:

- `scripts/ldap_update.php` every minute: refreshes the users SKA already knows (status, admin flag, group
  memberships) and the names of LDAP-linked groups from LDAP.
- `scripts/supervise_external_keys.php` every 5 minutes: looks for keys on target servers that SKA does not
  manage and refreshes the server facts used by [Troubleshoot access](../guide/troubleshoot-access.md).

`supervise_external_keys.php` connects to every server with `config/keys-sync`. The shipped file runs it as
`www-data`, which only works if `www-data` can read the private key. On a host install, run it as `keys-sync`
instead and keep the private key away from the web server:

```bash
mkdir -p /var/log/ska
touch /var/log/ska/ldap_update.log /var/log/ska/supervise_external_keys.log
chown www-data:www-data /var/log/ska/ldap_update.log
chown keys-sync:keys-sync /var/log/ska/supervise_external_keys.log
```

```bash title="/etc/cron.d/ska"
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

*/1 * * * * www-data  /usr/bin/flock -n /tmp/ldap_update.lock /usr/bin/php /srv/keys/scripts/ldap_update.php >> /var/log/ska/ldap_update.log 2>&1
*/5 * * * * keys-sync /usr/bin/flock -n /tmp/supervise_external_keys.lock /usr/bin/php /srv/keys/scripts/supervise_external_keys.php >> /var/log/ska/supervise_external_keys.log 2>&1
```

If the log file is not writable by the user in the crontab, the shell cannot open it and the job never runs,
without any error in the SKA interface. Rotate these logs with logrotate; SKA does not.

## Sync daemon

`scripts/syncd.php` polls the database for pending sync requests and starts one `scripts/sync.php` process per
server, several in parallel, each limited to 60 seconds by `timeout`. Without the daemon, changes made in the
web interface are queued but never reach the servers.

Set `[general] timeout_util` in `config.ini` to match the `timeout` on the host: `GNU coreutils` on Debian
and most distributions, `BusyBox` on Alpine. Run `timeout --version` to check. See
[Configuration reference](../reference/configuration.md#general).

### systemd

`services/systemd/keys-sync.service` runs the daemon as `keys-sync` with `--systemd`, which logs to the
journal:

```bash
cp /srv/keys/services/systemd/keys-sync.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now keys-sync.service
journalctl -u keys-sync.service -f
```

Before enabling it, check the unit:

- `ExecStart` and `User` must match your install path and user.
- `Requires=mysql.service` and `After=mysql.service` name the local database unit. Change them to your unit's
  name, or remove both if the database runs on another host.
- `ProtectHome=on` hides `/home`. If SKA is installed under `/home`, turn it off.

### sysvinit

`services/init.d/keys-sync` is the original init script. Without `--systemd`, `syncd.php` must be started as
root with `--user <name>`; it then forks, writes `/var/run/keys-sync.pid`, drops to that user and appends its
output to `/var/log/keys/sync.log`. The script as shipped passes no arguments to `syncd.php`, which then exits
with "--user parameter must be provided". It is also not executable in the repository. Install it with mode
0755, change its start line so that it ends in `-- --user $USER`, and create the log directory:

```bash
install -m 0755 /srv/keys/services/init.d/keys-sync /etc/init.d/keys-sync
mkdir -p /var/log/keys && chown keys-sync:keys-sync /var/log/keys
update-rc.d keys-sync defaults
```

Use systemd where you can.

## First start

1. Run the LDAP update once by hand. This creates the database schema and shows whether SKA can reach LDAP:

    ```bash
    sudo -u www-data /usr/bin/php /srv/keys/scripts/ldap_update.php
    ```

2. Open `https://ska.example.com/` and log in as a member of the LDAP group named in `admin_group_cn`. Members
   of that group get admin rights in SKA.
3. Check that the daemon is running (`systemctl status keys-sync.service`).
4. Prepare a first target server as described in [Prepare target servers](target-servers.md), add it in SKA,
   and watch the daemon log for the result of its sync.

To run a sync by hand, run it as `keys-sync`. With `--preview` it only prints the key files it would write,
without connecting to the server; without it, it syncs the server:

```bash
sudo -u keys-sync /usr/bin/php /srv/keys/scripts/sync.php --host server1.example.com --preview
sudo -u keys-sync /usr/bin/php /srv/keys/scripts/sync.php --host server1.example.com
```

See [CLI scripts and JSON endpoints](../reference/cli-and-api.md) for the other options.

## Version shown in the footer

SKA reads the version from a `VERSION` file in the install directory. If there is none, it runs
`git describe` on the checkout, and otherwise shows `dev`. When the checkout is owned by root and PHP runs as
`www-data`, git may refuse to work in the repository, so the footer shows `dev`. Writing the file after each
update avoids this:

```bash
git -C /srv/keys describe --tags --always > /srv/keys/VERSION
```

`VERSION` is listed in `.gitignore`.

## Updating

```bash
cd /srv/keys
git pull
composer install --no-dev --prefer-dist --optimize-autoloader
git describe --tags --always > VERSION
systemctl restart keys-sync.service
```

Database migrations are applied automatically by the next request or script. Restart the sync daemon after
every update, because it keeps the code it was started with. Compare `config/config.ini.example` with your
`config.ini` for new settings. Migrations only go forward, so take a backup before updating.

## Backups

Back up the database (for example with `mysqldump --single-transaction ska-db`) and the `config/`
directory, which holds `config.ini` and the sync key pair. Without the private key you would have to install
a new public key on every target server. Keep both backups as protected as the originals; see
[Security](../operations/security.md#the-sync-key).

## Where to go next

- [Prepare target servers](target-servers.md)
- [Security](../operations/security.md)
- [Monitoring and logs](../operations/monitoring.md)
