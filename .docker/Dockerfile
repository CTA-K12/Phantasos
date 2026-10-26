# Phantasos images — one Dockerfile, two targets:
#
#   app  PHP-FPM (default CMD) and, with a different command, the Messenger
#        worker: `php bin/console messenger:consume async ...`. Includes ffmpeg.
#   web  nginx: TLS, static assets, and file delivery via X-Accel-Redirect.
#
# Build (from the repo root):
#   docker build --platform linux/amd64 -f .docker/Dockerfile --target app -t <registry>/phantasos-app:<tag> .
#   docker build --platform linux/amd64 -f .docker/Dockerfile --target web -t <registry>/phantasos-web:<tag> .
#
# No secrets are baked in: .env in the image is .docker/php/env.defaults; the
# deployment supplies APP_SECRET, DATABASE_URL etc. as environment variables.

# ── Composer (official image: TLS-verified pull, pinned major) ────────────────
FROM composer:2 AS composer

# ── PHP base ─────────────────────────────────────────────────────────────────
FROM alpine:3 AS php-base

RUN apk add --no-cache \
        php84 php84-fpm php84-ctype php84-curl php84-dom php84-fileinfo php84-gd \
        php84-iconv php84-intl php84-mbstring php84-opcache php84-openssl \
        php84-pdo php84-pdo_pgsql php84-phar php84-session php84-simplexml \
        php84-tokenizer php84-xml php84-xmlwriter php84-zip php84-sodium \
        ffmpeg ca-certificates tzdata \
 && ln -sf /usr/bin/php84 /usr/bin/php \
 && rm -f /etc/php84/php-fpm.d/www.conf \
 && sed -i 's|^;\?error_log = .*|error_log = /proc/self/fd/2|' /etc/php84/php-fpm.conf \
 && php -v && ffmpeg -version | head -1

COPY .docker/php/php.d/phantasos.ini /etc/php84/conf.d/90-phantasos.ini
COPY .docker/php/php-fpm.d/phantasos.pool.conf /etc/php84/php-fpm.d/

# Load-bearing default; see the pool file.
ENV FPM_MAX_CHILDREN=8

WORKDIR /var/www/phantasos

# ── Build: dependencies, compiled assets, warmed cache ───────────────────────
FROM php-base AS build

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
COPY .docker/php/env.defaults .env
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && APP_SECRET=build-only DATABASE_URL='postgresql://x:x@localhost:5432/x?serverVersion=17' \
    GOOGLE_CLIENT_SECRET=build-only \
    sh -c 'php bin/console importmap:install && php bin/console asset-map:compile && php bin/console cache:warmup' \
 && rm -rf var/log/*

# ── app: PHP-FPM / worker ────────────────────────────────────────────────────
FROM php-base AS app

COPY --from=build --chown=nobody:nobody /var/www/phantasos /var/www/phantasos
USER nobody
EXPOSE 9001
CMD ["php-fpm84", "-F"]

# ── web: nginx ───────────────────────────────────────────────────────────────
FROM alpine:3 AS web

RUN apk add --no-cache nginx \
 && mkdir -p /run/nginx /var/lib/nginx/tmp \
 && chown -R nginx:nginx /run/nginx /var/lib/nginx /var/log/nginx \
 && ln -sf /dev/stdout /var/log/nginx/access.log \
 && ln -sf /dev/stderr /var/log/nginx/error.log

COPY .docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY .docker/nginx/phantasos.conf /etc/nginx/http.d/phantasos.conf
COPY --from=build /var/www/phantasos/public /var/www/phantasos/public

USER nginx
EXPOSE 8080 8443
CMD ["nginx", "-g", "daemon off;"]
