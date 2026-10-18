<?php

namespace App\Ingestion\Providers;

use App\Enums\Metric;
use App\Ingestion\Contracts\AirQualityProvider;
use App\Ingestion\Data\HourlyReading;
use App\Ingestion\Data\HourlySeries;
use App\Ingestion\Data\NoProviderState;
use App\Ingestion\Exceptions\ProviderException;
use App\Models\City;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Open-Meteo Air Quality API (https://open-meteo.com/en/docs/air-quality-api): CAMS model data.
 *
 * Times are requested and returned as Unix timestamps in GMT, so no time zone parsing is needed.
 */
class OpenMeteoProvider implements AirQualityProvider
{
    /**
     * Open-Meteo variable => App\Enums\Metric.
     */
    private const array VARIABLES = [
        'pm2_5' => Metric::Pm25,
        'pm10' => Metric::Pm10,
    ];

    private const string UNIT = 'μg/m³';

    /**
     * @param  array{url: string, domain: string, timeout: int, connect_timeout: int, retries: int, retry_sleep_ms: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public static function stateClass(): string
    {
        return NoProviderState::class;
    }

    public function fetchHourly(City $city, CarbonImmutable $from, CarbonImmutable $to): HourlySeries
    {
        $response = $this->request([
            'latitude' => $city->latitude,
            'longitude' => $city->longitude,
            'hourly' => implode(',', array_keys(self::VARIABLES)),
            'domains' => $this->config['domain'],
            'timezone' => 'GMT',
            'timeformat' => 'unixtime',
            'start_hour' => $from->utc()->format('Y-m-d\TH:i'),
            'end_hour' => $to->utc()->format('Y-m-d\TH:i'),
        ]);

        return $this->parse($response);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function request(array $query): Response
    {
        try {
            $response = Http::baseUrl($this->config['url'])
                ->acceptJson()
                ->timeout($this->config['timeout'])
                ->connectTimeout($this->config['connect_timeout'])
                ->retry(
                    $this->config['retries'] + 1,
                    $this->config['retry_sleep_ms'],
                    fn (Throwable $e): bool => $e instanceof ConnectionException || ($e instanceof RequestException && $this->isTransient($e->response)),
                    throw: false,
                )
                ->get('/v1/air-quality', $query);
        } catch (ConnectionException $e) {
            throw new ProviderException('Could not reach Open-Meteo: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            /** @var mixed $reason */
            $reason = $response->json('reason');

            throw new ProviderException(sprintf('Open-Meteo responded with HTTP %d%s', $response->status(), is_string($reason) ? ': '.$reason : '.'));
        }

        return $response;
    }

    private function isTransient(Response $response): bool
    {
        return $response->serverError() || $response->tooManyRequests();
    }

    private function parse(Response $response): HourlySeries
    {
        /** @var mixed $body untrusted decoded JSON, checked below */
        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderException('Open-Meteo returned a body that is not a JSON object.');
        }

        if (($body['error'] ?? false) === true) {
            throw new ProviderException('Open-Meteo returned an error: '.($body['reason'] ?? 'no reason given'));
        }

        /** @var mixed $hourly */
        $hourly = $body['hourly'] ?? null;

        if (! is_array($hourly)) {
            throw new ProviderException('Open-Meteo response has no "hourly" block.');
        }

        /** @var mixed $times */
        $times = $hourly['time'] ?? null;

        if (! is_array($times) || ! array_is_list($times)) {
            throw new ProviderException('Open-Meteo response has no "time" list.');
        }

        foreach ($times as $time) {
            if (! is_int($time)) {
                throw new ProviderException(sprintf('Open-Meteo returned a non-numeric time "%s"; expected unixtime.', is_string($time) ? $time : get_debug_type($time)));
            }
        }

        foreach (array_keys(self::VARIABLES) as $variable) {
            /** @var mixed $values */
            $values = $hourly[$variable] ?? null;

            if (! is_array($values) || ! array_is_list($values) || count($values) !== count($times)) {
                throw new ProviderException(sprintf('Open-Meteo "%s" values are missing or do not match the %d hours.', $variable, count($times)));
            }

            /** @var mixed $unit */
            $unit = $body['hourly_units'][$variable] ?? null;

            if ($unit !== self::UNIT) {
                throw new ProviderException(sprintf('Open-Meteo "%s" unit is "%s", expected "%s".', $variable, is_string($unit) ? $unit : get_debug_type($unit), self::UNIT));
            }
        }

        /** @var list<HourlyReading> $readings */
        $readings = [];

        foreach ($times as $index => $time) {
            /** @var array<string, mixed> $values */
            $values = [];

            foreach (self::VARIABLES as $variable => $metric) {
                $values[$metric->value] = $hourly[$variable][$index];
            }

            $readings[] = new HourlyReading(CarbonImmutable::createFromTimestamp($time, 'UTC'), $values);
        }

        return new HourlySeries($readings);
    }
}
