# syntax=docker/dockerfile:1

FROM php:8.3-fpm-alpine AS runtime

# Runtime libraries plus the headers needed to compile the PHP extensions
# required by composer.json and Dompdf.
RUN apk add --no-cache \
        curl \
        fcgi \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libxml2 \
        libzip \
        oniguruma \
        su-exec \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        dom \
        gd \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        xml \
        zip \
    && printf '\n\n\n\n' | pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1

# Install dependencies first so source changes do not invalidate this layer.
# A composer.lock is deliberately optional: commit one for reproducible builds.
COPY composer.json ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --no-scripts \
        --no-autoloader \
        --no-progress

COPY . .

RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && mkdir -p \
        bootstrap/cache \
        storage/app/backups \
        storage/app/documents \
        storage/app/private \
        storage/app/public \
        storage/fonts \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-chama.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-chama-opcache.ini
COPY docker/entrypoint.sh /usr/local/bin/chama-entrypoint
RUN chmod +x /usr/local/bin/chama-entrypoint

EXPOSE 9000

ENTRYPOINT ["chama-entrypoint"]
CMD ["php-fpm"]

# The web tier carries a copy of public/ so the container can run without a
# bind mount from the host checkout. Rebuild both images on every release.
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public

EXPOSE 80

# ---------------------------------------------------------------------------
# PaaS target — Render / Railway / Fly.io
#
# This is deliberately the LAST stage: those platforms build the whole
# Dockerfile with no --target flag, so the final stage must be runnable on its
# own. docker-compose keeps using the explicit `runtime` and `web` targets.
#
# PHP-FPM and nginx share one container. nginx listens on $PORT (injected by
# the platform) and FastCGI-proxies to local FPM on 9000. Any other command
# (e.g. `php artisan queue:work`) bypasses the web stack, so the same image
# also serves as the worker/scheduler image.
# ---------------------------------------------------------------------------
FROM runtime AS paas

# Managed Postgres is the database offered by Render and Fly.io; Railway can
# use it too. nginx + gettext render the $PORT-aware server block at boot.
RUN apk add --no-cache nginx gettext postgresql-libs \
    && apk add --no-cache --virtual .paas-build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .paas-build-deps \
    && rm -f /etc/nginx/http.d/default.conf

COPY docker/nginx/paas.conf.template /etc/nginx/http.d/default.conf.template
COPY docker/paas-entrypoint.sh /usr/local/bin/chama-paas-entrypoint
RUN chmod +x /usr/local/bin/chama-paas-entrypoint

ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["chama-paas-entrypoint"]
CMD ["web"]
