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
| App     | http://localhost:8088 | The ranking page, see [Web UI](#web-ui); `/up` is the framework health route |
| API docs | http://localhost:8088/docs/api | OpenAPI 3.1, see [API](#api)                     |
| Metrics | http://localhost:8088/metrics | Health and metrics page, see [Observability](#observability); JSON at `/health` and `/api/v1/metrics` |
| Adminer | http://localhost:8089 | Local development only. Log in with server `mysql` and the `DB_USERNAME` / `DB_PASSWORD` from `.env` |
| phpCacheAdmin | http://localhost:8090 | Local development only. Redis UI (the API metrics counters) |
| MySQL   | `127.0.0.1:33060`     | For an IDE / database client (same credentials)             |

## Stack

| Service     | Image                         | Role                                                    |
|-------------|-------------------------------|---------------------------------------------------------|
| `app`       | `docker/php/Dockerfile` (PHP 8.5 FPM) | Laravel 13 application; bootstraps a fresh checkout |
| `scheduler` | same image                    | `php artisan schedule:work` (scheduled data collection) |
| `queue`     | same image                    | `php artisan queue:work` (ingestion and aggregation jobs) |
| `nginx`     | `nginx:1.30-alpine`           | HTTP entry point                                        |
| `mysql`     | `mysql:8.4` (LTS)             | Development database `air_quality` and test database `air_quality_test` |
| `redis`     | `redis:8-alpine` (AOF persistence) | API metrics counters only; not published on the host |
| `adminer`   | `adminer:6`                   | Database UI, bound to localhost (not for production)    |
| `phpcacheadmin` | `robinn/phpcacheadmin`    | Redis UI, bound to localhost (not for production)       |

The PHP image includes the `phpredis` extension (PECL, pinned). Cache, sessions and the queue stay
on the database; Redis holds nothing but the API metrics.

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

## Web UI

http://localhost:8088 is a single page that answers the question of the brief. Pick a period and
a metric, and it shows:

- **Cleanest city**: the first city of the ranking and its average;
- **Period**: the selected days, and **Available data**: the stored period (any range inside it
  can be ranked);
- **The ranking** of all cities: position, average (µg/m³), coverage (days with data out of the
  days in the period, and the number of hourly measurements; incomplete coverage is highlighted)
  and the period stored for each city. Columns `#`, City, Average and Coverage can be sorted;
  the default order is the ranking itself.

Periods: the quick buttons `7 days`, `30 days` (the default), `3 months` (90 days) and `6 months`
(180 days) all end today; `Custom` (or editing From / To and pressing Apply) ranks any range.
The metric switch lists the active metrics (PM2.5 by default, PM10). Light and dark themes follow
the system setting; the toggle in the header overrides it and is remembered in the browser.

The page is a thin client of [`GET /api/v1/ranking`](#get-apiv1ranking): every number comes from
the API, and its validation errors (future dates, `from` after `to`, dates outside the stored
period) are shown at the related field. It never calls the data provider. It is plain Blade, CSS
and vanilla JavaScript served from `public/`, with no Node or build step and no external
resources.

"Today" for the quick buttons is the Europe/Sofia calendar day, rendered by the server. A tab left
open past midnight keeps the previous day until it is reloaded.

The fonts [DM Sans](https://github.com/googlefonts/dm-fonts) and
[Sora](https://github.com/sora-xor/sora-font) are self-hosted from `public/fonts/` under the
[SIL Open Font License 1.1](https://openfontlicense.org/).

## API

Interactive docs: http://localhost:8088/docs/api (OpenAPI 3.1 spec at
http://localhost:8088/docs/api.json, generated from the code by
[Scramble](https://scramble.dedoc.co/) and public in every environment).

### `GET /api/v1/ranking`

Ranks the active cities by the average of a metric over a period, from the cleanest to the most
polluted. The answer to *which city had the cleanest air* is `data[0]`.

| Parameter | Required | Format       | Meaning                                                          |
|-----------|----------|--------------|------------------------------------------------------------------|
| `from`    | yes      | `YYYY-MM-DD` | First day of the period, inclusive                               |
| `to`      | yes      | `YYYY-MM-DD` | Last day of the period, inclusive; `from = to` is a single day   |
| `metric`  | no       | `pm25`, `pm10` | Metric to rank by; `pm25` by default; only active metrics      |

```bash
curl 'http://localhost:8088/api/v1/ranking?from=2026-07-01&to=2026-07-31'
```

```json
{
    "data": [
        {
            "rank": 1,
            "city": { "slug": "varna", "name": "Varna" },
            "average": 6.27,
            "measurements": 744,
            "days_with_data": 31,
            "stored": { "from": "2026-04-14", "to": "2026-10-17" }
        }
    ],
    "meta": {
        "period": { "from": "2026-07-01", "to": "2026-07-31", "days": 31 },
        "metric": { "slug": "pm25", "label": "PM2.5", "unit": "µg/m³" },
        "stored": { "from": "2026-04-14", "to": "2026-10-17" }
    }
}
```

(the other four cities are left out above).

- **Days** are `Europe/Sofia` calendar days, like the daily aggregates; "today" is today in
  Sofia and is allowed once it has data (it then covers the hours collected so far).
- **Average** is `SUM(metric_sum) / SUM(metric_count)` over the days of the period, i.e. weighted
  by the number of hourly measurements, not an average of daily averages. It is ordered on the
  exact value and rounded to 2 decimals in the response; equal averages are ordered by city name.
- **Coverage**: per city, `measurements` (hourly values) and `days_with_data` in the period, and
  `stored`, the first and last day the city has data for. A city whose data starts later than
  `from` (or ends earlier than `to`) still takes part with the days it has.
- **Which cities**: active cities with at least one measurement in the period. A period inside
  the stored range where no city has data returns `200` with an empty `data`.
- **Stored period** (`meta.stored`) is read from the data: from the earliest to the latest day any
  active city has daily data for the metric.
- The response is built from `air_quality_daily` only, with a fixed number of queries for any
  period length; the provider is never called, so the API keeps serving when it is down.

Errors are `422` with the standard Laravel body `{"message": "...", "errors": {"<field>": ["..."]}}`
and no partial result:

| Case                                                     | Field            |
|----------------------------------------------------------|------------------|
| `from` / `to` missing, not `YYYY-MM-DD` or impossible (`2026-02-30`) | `from` / `to` |
| `from` after `to`                                        | `from`           |
| A date in the future (Sofia time)                        | `from` / `to`    |
| A date before or after the stored period; the message names it, e.g. *The from date must be within the stored period 2026-04-14 – 2026-10-17.* | `from` / `to` |
| Unknown or inactive metric                               | `metric`         |
| No data collected yet for the metric (e.g. before the first backfill) | `metric` |

## Observability

Three read-only views of the application and its data collection. None of them calls the data
provider; they read the database, the `ingestion_runs` journal and Redis.

### `GET /health`

```json
{
  "status": "ok",
  "checked_at": "2026-10-18T12:00:00+00:00",
  "checks": {
    "database": { "status": "ok" },
    "freshness": {
      "threshold_hours": 3,
      "providers": [
        { "provider": "open-meteo", "name": "Open-Meteo", "status": "ok", "last_success_at": "2026-10-18T11:00:12+00:00" }
      ]
    },
    "metrics_store": { "status": "ok" }
  }
}
```

| `status`   | HTTP | When |
|------------|------|------|
| `ok`       | 200  | The database answers and every active provider has a successful run within the threshold |
| `degraded` | 200  | The database answers, but an active provider (`stale`) has no successful run within the threshold; the stored data is still served, and the response says which provider and since when (`last_success_at`, `null` if it never succeeded) |
| `down`     | 503  | The database does not answer (`freshness` is then `null`) |

- **Freshness** is judged per active provider (`data_provider.is_active`) as a whole: its latest
  successful run for any city. One city without a recent run does not degrade the status. The
  threshold is `air_quality.health.freshness_hours` (3, the scheduler runs hourly).
- **Metrics store** (`ok` / `unavailable`) is informative only: the API works without Redis, so it
  never changes the status.
- `/health` is outside the `web` middleware group (no session), so it still answers 503 when the
  database is down. With the `mysql` container *stopped* (`docker compose stop mysql`) that takes
  several seconds in this setup, because resolving the missing container name fails slowly; a
  database server that refuses connections is reported at once. Laravel's `/up` stays as the cheap liveness probe of the Docker healthcheck.

### `GET /api/v1/metrics`

Documented in the [API docs](http://localhost:8088/docs/api). The response (`data`):

- `ingestion.total` and `ingestion.last_24h` (runs started in the last 24 hours): runs per outcome
  (`success`, `failed`, `running`), `rows_written`, `rows_rejected` and `avg_duration_ms` (finished
  runs only, `null` when there is none);
- `ingestion.last_run_by_provider`: the newest run of each provider (city, outcome, times, rows);
- `ingestion.recent_failures`: the 5 newest failed runs (`air_quality.metrics.recent_failures`)
  with provider, city, time and error;
- `api`: `requests`, `errors` (4xx + 5xx), `by_status` (`2xx` … `5xx`), `avg_latency_ms` and
  `max_latency_ms` of every request under `/api` since the counters started, **except this
  endpoint** (the Metrics page polls it every 30 s and would skew the numbers; it is still logged).
  `api` is `null` when Redis does not answer (the rest of the response is unaffected, a warning is
  logged).

**API metrics** are recorded by the terminable middleware `RecordApiMetrics` (global and first,
acting only on `/api/*`, so a 404 for an unknown API path counts too): it measures the request in the application, then, after the response has been sent, logs
it and adds it to one Redis hash with a single Lua script (atomic, one round trip: count, status
class, latency sum and an atomic max). The Redis connection `metrics` has 0.5 s timeouts and no
retries; if Redis is down the request is answered normally, the metric is skipped and
`API metrics not recorded` is logged. A circuit breaker (`CircuitBreakingApiMetricsStore`) then
leaves Redis alone for 30 s (`air_quality.metrics.retry_after_seconds`, a marker file in
`storage/framework/cache`): every metrics call fails at once and `/health` reports `unavailable`
immediately. Without it, a *stopped* Redis container costs ≈3 s per call (the failing DNS lookup of
its name, which the 0.5 s timeouts do not cover) and would keep a PHP worker busy that long after
every API request. The counters survive restarts (AOF on the `redis-data`
volume). Only totals, a sum and a maximum are kept: no percentiles or time series.

**Measured** on the test database with 200 000 synthetic runs (≈4.5 years at the scheduled rate,
2 providers, 5 cities), MySQL 8.4 in Docker on WSL2: the endpoint took ≈255 ms with table scans for
every part. A migration adds three indexes on `ingestion_runs`; with them `last_24h` (0.5 ms), the
last run per provider (0.3 ms, a skip scan for `MAX(id)` per provider) and the newest failures
(0.2 ms) read only what they return, and `total` is one covering index scan grouped by outcome
(≈35 ms). The whole endpoint: median 39 ms over 10 requests. `total` still grows linearly with the
journal (≈44 000 runs a year at 5 cities); a rollup table would make it constant if the history
gets much longer.

### Metrics page

http://localhost:8088/metrics (the **Metrics** link in the header, next to API docs) is a thin
client of the two endpoints above, in the same design and themes as the ranking page:

- a health band: overall status, database, Redis, and the freshness of each provider (last
  successful run, "… ago", against the threshold);
- cards: runs and rows for the last 24 hours and in total, average run duration, API requests,
  errors and latency;
- charts (inline SVG, colours from the theme's CSS variables, values listed under every bar):
  runs by outcome and rows written / rejected (last 24 hours and all time), API responses by
  status class;
- tables: the last run of each provider and the newest failures with their error;
- derived values computed in the browser: success rate, rejected share, rows per successful run,
  API error rate.

It refreshes every 30 seconds and with the Refresh button, and shows when it last updated. When
the database is down or an endpoint fails, it says so and dims the values left from the previous
update, so they do not look current. Like `/health`, the page is outside the `web` group, so it
loads without the database.

### Logs

Every log line goes to `storage/logs/laravel.log` (plain text) and to stderr as **one JSON object
per line** (`LOG_STDERR_FORMATTER`, Monolog `JsonFormatter` by default), which is what
`make logs ARGS="app queue"` shows. The lines that end an ingestion run (`Ingestion run finished`,
`Ingestion run failed`, …) always carry `provider, city, type, from, to, outcome, rows_written,
rows_rejected, duration_ms` (plus `error` on failure); `Daily aggregation finished` carries
`city, from, to, outcome, days, rows_written, duration_ms`. Every API request is logged as
`API request` with `method, path, route, status, duration_ms`.

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
| `PHPCACHEADMIN_PORT` | `8090` | phpCacheAdmin          |
| `FORWARD_DB_PORT` | `33060` | MySQL on the host       |
| `OPEN_METEO_URL`  | `https://air-quality-api.open-meteo.com` | Open-Meteo API base URL |
| `OPEN_METEO_TIMEOUT` | `15` | Open-Meteo request timeout (seconds) |
| `REDIS_HOST`      | `redis` | Redis host (the Compose service) |
| `REDIS_METRICS_DB` | `0`    | Redis database of the API metrics (tests use 15) |

If you change `APP_PORT`, update `APP_URL` too. A `.env` created before Redis was added still has
`REDIS_HOST=127.0.0.1`: set it to `redis` (and add `REDIS_METRICS_DB=0`, `PHPCACHEADMIN_PORT=8090`),
otherwise the API keeps working but its metrics are not recorded. The database credentials in `.env.example` are for
local development only.
MySQL applies `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `DB_ROOT_PASSWORD` only when it
initialises an empty data volume, so changing them later also requires recreating the volume (see
[Tests](#tests)).

Laravel logs go to `storage/logs/laravel.log` and to stderr (as JSON lines), so they also show up
in `make logs`; see [Logs](#logs).
