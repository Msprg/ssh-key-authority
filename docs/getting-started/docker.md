# Install with Docker

Docker is the recommended way to run SKA. The image holds the whole application in one container: the web
interface, the cron jobs and the sync daemon. The database runs in a second container.

## The image

The image is published on Docker Hub as `msprg/ssh-key-authority` for `amd64` and `arm64`.

| Tag | Contents |
|---|---|
| `<version>`, for example `1.6.0` | A published release. The tag is the name of the GitHub release. |
| `latest` | The most recent published release. |
| `latest-master` | The current tip of the `master` branch. |
| `latest-dev` | The current tip of the `dev` branch. |

Each of these also exists with an `-amd64` or `-arm64` suffix for a single architecture. Branch tags are
rebuilt on every push to the branch, except pushes that only change documentation. For production, pin a
release tag so an upgrade only happens when you change the tag.

The version is baked into the image at build time and shown in the page footer (`%v` in `[web] footer`).
Release images show the tag, branch images show the output of `git describe`, for example
`1.6.0-3-gabc1234`.

## What you need

- A host with Docker and Docker Compose.
- An LDAP or Active Directory server the container can reach, and an LDAP group whose members will be SKA
  administrators. See [LDAP and Active Directory](../operations/ldap.md).
- Outbound SSH from the container to the servers SKA will manage.
- A reverse proxy that terminates TLS, if users should reach SKA over HTTPS. The container itself only
  serves plain HTTP.

## Directory layout

The example compose file keeps everything under `./data/ska/` next to `docker-compose.yml`:

```text
docker-compose.yml
data/ska/
  config/                    mounted at /srv/keys/config
    config.ini
    keys-sync                private key SKA uses to log in to servers
    keys-sync.pub            its public key
  logs/                      mounted at /var/log/ska
  var/local/sync/            mounted at /var/local/keys-sync
  db-data/                   MariaDB data directory
```

## Step 1: compose file

Copy `docker-compose.yml.example` from the repository to `docker-compose.yml`. It defines two services.

**`ska-app`** is SKA itself.

- `build:` builds the image from the checkout. To use the published image instead, remove the `build:`
  section and set the tag you want, for example `image: msprg/ssh-key-authority:1.6.0`.
- `ports: "80:8080"` publishes the container's web server on port 80 of the host. Put your reverse proxy
  in front of it, or change the host side of the mapping.
- `depends_on` with `condition: service_healthy` starts SKA only once the database answers.
- Volumes:
    - `./data/ska/config/` to `/srv/keys/config/`, read-only (`:ro`). This holds `config.ini` and the sync
      key pair.
    - `./data/ska/logs` to `/var/log/ska`, for the logs of the cron jobs.
    - `./data/ska/var/local/sync` to `/var/local/keys-sync`.
- The health check fetches `http://localhost:8080/` every 30 seconds and fails on an HTTP error status. A
  redirect to the login page counts as healthy. The Dockerfile defines the same check, so it also applies
  when you run the image without Compose.

**`ska-db`** is MariaDB (`mariadb:11.8.5` in the example).

- Fill in `MARIADB_ROOT_PASSWORD` and `MARIADB_PASSWORD`. The example leaves both empty.
- `MARIADB_DATABASE` (`ska-db`) and `MARIADB_USER` (`ska-user`) create the database and the account SKA
  uses. They must match `[database]` in `config.ini`.
- Data lives in `./data/ska/db-data`.
- The health check runs `mariadb-admin ping` with the SKA account every 10 seconds.

## Step 2: config.ini

Create `data/ska/config/config.ini` from `config/config.ini.example` in the repository. At a minimum set:

- `[web] baseurl`: the address users open, for example `https://ska.example.com`.
- `[database]`: `hostname = ska-db` (the service name), `port = 3306`, and the user, password and database
  name from the compose file.
- `[ldap]`: server, base DNs, attribute mapping and `admin_group_cn`.
- `[email]`: sender and recipient addresses.

Leave `[general] timeout_util = GNU coreutils` as it is: the image is based on Debian. Every setting is
described in the [Configuration reference](../reference/configuration.md).

For LDAPS and StartTLS, the image ships `/etc/ldap/ldap.conf` with
`TLS_CACERT /etc/ssl/certs/ca-certificates.crt`, so certificates from public CAs are trusted. If your
directory uses a private CA, mount a CA file into the container and your own `ldap.conf` over
`/etc/ldap/ldap.conf` that points `TLS_CACERT` to it.

## Step 3: sync key pair

SKA logs in to every managed server as `keys-sync` with one key pair. Generate it without a passphrase
(SKA loads the private key without one) and in PEM format:

```bash
ssh-keygen -t rsa -b 4096 -m PEM -N '' -C ska-sync -f data/ska/config/keys-sync
```

This writes `keys-sync` and `keys-sync.pub`. The public key goes onto every target server, see
[Prepare target servers](target-servers.md). It is also shown on the Help page of SKA once it runs.

### Who reads the private key

Inside the container two users read `config/keys-sync`:

- `keys-sync` runs the sync daemon, which writes the key files to the servers.
- `www-data` runs the cron job `supervise_external_keys.php`, which looks for keys that SKA does not manage
  and refreshes the server facts used by [Troubleshoot access](../guide/troubleshoot-access.md).

With a plain bind mount, the file keeps its numeric owner and mode from the host. The `keys-sync` user is
created in the image with `useradd --system` and has no fixed UID. To see the UID in your image:

```bash
docker compose run --rm --no-deps --entrypoint id ska-app keys-sync
```

### The `*.host` option

Instead of matching UIDs on the host, you can name the files `keys-sync.host` and `keys-sync.pub.host`.
At every start, `scripts/docker-entrypoint.sh` copies them to `config/keys-sync` (owner `keys-sync`, mode
600) and `config/keys-sync.pub` (owner `keys-sync`, mode 644). If the `.host` files are absent, this step
does nothing.

This has two limits:

- The entrypoint writes into `config/`, so that mount must not be read-only. Remove `:ro` from the config
  volume. With `:ro` the copy fails and the container exits at start.
- The private key copy is readable only by `keys-sync`. The `www-data` cron job cannot read it, so the
  search for unmanaged keys fails on every server and records an error there. Key distribution itself is
  not affected.

!!! warning
    Keep the private key out of backups and places that other people can read. Anyone who has it can log in
    as `keys-sync` on every managed server and change who has access.

## Step 4: first start

```bash
docker compose up -d
docker compose ps
docker compose logs -f ska-app
```

The database schema is created by the first SKA process that connects to the database (a web request, a
cron job or the sync daemon). No separate setup command exists.

Open `http://ska.example.com/` (or the address of your reverse proxy) and sign in with an LDAP account.

## First admin login

There is no built-in admin account. A user is an administrator when the LDAP search for their groups
under `[ldap] dn_group` returns a group whose `cn` equals `[ldap] admin_group_cn` exactly. With
`indirect_group_memberships = 1`, membership through nested groups counts as well (this uses an Active
Directory matching rule).

The admin flag is read from LDAP when the user logs in and on every run of `ldap_update.php` (every
minute). The same job creates the admin group in SKA if it does not exist yet. If you are not an
administrator after logging in, check the group name and membership first, then
`data/ska/logs/ldap_update.log`.

## What runs in the container

`tini` is PID 1. It starts the entrypoint, which:

1. creates `/var/log/ska` and its two log files and gives them to `www-data`,
2. creates `/var/local/keys-sync` and gives it to `keys-sync`,
3. installs the `*.host` key files if present,
4. starts `supervisord` in the foreground.

Ownership is fixed on every start because bind mounts replace the directories prepared in the image,
including their owner. Without this, cron jobs fail silently on a host directory owned by root.

`supervisord` runs four programs and restarts them if they exit:

| Program | Runs as | Purpose |
|---|---|---|
| `php-fpm` | `www-data` workers | PHP for the web interface, listening on port 9000 |
| `nginx` | root (master process) | Web server on port 8080 (IPv4 and IPv6), document root `/srv/keys/public_html` |
| `cron` | root, jobs as `www-data` | Periodic jobs from `/etc/cron.d/ska` |
| `keys-syncd` | `keys-sync` | `scripts/syncd.php`: picks up sync requests and runs `sync.php` per server |

The cron jobs, each guarded by `flock` so runs never overlap:

| Schedule | Script | Log |
|---|---|---|
| every minute | `scripts/ldap_update.php` | `/var/log/ska/ldap_update.log` |
| every 5 minutes | `scripts/supervise_external_keys.php` | `/var/log/ska/supervise_external_keys.log` |

php-fpm listens on port 9000 on all addresses of the container, not only on `127.0.0.1`. The port is not
published on the host, but other containers on the same Docker network can reach it.

The sync daemon checks for pending sync requests every second. Requests are created when a change affects
access, in the web interface or when `ldap_update.php` applies a change from LDAP (for example a user who
was deactivated or changed groups). There is no scheduled full sync in the container. How a sync works is described in
[How keys reach servers](../concepts/key-distribution.md).

## Logs

- `docker compose logs ska-app`: output of nginx (access and error log), supervisord and the sync daemon,
  including the output of every sync.
- php-fpm writes its own log to `/var/log/php8.2-fpm.log` inside the container (the Debian default, which
  the image does not change). It is not a volume.
- `data/ska/logs/ldap_update.log` and `data/ska/logs/supervise_external_keys.log`: the cron jobs. Nothing
  in the image rotates these files; rotate them on the host.
- The audit trail of changes is stored in the database and shown in the web interface.

See [Monitoring and logs](../operations/monitoring.md) for what to watch.

## Running scripts by hand

The scripts live under `/srv/keys/scripts`. Run sync tasks as `keys-sync`, so they use the same key and
permissions as the daemon. For example, to show what would be written to one server without changing it:

```bash
docker compose exec --user keys-sync ska-app php /srv/keys/scripts/sync.php --host server1.example.com --preview
```

All scripts are described in [CLI scripts and JSON endpoints](../reference/cli-and-api.md).

## HTTPS

The container serves plain HTTP on port 8080. Terminate TLS in a reverse proxy in front of it and set
`[web] baseurl` to the `https://` address.

!!! note
    SKA sets the `Secure` flag on its session cookie only when PHP sees the request as HTTPS. Requests
    reach PHP through the container's own nginx over plain HTTP, so behind a proxy the flag is not set.
    The cookie is still `HttpOnly` and `SameSite=Strict`. See [Security](../operations/security.md).

PHP sessions are stored in `/var/lib/php/sessions` inside the container, which is not a volume. Users have
to log in again after the container is recreated.

## Building the image locally

The Dockerfile takes the version shown in the footer from the `SKA_VERSION` build argument. Without it,
the footer says `dev`.

```bash
SKA_VERSION=$(git describe --tags --always) docker compose up -d --build
```

or without Compose:

```bash
docker build --build-arg SKA_VERSION=$(git describe --tags --always) -t ska:local .
```

The build writes the version to `/srv/keys/VERSION` and removes `.git` from the image.

## Upgrading

1. Back up the database and the config directory (see below). Migrations only go forward, so the backup is
   your way back.
2. Change the image tag in `docker-compose.yml` (or pull `latest` again), then:

    ```bash
    docker compose pull ska-app
    docker compose up -d
    ```

3. Watch `docker compose logs ska-app` and log in once.

Database migrations apply automatically. Each SKA process compares the highest applied migration in the
`migration` table with the number the code expects (`model/migrationdirectory.php`) and applies the missing
ones from `migrations/` in order.

Read the release notes before upgrading for any new settings in `config/config.ini.example`. Your
`config.ini` is not changed by an upgrade.

## Backups

Back up two things:

- **The database.** For example, from the host:

    ```bash
    docker compose exec -T ska-db sh -c 'exec mariadb-dump --single-transaction -uroot -p"$MARIADB_ROOT_PASSWORD" ska-db' > ska-db.sql
    ```

- **`data/ska/config/`**: `config.ini` and the sync key pair. Without the private key you would have to
  install a new public key on every target server.

`data/ska/logs/` is optional. `data/ska/db-data/` is the live MariaDB data directory; copy it only while
the database is stopped, or rely on the dump.

To restore, start `ska-db` with an empty data directory, load the dump with `mariadb`, put the config
directory back and start `ska-app`.
