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
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/entrypoint

# Run as a user matching the host UID/GID so files written to the bind mount stay editable.
# The host IDs may already exist in the base image (e.g. GID 20 on macOS), so reuse them.
RUN if ! getent group "${GID}" >/dev/null; then groupadd --gid "${GID}" app; fi \
    && useradd --uid "${UID}" --gid "${GID}" --non-unique --create-home --shell /bin/bash app \
    && mkdir -p /home/app/.cache/composer \
    && chown -R "${UID}:${GID}" /home/app /var/www/html

ENV COMPOSER_CACHE_DIR=/home/app/.cache/composer

WORKDIR /var/www/html

USER app

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
