# syntax=docker/dockerfile:1.6

FROM debian:12-slim

ARG DEBIAN_FRONTEND=noninteractive
ENV APP_DIR=/srv/keys     COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends     nginx     php-fpm     php-cli     php-mysql     php-ldap     php-mbstring     php-gmp     php-xml     php-zip     php-curl     php-gd     php-intl     php-bcmath     composer     cron     supervisor     tini     ca-certificates     curl     git     openssh-client     unzip     && rm -rf /var/lib/apt/lists/*

RUN useradd --system --home /var/lib/keys-sync --shell /usr/sbin/nologin keys-sync && \
    mkdir -p /var/lib/keys-sync /var/log/ska /var/local/keys-sync /var/log/supervisor && \
    chown -R keys-sync:keys-sync /var/lib/keys-sync /var/local/keys-sync && \
    chown -R www-data:www-data /var/log/ska && \
    touch /var/log/ska/ldap_update.log /var/log/ska/supervise_external_keys.log && \
    chown www-data:www-data /var/log/ska/ldap_update.log /var/log/ska/supervise_external_keys.log

WORKDIR ${APP_DIR}
COPY . ${APP_DIR}

RUN composer install --no-dev --prefer-dist --optimize-autoloader

RUN set -eux;     PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')";     FPM_DIR="/etc/php/${PHP_VERSION}/fpm";     sed -ri 's|^listen = .+|listen = 9000|' "${FPM_DIR}/pool.d/www.conf";     sed -ri 's|^;*daemonize = yes|daemonize = no|' "${FPM_DIR}/php-fpm.conf";     sed -ri 's|^;*clear_env = .+|clear_env = no|' "${FPM_DIR}/pool.d/www.conf";     sed -ri 's|^;*catch_workers_output = .*|catch_workers_output = yes|' "${FPM_DIR}/pool.d/www.conf";     mkdir -p /run/php;     ln -sf "/usr/sbin/php-fpm${PHP_VERSION}" /usr/sbin/php-fpm;     chown www-data:www-data /run/php

RUN set -eux;     cat <<'NGINX-EOF' >/etc/nginx/nginx.conf;     printf '\n' >>/etc/nginx/nginx.conf
worker_processes auto;

error_log /dev/stderr info;

pid /run/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include       /etc/nginx/mime.types;
    default_type  application/octet-stream;

    sendfile        on;
    keepalive_timeout 65;
    server_tokens off;
    client_max_body_size 16m;

    access_log /dev/stdout;
    error_log  /dev/stderr info;

    include /etc/nginx/conf.d/*.conf;

    server {
        listen 8080 default_server;
        listen [::]:8080 default_server;

        root /srv/keys/public_html;
        index init.php;

        add_header X-Content-Type-Options nosniff;
        add_header X-Frame-Options DENY;
        add_header X-XSS-Protection "1; mode=block";

        location / {
            try_files $uri $uri/ /init.php?$query_string;
        }

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_pass 127.0.0.1:9000;
        }

        location ~* \.(css|js|png|jpg|jpeg|gif|svg|ico)$ {
            expires 7d;
            access_log off;
        }
    }
}
NGINX-EOF

RUN set -eux;     cat <<'CRON-EOF' >/etc/cron.d/ska;     printf '\n' >>/etc/cron.d/ska;     chmod 0644 /etc/cron.d/ska
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

*/1 * * * * www-data /usr/bin/flock -n /tmp/ldap_update.lock /usr/bin/php /srv/keys/scripts/ldap_update.php >> /var/log/ska/ldap_update.log 2>&1
*/5 * * * * www-data /usr/bin/flock -n /tmp/supervise_external_keys.lock /usr/bin/php /srv/keys/scripts/supervise_external_keys.php >> /var/log/ska/supervise_external_keys.log 2>&1
CRON-EOF

RUN set -eux;     cat <<'SUPERVISOR-EOF' >/etc/supervisor/conf.d/ska.conf
[program:php-fpm]
command=/usr/sbin/php-fpm -F
stopsignal=QUIT
autostart=true
autorestart=true
startsecs=5
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
priority=10

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
stopsignal=QUIT
autostart=true
autorestart=true
startsecs=5
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
priority=20

[program:cron]
command=/usr/sbin/cron -f
autostart=true
autorestart=true
startsecs=5
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
priority=30

[program:keys-syncd]
command=/usr/bin/php /srv/keys/scripts/syncd.php --systemd
directory=/srv/keys
user=keys-sync
# The 'startsecs' option tells Supervisor how many seconds the program must stay running after it is started to be considered successfully started.
# If the process exits before this time, Supervisor will consider it a failure and may attempt to restart it.
startsecs=5
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
priority=40
SUPERVISOR-EOF

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl -fsS http://127.0.0.1:8080/ || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
