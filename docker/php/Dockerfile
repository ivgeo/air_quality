FROM php:8.5-fpm

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libfcgi-bin \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/fpm/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

# Run as a user matching the host UID/GID so files written to the bind mount stay editable.
RUN groupadd --gid "${GID}" app \
    && useradd --uid "${UID}" --gid app --create-home --shell /bin/bash app \
    && mkdir -p /home/app/.cache/composer \
    && chown -R app:app /home/app /var/www/html

ENV COMPOSER_CACHE_DIR=/home/app/.cache/composer

WORKDIR /var/www/html

USER app

EXPOSE 9000

CMD ["php-fpm"]
