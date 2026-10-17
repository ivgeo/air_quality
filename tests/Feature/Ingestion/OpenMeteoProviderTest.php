<?php

namespace Tests\Feature\Ingestion;

use App\Ingestion\Data\HourlySeries;
use App\Ingestion\Exceptions\ProviderException;
use App\Ingestion\Providers\OpenMeteoProvider;
use App\Models\City;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenMeteoProviderTest extends TestCase
{
    private const FROM = 1792224000; // 2026-10-17 08:00 UTC

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_it_requests_pm_values_for_the_city_and_period_in_utc(): void
    {
        Http::fake(['*' => Http::response(self::body())]);

        $this->fetch();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://air-quality-api.open-meteo.com/v1/air-quality?')
                && $request->method() === 'GET'
                && $query === [
                    'latitude' => '42.69751',
                    'longitude' => '23.32415',
                    'hourly' => 'pm2_5,pm10',
                    'domains' => 'cams_europe',
                    'timezone' => 'GMT',
                    'timeformat' => 'unixtime',
                    'start_hour' => '2026-10-17T08:00',
                    'end_hour' => '2026-10-17T10:00',
                ];
        });
    }

    public function test_it_maps_hours_to_utc_and_metric_keys_keeping_raw_values(): void
    {
        Http::fake(['*' => Http::response(self::body(pm25: [12.3, null, 0], pm10: [20.1, 18, 'x']))]);

        $series = $this->fetch();

        $this->assertCount(3, $series->readings);
        $this->assertSame('2026-10-17 08:00:00 UTC', $series->readings[0]->measureAt->format('Y-m-d H:i:s T'));
        $this->assertSame('2026-10-17 10:00:00', $series->readings[2]->measureAt->format('Y-m-d H:i:s'));
        $this->assertSame(['pm25' => 12.3, 'pm10' => 20.1], $series->readings[0]->values);
        $this->assertSame(['pm25' => null, 'pm10' => 18], $series->readings[1]->values);
        $this->assertSame(['pm25' => 0, 'pm10' => 'x'], $series->readings[2]->values);
    }

    public function test_a_timeout_is_retried_and_then_reported(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

        try {
            $this->fetch();
            $this->fail('No ProviderException was thrown.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_a_server_error_is_retried_and_then_reported(): void
    {
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        $this->expectExceptionObject(new ProviderException('Open-Meteo responded with HTTP 502.'));

        try {
            $this->fetch();
        } finally {
            Http::assertSentCount(3);
        }
    }

    public function test_a_temporary_failure_recovers_on_retry(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('Unavailable', 503)
            ->push(['error' => true, 'reason' => 'Too many requests'], 429)
            ->push(self::body())]);

        $this->assertCount(3, $this->fetch()->readings);
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_a_client_error_is_not_retried_and_reports_the_reason(): void
    {
        Http::fake(['*' => Http::response(['error' => true, 'reason' => 'Parameter start_hour is out of range'], 400)]);

        $this->expectExceptionObject(new ProviderException('Open-Meteo responded with HTTP 400: Parameter start_hour is out of range'));

        try {
            $this->fetch();
        } finally {
            Http::assertSentCount(1);
        }
    }

    #[DataProvider('malformedBodies')]
    public function test_a_response_of_an_unexpected_shape_is_reported(mixed $body, string $message): void
    {
        Http::fake(['*' => Http::response($body)]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage($message);

        $this->fetch();
    }

    public static function malformedBodies(): array
    {
        $valid = self::body();

        return [
            'not json' => ['<html>oops</html>', 'not a JSON object'],
            'error flag with 200' => [['error' => true, 'reason' => 'Boom'], 'Boom'],
            'no hourly block' => [array_diff_key($valid, ['hourly' => true]), '"hourly"'],
            'no time list' => [array_replace_recursive($valid, ['hourly' => ['time' => 'x']]), '"time"'],
            'missing metric' => [self::without($valid, 'pm10'), '"pm10"'],
            'length mismatch' => [array_replace($valid, ['hourly' => [...$valid['hourly'], 'pm2_5' => [1.0]]]), '"pm2_5"'],
            'non-numeric time' => [array_replace($valid, ['hourly' => [...$valid['hourly'], 'time' => ['2026-10-17T08:00', 1, 2]]]), 'time'],
            'wrong unit' => [array_replace_recursive($valid, ['hourly_units' => ['pm2_5' => 'ppm']]), 'unit'],
        ];
    }

    /**
     * @param  list<mixed>|null  $pm25
     * @param  list<mixed>|null  $pm10
     * @return array<string, mixed>
     */
    private static function body(?array $pm25 = null, ?array $pm10 = null): array
    {
        return [
            'latitude' => 42.7,
            'longitude' => 23.3,
            'utc_offset_seconds' => 0,
            'timezone' => 'GMT',
            'hourly_units' => ['time' => 'unixtime', 'pm2_5' => 'μg/m³', 'pm10' => 'μg/m³'],
            'hourly' => [
                'time' => [self::FROM, self::FROM + 3600, self::FROM + 7200],
                'pm2_5' => $pm25 ?? [10.0, 11.0, 12.0],
                'pm10' => $pm10 ?? [20.0, 21.0, 22.0],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function without(array $body, string $metric): array
    {
        unset($body['hourly'][$metric]);

        return $body;
    }

    private function fetch(): HourlySeries
    {
        $city = City::factory()->make(['latitude' => 42.69751, 'longitude' => 23.32415]);
        $from = CarbonImmutable::createFromTimestampUTC(self::FROM);

        return (new OpenMeteoProvider(config('air_quality.providers.open-meteo')))->fetchHourly($city, $from, $from->addHours(2));
    }
}
