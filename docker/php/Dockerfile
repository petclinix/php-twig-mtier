FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        git \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json ./
RUN composer install --no-interaction --no-progress

COPY . .

CMD ["php-fpm"]
