FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install pdo_mysql intl bcmath pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN docker-php-ext-install opcache
COPY docker/laravel/php.ini /usr/local/etc/php/conf.d/beautybook.ini

WORKDIR /var/www/html

# Keep dependencies on Docker's Linux filesystem, away from the Windows bind mount.
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-scripts \
    && sha256sum composer.lock | cut -d ' ' -f 1 > vendor/.beautybook-lock
COPY docker/laravel/entrypoint.sh /usr/local/bin/beautybook-entrypoint
RUN chmod +x /usr/local/bin/beautybook-entrypoint

ENTRYPOINT ["beautybook-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
