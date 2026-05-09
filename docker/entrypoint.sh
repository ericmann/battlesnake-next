#!/bin/sh
set -eu

# Start php-fpm in the background, then nginx in the foreground.
# When nginx exits, kill php-fpm so the container fully stops.
php-fpm --nodaemonize &
PHP_FPM_PID=$!

trap 'kill -TERM "$PHP_FPM_PID" 2>/dev/null || true' TERM INT

# Block until php-fpm has the socket open (~50ms in practice).
i=0
while [ ! -S /run/php-fpm.sock ] && [ "$i" -lt 50 ]; do
    sleep 0.05
    i=$((i + 1))
done

nginx -g 'daemon off;'
