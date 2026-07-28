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

# Hand the platform's runtime environment variables to PHP explicitly. Railway (and
# friends) inject these into the container process; PassEnv copies them into the
# request environment so chat-handler.php sees them in both $_SERVER and getenv()
# regardless of how the SAPI is built. Only emit lines for variables that are
# actually set — PassEnv on an undefined name logs a startup warning.
: > /etc/apache2/conf-enabled/zz-env.conf
for name in OPENROUTER_API_KEY OPENROUTER_MODEL OPENROUTER_PAID_MODEL CHAT_RATE_LIMIT CHAT_RATE_WINDOW; do
    eval "value=\${${name}:-}"
    if [ -n "$value" ]; then
        echo "PassEnv ${name}" >> /etc/apache2/conf-enabled/zz-env.conf
    else
        echo "apache-port: ${name} is not set in the container environment" >&2
    fi
done

exec apache2-foreground
