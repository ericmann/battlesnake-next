# syntax=docker/dockerfile:1.6

# ----- Stage 1: composer install (no dev) ------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

# ----- Stage 2: runtime (php-fpm + nginx in one container) -------------------
FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache \
        nginx \
        ca-certificates \
        curl \
        tini \
    && update-ca-certificates

# php-fpm tuning: small, predictable, plenty of headroom for ≤8 concurrent games.
RUN { \
        echo '[www]'; \
        echo 'listen = /run/php-fpm.sock'; \
        echo 'listen.owner = nobody'; \
        echo 'listen.group = nobody'; \
        echo 'listen.mode = 0660'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 16'; \
        echo 'pm.start_servers = 4'; \
        echo 'pm.min_spare_servers = 2'; \
        echo 'pm.max_spare_servers = 6'; \
        echo 'clear_env = no'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
        echo 'request_terminate_timeout = 5s'; \
    } > /usr/local/etc/php-fpm.d/zz-snake.conf \
    && rm -f /usr/local/etc/php-fpm.d/www.conf.default

# Tighter PHP defaults for a low-latency request loop.
RUN { \
        echo 'memory_limit = 128M'; \
        echo 'expose_php = Off'; \
        echo 'max_execution_time = 5'; \
        echo 'realpath_cache_size = 4M'; \
        echo 'realpath_cache_ttl = 600'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.enable_cli = 0'; \
        echo 'opcache.memory_consumption = 64'; \
        echo 'opcache.max_accelerated_files = 4000'; \
        echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/zz-snake.ini \
    && docker-php-ext-install opcache

# Strip default nginx site, drop ours in.
COPY nginx/default.conf /etc/nginx/http.d/default.conf
RUN sed -i 's|user nginx;|user nobody;|' /etc/nginx/nginx.conf \
    && mkdir -p /run/nginx /var/log/nginx \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

WORKDIR /var/www
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY public ./public

# Entrypoint runs both nginx and php-fpm under tini so signals reach both.
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && chown -R nobody:nobody /var/www

EXPOSE 9000

HEALTHCHECK --interval=15s --timeout=3s --start-period=5s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1:9000/ || exit 1

ENTRYPOINT ["/sbin/tini", "--", "/entrypoint.sh"]
