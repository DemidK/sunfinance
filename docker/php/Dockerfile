FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev postgresql-dev git unzip \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql opcache zip \
    && apk del --no-cache icu-dev libzip-dev \
    && apk add --no-cache icu-libs libzip libpq

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/app.ini

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-progress --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p var && chown -R www-data:www-data var

CMD ["php-fpm"]
