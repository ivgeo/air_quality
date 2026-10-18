<?php

namespace App\Ingestion\Providers\OpenAq;

use App\Enums\Metric;
use App\Ingestion\Exceptions\ProviderException;
use App\Models\City;
use Carbon\CarbonImmutable;

/**
 * Finds the reference stations around a city: Bulgarian monitors within the configured radius
 * that still report, keeping their sensors of the metrics we store. The API filters are checked
 * again on the response, so a station across the border (e.g. Giurgiu near Ruse) never slips in.
 */
class StationDiscovery
{
    /**
     * OpenAQ parameter name => App\Enums\Metric.
     */
    private const array PARAMETERS = [
        'pm25' => Metric::Pm25,
        'pm10' => Metric::Pm10,
    ];

    private const string COUNTRY = 'BG';

    /**
     * More locations than any radius up to the API's 25 km holds around these cities.
     */
    private const int LIMIT = 100;

    /**
     * @param  array{radius_m: int, active_within_days: int}  $config
     */
    public function __construct(
        private readonly OpenAqClient $client,
        private readonly array $config,
    ) {}

    /**
     * @throws ProviderException
     */
    public function discover(City $city): CityStations
    {
        $body = $this->client->get('/v3/locations', [
            'coordinates' => "{$city->latitude},{$city->longitude}",
            'radius' => $this->config['radius_m'],
            'iso' => self::COUNTRY,
            'monitor' => 'true',
            'limit' => self::LIMIT,
        ]);

        /** @var mixed $locations */
        $locations = $body['results'] ?? null;

        if (! is_array($locations) || ! array_is_list($locations)) {
            throw new ProviderException('OpenAQ locations response has no "results" list.');
        }

        $activeSince = CarbonImmutable::now()->subDays($this->config['active_within_days']);
        /** @var list<Sensor> $sensors */
        $sensors = [];

        foreach ($locations as $location) {
            if ($this->isActiveReferenceStation($location, $activeSince)) {
                array_push($sensors, ...$this->sensors($location));
            }
        }

        return new CityStations(CarbonImmutable::now(), $sensors);
    }

    private function isActiveReferenceStation(mixed $location, CarbonImmutable $activeSince): bool
    {
        if (! is_array($location) || ($location['isMonitor'] ?? null) !== true || ($location['country']['code'] ?? null) !== self::COUNTRY) {
            return false;
        }

        /** @var mixed $last */
        $last = $location['datetimeLast']['utc'] ?? null;

        $lastAt = is_string($last) ? OpenAqClient::parseTime($last) : null;

        return $lastAt !== null && $lastAt->greaterThanOrEqualTo($activeSince);
    }

    /**
     * @param  array<mixed>  $location
     * @return list<Sensor>
     */
    private function sensors(array $location): array
    {
        $name = is_string($location['name'] ?? null) ? $location['name'] : 'location '.(string) json_encode($location['id'] ?? null);
        /** @var list<Sensor> $sensors */
        $sensors = [];

        foreach (is_array($location['sensors'] ?? null) ? $location['sensors'] : [] as $sensor) {
            /** @var mixed $parameter */
            $parameter = is_array($sensor) ? ($sensor['parameter']['name'] ?? null) : null;
            $metric = is_string($parameter) ? (self::PARAMETERS[$parameter] ?? null) : null;

            if ($metric !== null && is_int($sensor['id'] ?? null)) {
                $sensors[] = new Sensor($sensor['id'], $metric, $name);
            }
        }

        return $sensors;
    }
}
