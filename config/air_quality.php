<?php

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
    | decided by `data_provider.is_active`; an active provider without an entry here is skipped
    | and its runs are recorded as failed. `class` must implement
    | App\Ingestion\Contracts\AirQualityProvider and receives the whole entry as its config.
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
