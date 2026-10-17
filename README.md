# Clean Air Finder

A web service and minimal web UI that answers: *which Bulgarian city had the cleanest air
(average PM2.5, µg/m³) over a given period?*

> Work in progress: this README currently covers running the project. Architecture, data source,
> assumptions and trade-offs will be documented as the features land.

## Requirements

- Docker with the Compose plugin (`docker compose`)
- GNU make

Nothing else is needed on the host: PHP, Composer and Artisan all run inside the containers.

## Quick start

```bash
make up
```

That single command builds the images and starts the stack. On the first run it also creates `.env`
from `.env.example`, installs the Composer dependencies, generates `APP_KEY` and runs the
migrations. It returns once every service is healthy, and it is safe to run again: an existing
`.env` is never overwritten.

| What    | URL                   | Notes                                                       |
|---------|-----------------------|-------------------------------------------------------------|
| App     | http://localhost:8088 | `/up` is the framework health route                         |
| Adminer | http://localhost:8089 | Local development only. Log in with server `mysql` and the `DB_USERNAME` / `DB_PASSWORD` from `.env` |
| MySQL   | `127.0.0.1:33060`     | For an IDE / database client (same credentials)             |

## Stack

| Service     | Image                         | Role                                                    |
|-------------|-------------------------------|---------------------------------------------------------|
| `app`       | `docker/php/Dockerfile` (PHP 8.5 FPM) | Laravel 13 application; bootstraps a fresh checkout |
| `scheduler` | same image                    | `php artisan schedule:work` (scheduled data collection) |
| `nginx`     | `nginx:1.30-alpine`           | HTTP entry point                                        |
| `mysql`     | `mysql:8.4` (LTS)             | Development database `air_quality` and test database `air_quality_test` |
| `adminer`   | `adminer:6`                   | Database UI, bound to localhost (not for production)    |

The PHP image is built with your host UID/GID (passed by the Makefile), so files created by the
containers in the project directory stay owned by you.

## Make targets

Run `make` (or `make help`) to list them. Extra arguments go through `ARGS="..."`.

| Command        | What it does                                               |
|----------------|------------------------------------------------------------|
| `make up`      | Build and start everything, wait until healthy             |
| `make down`    | Stop and remove the containers (data volumes are kept)     |
| `make restart` | `down` + `up`                                              |
| `make build`   | Rebuild the PHP image                                      |
| `make ps`      | Service status and health                                  |
| `make logs`    | Follow logs, e.g. `make logs ARGS="app scheduler"`         |
| `make sh`      | Shell in the `app` container                               |
| `make artisan` | Artisan, e.g. `make artisan ARGS="migrate:status"`         |
| `make composer`| Composer, e.g. `make composer ARGS="show"`                 |
| `make test`    | PHPUnit suite, e.g. `make test ARGS="--filter=SomeTest"`   |
| `make mysql`   | MySQL client on the development database                   |

## Tests

```bash
make test
```

PHPUnit runs inside the `app` container against the separate MySQL database `air_quality_test`
(configured in `phpunit.xml`), so the development data is never touched.

The test database is created by `docker/mysql/init/01-create-test-database.sh`. MySQL only runs
init scripts when its data volume is empty. If you already had a `mysql-data` volume from before
that script existed, recreate it with `docker compose down -v && make up`. **This deletes all local
database data.**

## Configuration

All settings live in `.env` (created from `.env.example` on the first run). If a host port is
taken, change it there and run `make up` again:

| Variable          | Default | Used for                |
|-------------------|---------|-------------------------|
| `APP_PORT`        | `8088`  | App (nginx)             |
| `ADMINER_PORT`    | `8089`  | Adminer                 |
| `FORWARD_DB_PORT` | `33060` | MySQL on the host       |

If you change `APP_PORT`, update `APP_URL` too. The database credentials in `.env.example` are for
local development only.
MySQL applies `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `DB_ROOT_PASSWORD` only when it
initialises an empty data volume, so changing them later also requires recreating the volume (see
[Tests](#tests)).

Laravel logs go to `storage/logs/laravel.log` and to stderr, so they also show up in `make logs`.
