<?php

namespace App\Ingestion\Providers\OpenAq;

use App\Ingestion\Contracts\AirQualityProvider;
use App\Ingestion\Contracts\ProviderStateStore;
use App\Ingestion\Data\HourlyReading;
use App\Ingestion\Data\HourlySeries;
use App\Ingestion\Exceptions\ProviderException;
use App\Ingestion\Exceptions\ProviderNotConfiguredException;
use App\Ingestion\HourlySeriesValidator;
use App\Models\City;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * OpenAQ v3 (https://docs.openaq.org/): hourly measurements of the reference stations around a
 * city (EEA data, ODC-BY).
 *
 * The stations are discovered by radius and kept in `data_provider.state` for a day. A city's
 * hour is the mean of its sensors with a valid, unflagged value for that hour and metric, so
 * OpenAQ stores one row per city and hour like any provider. Hours are keyed by the start of
 * their period in UTC. One instance serves one ingestion run (its client holds the time budget).
 */
class OpenAqProvider implements AirQualityProvider
{
    /**
     * The most hours the API returns per page.
     */
    private const int PAGE_SIZE = 1000;

    /**
     * How long a discovery may hold its city's lock before it expires.
     */
    private const int DISCOVERY_LOCK_SECONDS = 120;

    private readonly OpenAqClient $client;

    private readonly StationDiscovery $discovery;

    /**
     * @param  array{url: string, api_key: ?string, radius_m: int, active_within_days: int, discovery_ttl_hours: int, timeout: int, connect_timeout: int, retries: int, max_wait_seconds: int, rate_limit: array{per_minute: int, per_hour: int}, max_pages: int, time_budget_seconds: int}  $config
     *
     * @throws ProviderNotConfiguredException without an API key
     */
    public function __construct(
        private readonly array $config,
        private readonly ProviderStateStore $state,
    ) {
        $this->client = new OpenAqClient($config);
        $this->discovery = new StationDiscovery($this->client, $config);
    }

    public static function stateClass(): string
    {
        return OpenAqState::class;
    }

    public function fetchHourly(City $city, CarbonImmutable $from, CarbonImmutable $to): HourlySeries
    {
        $startedAt = hrtime(true);
        /** @var list<Sensor> $sensors */
        $sensors = [];
        /** @var array<int, array<string, list<float>>> $values timestamp => metric => sensor values */
        $values = [];

        try {
            $sensors = $this->stations($city)->sensors;

            foreach ($sensors as $sensor) {
                // A margin on each side; the validator cuts the hours outside the period.
                foreach ($this->sensorHours($sensor, $from->subHour(), $to->addHour()) as $timestamp => $value) {
                    $values[$timestamp][$sensor->metric->value][] = $value;
                }
            }
        } finally {
            Log::info('OpenAQ fetch finished', [
                'city' => $city->slug,
                'sensors' => count($sensors),
                'hours' => count($values),
                ...$this->client->stats(),
                'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
            ]);
        }

        ksort($values);
        /** @var list<HourlyReading> $readings */
        $readings = [];

        foreach ($values as $timestamp => $metrics) {
            $readings[] = new HourlyReading(
                CarbonImmutable::createFromTimestamp($timestamp, 'UTC'),
                array_map(fn (array $metricValues): float => array_sum($metricValues) / count($metricValues), $metrics),
            );
        }

        return new HourlySeries($readings);
    }

    /**
     * The city's known stations while they are fresh; otherwise discovered again under a lock
     * per city, falling back to the known ones when the discovery fails.
     *
     * @throws ProviderException
     */
    private function stations(City $city): CityStations
    {
        $known = $this->knownStations($city);

        if ($known !== null && ! $known->isStale($this->config['discovery_ttl_hours'])) {
            return $known;
        }

        /** @var CityStations|false $stations */
        $stations = Cache::lock("openaq:discovery:{$city->slug}", self::DISCOVERY_LOCK_SECONDS)
            ->get(fn (): CityStations => $this->rediscover($city));

        if ($stations !== false) {
            return $stations;
        }

        if ($known === null) {
            throw new ProviderException("OpenAQ stations of {$city->slug} are being discovered by another run.");
        }

        return $known;
    }

    /**
     * @throws ProviderException when the discovery fails and no stations are known
     */
    private function rediscover(City $city): CityStations
    {
        // Another run may have refreshed them while this one waited for the lock.
        $known = $this->knownStations($city);

        if ($known !== null && ! $known->isStale($this->config['discovery_ttl_hours'])) {
            return $known;
        }

        try {
            $stations = $this->discovery->discover($city);
        } catch (ProviderException $e) {
            if ($known === null) {
                throw $e;
            }

            // discovered_at stays, so the next run tries again.
            Log::warning('OpenAQ station discovery failed, using the last known stations', [
                'city' => $city->slug,
                'discovered_at' => $known->discoveredAt->toAtomString(),
                'sensors' => count($known->sensors),
                'error' => $e->getMessage(),
            ]);

            return $known;
        }

        $this->state->putCity($city->slug, $stations->toArray());

        Log::info('OpenAQ station discovery', [
            'city' => $city->slug,
            'sensors' => count($stations->sensors),
            'locations' => array_values(array_unique(array_map(fn (Sensor $sensor): string => $sensor->location, $stations->sensors))),
        ]);

        return $stations;
    }

    private function knownStations(City $city): ?CityStations
    {
        $state = $this->state->get();

        return $state instanceof OpenAqState ? $state->city($city->slug) : null;
    }

    /**
     * The sensor's valid, unflagged values keyed by the UTC timestamp of the hour's start.
     *
     * @return array<int, float>
     *
     * @throws ProviderException
     */
    private function sensorHours(Sensor $sensor, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<int, float> $hours */
        $hours = [];

        for ($page = 1; ; $page++) {
            if ($page > $this->config['max_pages']) {
                throw new ProviderException(sprintf('OpenAQ sensor %d has more than %d pages of hours for the period.', $sensor->id, $this->config['max_pages']));
            }

            $body = $this->client->get("/v3/sensors/{$sensor->id}/hours", [
                'datetime_from' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
                'datetime_to' => $to->utc()->format('Y-m-d\TH:i:s\Z'),
                'limit' => self::PAGE_SIZE,
                'page' => $page,
            ]);

            /** @var mixed $results */
            $results = $body['results'] ?? null;

            if (! is_array($results) || ! array_is_list($results)) {
                throw new ProviderException(sprintf('OpenAQ hours of sensor %d have no "results" list.', $sensor->id));
            }

            foreach ($results as $hour) {
                [$timestamp, $value] = $this->parseHour($sensor, $hour);

                if ($value !== null) {
                    $hours[$timestamp] = $value;
                }
            }

            if (count($results) < self::PAGE_SIZE) {
                return $hours;
            }
        }
    }

    /**
     * @return array{int, float|null} the hour's start and its value, null when it is missing,
     *                                invalid or flagged
     *
     * @throws ProviderException when the hour does not have the expected shape or unit
     */
    private function parseHour(Sensor $sensor, mixed $hour): array
    {
        /** @var mixed $start */
        $start = is_array($hour) ? ($hour['period']['datetimeFrom']['utc'] ?? null) : null;
        /** @var mixed $unit */
        $unit = is_array($hour) ? ($hour['parameter']['units'] ?? null) : null;

        if (! is_string($start)) {
            throw new ProviderException(sprintf('OpenAQ hour of sensor %d has no period start.', $sensor->id));
        }

        if (! is_string($unit) || self::normalizeUnit($unit) !== self::normalizeUnit($sensor->metric->unit())) {
            throw new ProviderException(sprintf(
                'OpenAQ sensor %d unit is "%s", expected "%s".',
                $sensor->id,
                is_string($unit) ? $unit : get_debug_type($unit),
                $sensor->metric->unit(),
            ));
        }

        $startAt = OpenAqClient::parseTime($start);

        if ($startAt === null) {
            throw new ProviderException(sprintf('OpenAQ hour of sensor %d starts at an invalid time "%s".', $sensor->id, $start));
        }

        /** @var mixed $value */
        $value = $hour['value'] ?? null;
        $valid = HourlySeriesValidator::isValidValue($value) && ($hour['flagInfo']['hasFlags'] ?? false) !== true;

        return [$startAt->getTimestamp(), $valid ? (float) $value : null];
    }

    /**
     * The micro sign (U+00B5) and the Greek mu (U+03BC) both appear in "µg/m³".
     */
    private static function normalizeUnit(string $unit): string
    {
        return str_replace("\u{03BC}", "\u{00B5}", $unit);
    }
}
