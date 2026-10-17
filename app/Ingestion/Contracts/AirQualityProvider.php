<?php

namespace App\Ingestion\Contracts;

use App\Ingestion\Data\HourlySeries;
use App\Ingestion\Exceptions\ProviderException;
use App\Models\City;
use Carbon\CarbonImmutable;

/**
 * A source of hourly air quality data. Implementations are registered by slug in
 * config/air_quality.php and receive that config entry as the `config` constructor argument.
 *
 * A provider only translates its own API: it maps its metric keys to App\Enums\Metric values and
 * checks the response structure. Value rules (missing, negative, future hours) are applied by
 * App\Ingestion\HourlySeriesValidator for every provider alike.
 */
interface AirQualityProvider
{
    /**
     * Fetch the hours from $from to $to (inclusive, UTC) for the city's coordinates.
     *
     * @throws ProviderException when the provider is unreachable, answers with an error or returns
     *                           a response of an unexpected shape
     */
    public function fetchHourly(City $city, CarbonImmutable $from, CarbonImmutable $to): HourlySeries;
}
