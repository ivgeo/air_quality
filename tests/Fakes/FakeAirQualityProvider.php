<?php

namespace Tests\Fakes;

use App\Ingestion\Contracts\AirQualityProvider;
use App\Ingestion\Data\HourlySeries;
use App\Models\City;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Provider registered in tests through config only, which also shows that a new provider needs
 * nothing but a class and a config entry. Its `handler` config builds the response (or throws).
 */
class FakeAirQualityProvider implements AirQualityProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(public readonly array $config) {}

    public function fetchHourly(City $city, CarbonImmutable $from, CarbonImmutable $to): HourlySeries
    {
        $handler = $this->config['handler'] ?? null;

        return $handler instanceof Closure ? $handler($city, $from, $to) : new HourlySeries([]);
    }
}
