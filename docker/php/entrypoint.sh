#!/usr/bin/env bash
# Container entrypoint shared by the "app" and "scheduler" services (selected by CONTAINER_ROLE).
# The app role bootstraps a fresh checkout idempotently, so `docker compose up` alone is enough.
set -euo pipefail

cd /var/www/html

log() {
    echo "[entrypoint:${CONTAINER_ROLE:-app}] $*"
}

bootstrap() {
    if [ ! -f .env ]; then
        log "Creating .env from .env.example"
        cp .env.example .env
    fi

    if [ ! -f vendor/composer/installed.json ] || [ composer.lock -nt vendor/composer/installed.json ]; then
        log "Installing Composer dependencies"
        composer install --no-interaction --prefer-dist --no-progress
        # Mark vendor/ as up to date with composer.lock even when nothing had to change.
        touch vendor/composer/installed.json
    else
        log "Composer dependencies are up to date"
    fi

    if ! grep -qE '^APP_KEY=.+' .env; then
        log "Generating APP_KEY"
        php artisan key:generate --force --no-interaction
    fi

    log "Running database migrations"
    local attempt=1
    until php artisan migrate --force --no-interaction; do
        if [ "${attempt}" -ge 10 ]; then
            log "Migrations failed after ${attempt} attempts"
            exit 1
        fi
        log "Migrations failed (attempt ${attempt}), retrying in 3s"
        attempt=$((attempt + 1))
        sleep 3
    done

    log "Seeding reference data"
    php artisan db:seed --force --no-interaction
}

case "${CONTAINER_ROLE:-app}" in
    app)
        bootstrap
        log "Starting php-fpm"
        exec php-fpm
        ;;
    scheduler)
        log "Starting the scheduler"
        exec php artisan schedule:work
        ;;
    *)
        log "Unknown CONTAINER_ROLE '${CONTAINER_ROLE}' (expected: app, scheduler)"
        exit 1
        ;;
esac
