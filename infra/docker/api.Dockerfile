# Platform API (Laravel) — php-fpm, non-root, production dependencies only.
FROM composer:2.8 AS vendor
WORKDIR /app
COPY apps/platform-api/composer.json apps/platform-api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# Published-site runtime bundle (served from public/runtime; its manifest is read by RuntimeAssets).
FROM node:22-alpine AS runtime
WORKDIR /src
COPY package.json package-lock.json ./
COPY packages packages
COPY apps/runtime-web apps/runtime-web
COPY apps/studio-web/package.json apps/studio-web/package.json
COPY tests/e2e/package.json tests/e2e/package.json
RUN mkdir -p apps/platform-api/public && npm ci --no-audit --no-fund && npm run build -w apps/runtime-web

FROM php:8.3-fpm-alpine
RUN apk add --no-cache icu-libs libzip libpng libjpeg-turbo libwebp freetype \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd intl zip opcache pcntl \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build
COPY infra/docker/php.ini /usr/local/etc/php/conf.d/zz-studio.ini
WORKDIR /var/www
# Shared JSON Schemas, component registry and templates are read at runtime.
COPY packages/app-schema/schema packages/app-schema/schema
COPY packages/component-registry/registry.json packages/component-registry/registry.json
COPY packages/templates/dist packages/templates/dist
COPY apps/platform-api apps/platform-api
COPY --from=vendor /app/vendor apps/platform-api/vendor
COPY --from=runtime /src/apps/platform-api/public/runtime apps/platform-api/public/runtime
WORKDIR /var/www/apps/platform-api
RUN rm -f .env && mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/tenant-assets bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
ENV STUDIO_PACKAGES_PATH=/var/www/packages
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]
