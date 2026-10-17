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
| `queue`     | same image                    | `php artisan queue:work` (ingestion and aggregation jobs) |
| `nginx`     | `nginx:1.30-alpine`           | HTTP entry point                                        |
| `mysql`     | `mysql:8.4` (LTS)             | Development database `air_quality` and test database `air_quality_test` |
| `adminer`   | `adminer:6`                   | Database UI, bound to localhost (not for production)    |

The PHP image is built with your host UID/GID (passed by the Makefile), so files created by the
containers in the project directory stay owned by you.

## Data collection

Air quality data is collected in the background and stored locally; a user request never calls the
provider. The first provider is the [Open-Meteo Air Quality API](https://open-meteo.com/en/docs/air-quality-api)
(CAMS Europe model, PM2.5 and PM10, hourly, UTC).

| Command                                         | What it does                                                    |
|-------------------------------------------------|-----------------------------------------------------------------|
| `make artisan ARGS="air-quality:backfill"`      | Fill the history (default 186 days, `--days=N` to change)       |
| `make artisan ARGS="air-quality:ingest"`        | Re-fetch the last 3 days; the scheduler runs this every hour    |

Both commands only queue one job per active provider and active city (`data_provider` / `city`
tables); the `queue` service runs them. The history is empty after the first `make up`, so run
the backfill once. Both commands are safe to repeat: each job upserts the valid hours it got, inside a transaction,
on the unique key (city, provider, hour), merging new metric values into the stored ones. A slow,
failing or invalid provider response leaves the stored data untouched, and so does a single hour
or metric that comes back missing or invalid. Every job leaves a row in `ingestion_runs` (outcome, rows
written and rejected, error, duration) and logs its start and end; follow them with
`make logs ARGS="queue"`.

Providers are configured in `config/air_quality.php` (class, URL, timeouts, retries), keyed by
`data_provider.slug`. Adding a provider means a class implementing
`App\Ingestion\Contracts\AirQualityProvider`, a config entry and a `data_provider` row.

## Daily aggregation

Queries read pre-aggregated days, not hours. `air_quality_daily` holds, per city, calendar day and
metric, the **sum and count** of the stored hourly values of **all providers together** (an
average would not combine exactly over a period). An hour without a value for a metric is not
counted for it, so `metric_count` is the number of real values, not 24.

- **Calendar day**: hours are stored in UTC, a day runs from local midnight to local midnight in
  `Europe/Sofia` (`air_quality.timezone`, read only by `App\Support\LocalCalendar`). So 22:00 UTC
  in winter and 21:00 UTC in summer already belong to the next day, and the DST switch days have 23
  and 25 hours. The day bounds are computed in PHP, so MySQL needs no timezone tables.
- **When**: every successful ingestion run (scheduled refresh or backfill) queues an
  `AggregateDailyData` job for its city and the local days of its period; a failed run queues
  nothing and changes nothing. Jobs of the same city never overlap. So after the backfill the
  daily table fills by itself.
- **How**: each day is recomputed in full, in one transaction: delete the city's rows for the day,
  then one `INSERT … SELECT … GROUP BY` over the day's hourly rows, with the JSON paths built from
  the `Metric` enum. Running it again gives the same rows, a day that lost its hourly data loses its
  daily rows, and readers see either the old or the new day, never half of it. Statuses are not
  read: whatever was collected is aggregated, so deactivating a provider, city or metric needs no
  recomputation.
- **Failures**: an error rolls back only that day; the hourly data is never touched. A failed job
  is retried by the queue and then logged as `Daily aggregation failed` (city, period, error); the
  day keeps its previous rows until the next hourly run (which covers the last days again) or a
  manual recomputation (after a failed backfill aggregation, run `air-quality:aggregate` for the
  older days). Start and end of every aggregation are logged with days, rows and duration.

Full recomputation, synchronous (not queued):

| Command                                                              | What it does                                |
|----------------------------------------------------------------------|---------------------------------------------|
| `make artisan ARGS="air-quality:aggregate"`                          | Every city, from the first to the last stored day (hourly or daily) |
| `make artisan ARGS="air-quality:aggregate --from=2026-07-01 --to=2026-07-31"` | Only these local days (`Y-m-d`); a missing end comes from the stored data |
| `make artisan ARGS="air-quality:aggregate --city=sofia"`             | Only one city (inactive ones included); combines with `--from` / `--to` |

Invalid dates, `--from` after `--to` or an unknown city fail with an error and change nothing.

**Measured** on the development database (MySQL 8.4 in Docker on WSL2, 22 330 hourly rows,
5 cities, 1 provider, 187 days):

- One city-day: `EXPLAIN ANALYZE` of the `SELECT` part shows an index lookup on
  `unq_city_provider_measure` by `city_id` with an index condition on `measure_at`, no table scan:
  24 rows × 2 metrics in ≈0.65 ms. The condition is checked inside the index, but on every index
  entry of the city, so this grows with the history per city. An index on
  `(city_id, measure_at)` (or `data_provider_id IN (…)` to use the whole unique key) would make it
  a pure range read; not needed at this size.
- Full `air-quality:aggregate`: 935 city-days, 1 870 daily rows in ≈9.4 s (≈1.9 s per city,
  ≈10 ms per day, mostly the commit of each day's transaction). The hourly job only recomputes
  the last 4 days of one city (a few dozen ms).

## Make targets

Run `make` (or `make help`) to list them. Extra arguments go through `ARGS="..."`.

| Command        | What it does                                               |
|----------------|------------------------------------------------------------|
| `make up`      | Build and start everything, wait until healthy             |
| `make down`    | Stop and remove the containers (data volumes are kept)     |
| `make restart` | `down` + `up`                                              |
| `make build`   | Rebuild the PHP image                                      |
| `make ps`      | Service status and health                                  |
| `make logs`    | Follow logs, e.g. `make logs ARGS="app queue"`             |
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
| `OPEN_METEO_URL`  | `https://air-quality-api.open-meteo.com` | Open-Meteo API base URL |
| `OPEN_METEO_TIMEOUT` | `15` | Open-Meteo request timeout (seconds) |

If you change `APP_PORT`, update `APP_URL` too. The database credentials in `.env.example` are for
local development only.
MySQL applies `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `DB_ROOT_PASSWORD` only when it
initialises an empty data volume, so changing them later also requires recreating the volume (see
[Tests](#tests)).

Laravel logs go to `storage/logs/laravel.log` and to stderr, so they also show up in `make logs`.
