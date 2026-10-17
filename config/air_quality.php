<?php

use App\Ingestion\Providers\OpenMeteoProvider;

return [

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

];
