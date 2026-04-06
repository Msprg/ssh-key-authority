# syntax=docker/dockerfile:1.6

FROM debian:12-slim

ARG DEBIAN_FRONTEND=noninteractive
ENV APP_DIR=/srv/keys     COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx php-fpm php-cli php-mysql php-ldap php-mbstring php-gmp php-xml php-zip php-curl php-gd php-intl php-bcmath php-ssh2 \
    composer cron supervisor tini ca-certificates curl openssh-client \
    && rm -rf /var/lib/apt/lists/*

RUN useradd --system --home /var/lib/keys-sync --shell /usr/sbin/nologin keys-sync && \
    mkdir -p /var/lib/keys-sync /var/log/ska /var/local/keys-sync /var/log/supervisor && \
    chown -R keys-sync:keys-sync /var/lib/keys-sync /var/local/keys-sync && \
    chown -R www-data:www-data /var/log/ska && \
    touch /var/log/ska/ldap_update.log /var/log/ska/supervise_external_keys.log && \
    chown www-data:www-data /var/log/ska/ldap_update.log /var/log/ska/supervise_external_keys.log

WORKDIR ${APP_DIR}
COPY . ${APP_DIR}

RUN cp -r ${APP_DIR}/etc / && rm -rf ${APP_DIR}/etc

RUN composer install --no-dev --prefer-dist --optimize-autoloader

RUN set -eux; \
    PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"; \
    FPM_DIR="/etc/php/${PHP_VERSION}/fpm"; \
    sed -ri 's|^listen = .+|listen = 9000|' "${FPM_DIR}/pool.d/www.conf"; \
    sed -ri 's|^;*daemonize = yes|daemonize = no|' "${FPM_DIR}/php-fpm.conf"; \
    sed -ri 's|^;*clear_env = .+|clear_env = no|' "${FPM_DIR}/pool.d/www.conf"; \
    sed -ri 's|^;*catch_workers_output = .*|catch_workers_output = yes|' "${FPM_DIR}/pool.d/www.conf"; \
    mkdir -p /run/php /var/lib/php/sessions; \
    ln -sf "/usr/sbin/php-fpm${PHP_VERSION}" /usr/sbin/php-fpm; \
    chown www-data:www-data /run/php /var/lib/php/sessions; \
    chmod 1733 /var/lib/php/sessions

RUN chmod 0644 /etc/cron.d/ska

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl -fsS http://127.0.0.1:8080/ || exit 1

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
