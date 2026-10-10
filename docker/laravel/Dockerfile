FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install pdo_mysql intl bcmath pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN docker-php-ext-install opcache
COPY docker/laravel/php.ini /usr/local/etc/php/conf.d/beautybook.ini

WORKDIR /var/www/html

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
