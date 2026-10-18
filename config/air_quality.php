<?php

use App\Ingestion\Providers\OpenAq\OpenAqProvider;
use App\Ingestion\Providers\OpenMeteoProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Calendar timezone
    |--------------------------------------------------------------------------
    |
    | Hourly data is stored in UTC; a calendar day (the `date` of the daily rows and of every
    | requested period) starts and ends at local midnight in this timezone, so a day spans 23, 24
    | or 25 UTC hours. Only App\Support\LocalCalendar reads it.
    |
    */

    'timezone' => 'Europe/Sofia',

    /*
    |--------------------------------------------------------------------------
    | Data providers
    |--------------------------------------------------------------------------
    |
    | How to talk to each provider, keyed by `data_provider.slug`. Which providers actually run is
    | decided by `data_provider.is_active`; an active provider without a usable entry here (none,
    | or a required setting such as an API key missing) is skipped and its runs are recorded as
    | failed. `class` must implement App\Ingestion\Contracts\AirQualityProvider and receives the
    | whole entry as its config. `job_timeout` (seconds, default 80) bounds one ingestion job and
    | must stay below the queue's retry_after (config/queue.php).
    |
    */

    'providers' => [
        'open-meteo' => [
            'class' => OpenMeteoProvider::class,
            'url' => env('OPEN_METEO_URL', 'https://air-quality-api.open-meteo.com'),
            // CAMS European regional model (~11 km), the best resolution for Bulgaria.
            'domain' => 'cams_europe',
            'timeout' => (int) env('OPEN_METEO_TIMEOUT', 15),
            'connect_timeout' => 5,
            // Retries after the first attempt, for connection errors, 5xx and 429 only.
            'retries' => 2,
            'retry_sleep_ms' => 1000,
        ],

        // Reference stations (EEA) near each city, discovered by radius and kept in
        // `data_provider.state`. The key is sent as a header and never logged.
        'openaq' => [
            'class' => OpenAqProvider::class,
            'url' => env('OPENAQ_URL', 'https://api.openaq.org'),
            'api_key' => env('OPENAQ_API_KEY'),
            // Stations within this distance of the city's coordinates (the API allows 25 km).
            'radius_m' => 15000,
            // A station whose last measurement is older than this is treated as shut down.
            'active_within_days' => 7,
            // How long a city's discovered stations are reused before /locations is asked again.
            'discovery_ttl_hours' => 24,
            'timeout' => (int) env('OPENAQ_TIMEOUT', 15),
            'connect_timeout' => 5,
            // Retries after the first attempt, for connection errors, 5xx and 429 only; a 429 waits
            // the x-ratelimit-reset seconds, capped at max_wait_seconds.
            'retries' => 2,
            'max_wait_seconds' => 65,
            // Requests per window for the whole key (the free tier allows 60/minute and 2000/hour).
            'rate_limit' => [
                'per_minute' => 50,
                'per_hour' => 1800,
            ],
            // Pages of 1000 hours per sensor; 186 days need 5.
            'max_pages' => 10,
            // A run gives up (failed) before the job timeout kills it, which would leave it running.
            'time_budget_seconds' => 270,
            'job_timeout' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    |
    | refresh_days: how many past days the hourly job re-fetches (providers revise recent hours).
    | backfill_days: default history length for air-quality:backfill; 186 days cover any
    | 6 calendar months plus a margin for the Europe/Sofia day boundaries.
    |
    */

    'ingestion' => [
        'refresh_days' => 3,
        'backfill_days' => 186,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health check
    |--------------------------------------------------------------------------
    |
    | freshness_hours: /health reports an active provider as stale (status "degraded") when its
    | latest successful run finished longer ago than this; the scheduler runs every hour.
    |
    */

    'health' => [
        'freshness_hours' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | recent_failures: how many of the newest failed ingestion runs GET /api/v1/metrics lists.
    | redis_connection: the Redis connection (config/database.php) holding the API counters.
    | retry_after_seconds: after a failed call the store is left alone this long (every call
    | fails at once), so an unavailable Redis costs one slow attempt per interval.
    | untracked_routes: API route names logged but not counted, e.g. the metrics endpoint itself,
    | which the Metrics page polls every 30 seconds.
    |
    */

    'metrics' => [
        'recent_failures' => 5,
        'redis_connection' => 'metrics',
        'retry_after_seconds' => 30,
        'untracked_routes' => ['api.v1.metrics'],
    ],

];
