# Lomagundi & Forestal Pharmacies — website container image.
# Serves the static site (HTML/CSS/JS) plus contact-handler.php via Apache + PHP.
# Built to run identically with `docker compose up`, plain `docker run`, or a
# Railway Dockerfile-based deploy (see ../railway.json).

FROM php:8.3-apache

# Pull in the latest Debian security patches on top of the base image layer
# (the upstream tag is a point-in-time snapshot, so this closes the gap for
# any OS package CVEs fixed since it was last published).
RUN apt-get update \
    && apt-get upgrade -y \
    && rm -rf /var/lib/apt/lists/*

# mod_headers powers the security headers below.
RUN a2enmod headers

# Sane, production-leaning PHP defaults for a small brochure/contact-form site.
RUN { \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'upload_max_filesize = 2M'; \
        echo 'post_max_size = 2M'; \
    } > /usr/local/etc/php/conf.d/site.ini

# Security headers + directory-listing lockdown (see docker/security.conf),
# and the entrypoint that binds Apache to the platform's dynamic $PORT.
COPY docker/security.conf /etc/apache2/conf-enabled/zz-security.conf
COPY docker/apache-port.sh /usr/local/bin/apache-port.sh
RUN sed -i 's/\r$//' /usr/local/bin/apache-port.sh \
    && chmod +x /usr/local/bin/apache-port.sh

WORKDIR /var/www/html
COPY . /var/www/html/

# Only the actual website should ever be web-accessible — strip deployment
# and tooling files that came along with the build context.
RUN rm -rf \
        /var/www/html/docker \
        /var/www/html/Dockerfile \
        /var/www/html/docker-compose.yml \
        /var/www/html/Makefile \
        /var/www/html/railway.json \
        /var/www/html/.dockerignore \
        /var/www/html/README.md

ENV PORT=8080
EXPOSE 8080

CMD ["/usr/local/bin/apache-port.sh"]
