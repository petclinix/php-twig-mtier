FROM php:8.3-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        git \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json ./

# ---------------------------------------------------------------------------
# testing: full dependency tree (incl. require-dev / PHPUnit) plus test
# sources. This is what docker-compose builds for local dev — it's also the
# container `vendor/bin/phpunit` runs in.
# ---------------------------------------------------------------------------
FROM base AS testing

# Coverage driver for `vendor/bin/phpunit --coverage-*` — testing stage
# only, never prod. PCOV over Xdebug: purpose-built for coverage
# collection, far lower overhead, no debugging need here. $PHPIZE_DEPS
# (build toolchain) is needed to compile it via pecl and isn't in the
# base php:8.3-fpm image; purge it afterwards, same hygiene as the base
# stage's own apt-get cleanup.
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

RUN composer install --no-interaction --no-progress

COPY . .

CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# prod: --no-dev only, and only the runtime source directories — no tests/,
# no phpunit.xml, no dev tooling. Last stage, so it's the default `docker
# build` target: forgetting --target never silently produces the bloated
# image again.
# ---------------------------------------------------------------------------
FROM base AS prod

RUN composer install --no-interaction --no-progress --no-dev --optimize-autoloader

COPY public ./public
COPY src ./src
COPY templates ./templates

CMD ["php-fpm"]
