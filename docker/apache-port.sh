#!/bin/sh
# Binds Apache to the platform-assigned $PORT (Railway, Render, Fly.io, etc.
# all inject this env var and expect the container to listen on it) instead
# of the image's built-in default of port 80.
set -e
: "${PORT:=8080}"

# Keep the runtime configuration deterministic if a platform layer or package
# update enables another MPM after the image has been built.
a2dismod -f mpm_event mpm_worker >/dev/null
a2enmod mpm_prefork >/dev/null

sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
