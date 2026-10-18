<?php

namespace Tests\Feature\Ingestion;

use App\Ingestion\Data\HourlySeries;
use App\Ingestion\Exceptions\ProviderException;
use App\Ingestion\Providers\OpenAq\OpenAqProvider;
use App\Ingestion\Providers\OpenAq\OpenAqState;
use App\Models\City;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\InMemoryProviderStateStore;
use Tests\TestCase;

class OpenAqProviderTest extends TestCase
{
    private const string LOCATIONS = 'https://api.openaq.org/v3/locations*';

    private InMemoryProviderStateStore $store;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-18 12:30:00');
        Sleep::fake(syncWithCarbon: true);
        $this->store = new InMemoryProviderStateStore(OpenAqState::class);
        $this->city = City::factory()->make(['slug' => 'sofia', 'latitude' => 42.69751, 'longitude' => 23.32415]);
    }

    public function test_stations_are_discovered_by_radius_among_bulgarian_reference_stations(): void
    {
        Http::fake([
            self::LOCATIONS => Http::response(self::locations([self::location(1, 'Sofia - Hipodruma', [101 => 'pm25'])])),
            '*' => Http::response(self::hours([])),
        ]);

        $this->fetch();

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), 'https://api.openaq.org/v3/locations?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['coordinates'] === '42.69751,23.32415'
                && $query['radius'] === '15000'
                && $query['iso'] === 'BG'
                && $query['monitor'] === 'true';
        });
    }

    public function test_only_active_bulgarian_reference_sensors_of_known_metrics_are_kept(): void
    {
        Http::fake([
            self::LOCATIONS => Http::response(self::locations([
                self::location(1, 'Sofia - Hipodruma', [101 => 'pm25', 102 => 'pm10', 103 => 'no2']),
                self::location(2, 'Low-cost sensor', [201 => 'pm25'], isMonitor: false),
                self::location(3, 'Giurgiu', [301 => 'pm25'], country: 'RO'),
                self::location(4, 'Closed station', [401 => 'pm25'], last: '2026-10-01T10:00:00Z'),
                self::location(5, 'Never measured', [501 => 'pm25'], last: null),
            ])),
            '*' => Http::response(self::hours([])),
        ]);

        $this->fetch();

        $this->assertSame(
            [['id' => 101, 'metric' => 'pm25', 'location' => 'Sofia - Hipodruma'], ['id' => 102, 'metric' => 'pm10', 'location' => 'Sofia - Hipodruma']],
            $this->store->data['cities']['sofia']['sensors'],
        );
        $this->assertSame('2026-10-18T12:30:00+00:00', $this->store->data['cities']['sofia']['discovered_at']);
        Http::assertSentCount(3);
        foreach ([103, 201, 301, 401, 501] as $sensor) {
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), "/sensors/{$sensor}/"));
        }
    }

    public function test_the_sensors_of_a_city_are_averaged_per_hour_and_metric(): void
    {
        $this->knownSensors([101 => 'pm25', 102 => 'pm25', 103 => 'pm10']);
        Http::fake([
            '*/sensors/101/hours*' => Http::response(self::hours([self::hour('08:00', 10), self::hour('09:00', 12.5)])),
            '*/sensors/102/hours*' => Http::response(self::hours([self::hour('08:00', 20)])),
            '*/sensors/103/hours*' => Http::response(self::hours([self::hour('09:00', 30)])),
        ]);

        $series = $this->fetch();

        $this->assertSame(
            ['2026-10-18 08:00' => ['pm25' => 15.0], '2026-10-18 09:00' => ['pm25' => 12.5, 'pm10' => 30.0]],
            self::byHour($series),
        );
        $this->assertSame('UTC', $series->readings[0]->measureAt->timezoneName);
    }

    public function test_null_flagged_and_negative_values_take_no_part_in_the_average(): void
    {
        $this->knownSensors([101 => 'pm25', 102 => 'pm25', 103 => 'pm10']);
        Http::fake([
            '*/sensors/101/hours*' => Http::response(self::hours([self::hour('08:00', 10), self::hour('09:00', null), self::hour('10:00', 99, flagged: true)])),
            '*/sensors/102/hours*' => Http::response(self::hours([self::hour('08:00', -5), self::hour('09:00', 14)])),
            '*/sensors/103/hours*' => Http::response(self::hours([self::hour('10:00', 25)])),
        ]);

        $this->assertSame(
            ['2026-10-18 08:00' => ['pm25' => 10.0], '2026-10-18 09:00' => ['pm25' => 14.0], '2026-10-18 10:00' => ['pm10' => 25.0]],
            self::byHour($this->fetch()),
        );
    }

    public function test_an_hour_without_any_valid_sensor_is_left_out(): void
    {
        $this->knownSensors([101 => 'pm25']);
        Http::fake(['*/sensors/101/hours*' => Http::response(self::hours([self::hour('08:00', null), self::hour('09:00', 3)]))]);

        $this->assertSame(['2026-10-18 09:00' => ['pm25' => 3.0]], self::byHour($this->fetch()));
    }

    public function test_a_city_with_pm10_stations_only_gets_pm10_hours(): void
    {
        Http::fake([
            self::LOCATIONS => Http::response(self::locations([self::location(1, 'Burgas - Dolno Ezerovo', [111 => 'pm10'])])),
            '*/sensors/111/hours*' => Http::response(self::hours([self::hour('08:00', 40)])),
        ]);

        $this->assertSame(['2026-10-18 08:00' => ['pm10' => 40.0]], self::byHour($this->fetch()));
    }

    public function test_a_city_without_stations_gets_no_hours_and_is_not_looked_up_again(): void
    {
        Http::fake([self::LOCATIONS => Http::response(self::locations([]))]);

        $this->assertSame([], $this->fetch()->readings);
        $this->assertSame([], $this->fetch()->readings);

        Http::assertSentCount(1);
        $this->assertSame([], $this->store->data['cities']['sofia']['sensors']);
    }

    public function test_the_period_is_requested_with_an_hour_of_margin_on_each_side(): void
    {
        $this->knownSensors([101 => 'pm25']);
        Http::fake(['*' => Http::response(self::hours([]))]);

        $this->fetch();

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://api.openaq.org/v3/sensors/101/hours?')
                && $query === ['datetime_from' => '2026-10-18T07:00:00Z', 'datetime_to' => '2026-10-18T11:00:00Z', 'limit' => '1000', 'page' => '1'];
        });
    }

    public function test_a_full_page_asks_for_the_next_one(): void
    {
        $this->knownSensors([101 => 'pm25']);
        Http::fake(['*/sensors/101/hours*' => Http::sequence()
            ->push(self::hours(self::hourRange(CarbonImmutable::parse('2026-09-01 00:00', 'UTC'), 1000)))
            ->push(self::hours([self::hour('08:00', 5)]))]);

        $series = $this->fetch();

        $this->assertCount(1001, $series->readings);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page=2'));
    }

    public function test_more_pages_than_allowed_fail_the_run(): void
    {
        $this->knownSensors([101 => 'pm25']);
        Http::fake(['*/sensors/101/hours*' => Http::response(self::hours(self::hourRange(CarbonImmutable::parse('2026-09-01 00:00', 'UTC'), 1000)))]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('pages');

        try {
            $this->fetch(['max_pages' => 2]);
        } finally {
            Http::assertSentCount(2);
        }
    }

    /**
     * @param  array<mixed>  $body
     */
    #[DataProvider('malformedHours')]
    public function test_a_malformed_hours_response_fails_the_run(array $body): void
    {
        $this->knownSensors([101 => 'pm25']);
        Http::fake(['*/sensors/101/hours*' => Http::response($body)]);

        $this->expectException(ProviderException::class);

        $this->fetch();
    }

    public static function malformedHours(): array
    {
        $hour = self::hour('08:00', 5);
        unset($hour['period']);

        return [
            'no results' => [['meta' => []]],
            'results not a list' => [['results' => ['a' => self::hour('08:00', 5)]]],
            'no period' => [self::hours([$hour])],
            'bad time' => [self::hours([self::hour('08:00', 5, from: 'yesterday')])],
            'another unit' => [self::hours([self::hour('08:00', 5, units: 'ppm')])],
            'no parameter' => [self::hours([array_diff_key(self::hour('08:00', 5), ['parameter' => true])])],
        ];
    }

    public function test_the_greek_mu_spelling_of_the_unit_is_accepted(): void
    {
        $this->knownSensors([101 => 'pm25']);
        Http::fake(['*/sensors/101/hours*' => Http::response(self::hours([self::hour('08:00', 5, units: "\u{03BC}g/m\u{00B3}")]))]);

        $this->assertSame(['2026-10-18 08:00' => ['pm25' => 5.0]], self::byHour($this->fetch()));
    }

    public function test_a_malformed_locations_response_fails_the_run(): void
    {
        Http::fake([self::LOCATIONS => Http::response(['results' => 'none'])]);

        $this->expectException(ProviderException::class);

        $this->fetch();
    }

    public function test_known_stations_are_reused_within_the_ttl_and_rediscovered_after_it(): void
    {
        Http::fake([
            self::LOCATIONS => Http::response(self::locations([self::location(1, 'Sofia - Hipodruma', [101 => 'pm25'])])),
            '*' => Http::response(self::hours([])),
        ]);

        $this->fetch();
        CarbonImmutable::setTestNow('2026-10-19 12:29:59');
        $this->fetch();
        $this->assertSame(1, $this->locationRequests());

        CarbonImmutable::setTestNow('2026-10-19 12:30:00');
        $this->fetch();
        $this->assertSame(2, $this->locationRequests());
        $this->assertSame('2026-10-19T12:30:00+00:00', $this->store->data['cities']['sofia']['discovered_at']);
    }

    public function test_a_failed_rediscovery_keeps_using_the_last_known_stations(): void
    {
        $this->knownSensors([101 => 'pm25'], discoveredAt: '2026-10-16T09:00:00+00:00');
        Http::fake([
            self::LOCATIONS => Http::response(['detail' => 'down'], 503),
            '*/sensors/101/hours*' => Http::response(self::hours([self::hour('08:00', 7)])),
        ]);

        $this->assertSame(['2026-10-18 08:00' => ['pm25' => 7.0]], self::byHour($this->fetch()));
        $this->assertSame('2026-10-16T09:00:00+00:00', $this->store->data['cities']['sofia']['discovered_at']);
    }

    public function test_a_failed_first_discovery_fails_the_run(): void
    {
        Http::fake([self::LOCATIONS => Http::response(['detail' => 'down'], 503)]);

        $this->expectException(ProviderException::class);

        try {
            $this->fetch();
        } finally {
            $this->assertSame([], $this->store->data);
        }
    }

    public function test_other_cities_keep_their_stations(): void
    {
        $this->store->data = ['cities' => ['varna' => ['discovered_at' => '2026-10-18T10:00:00+00:00', 'sensors' => []]]];
        Http::fake([self::LOCATIONS => Http::response(self::locations([]))]);

        $this->fetch();

        $this->assertEqualsCanonicalizing(['varna', 'sofia'], array_keys($this->store->data['cities']));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function fetch(array $config = []): HourlySeries
    {
        $provider = new OpenAqProvider([...config('air_quality.providers.openaq'), 'api_key' => 'test-key', ...$config], $this->store);

        return $provider->fetchHourly($this->city, CarbonImmutable::parse('2026-10-18 08:00', 'UTC'), CarbonImmutable::parse('2026-10-18 10:00', 'UTC'));
    }

    /**
     * @param  array<int, string>  $sensors  sensor id => metric
     */
    private function knownSensors(array $sensors, string $discoveredAt = '2026-10-18T10:00:00+00:00'): void
    {
        $this->store->data = ['cities' => ['sofia' => [
            'discovered_at' => $discoveredAt,
            'sensors' => array_map(fn (int $id, string $metric): array => ['id' => $id, 'metric' => $metric, 'location' => 'Sofia'], array_keys($sensors), $sensors),
        ]]];
    }

    private function locationRequests(): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/v3/locations'))->count();
    }

    /**
     * @return array<string, array<string, float>>
     */
    private static function byHour(HourlySeries $series): array
    {
        /** @var array<string, array<string, float>> $hours */
        $hours = [];

        foreach ($series->readings as $reading) {
            $hours[$reading->measureAt->format('Y-m-d H:i')] = $reading->values;
        }

        return $hours;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private static function locations(array $results): array
    {
        return ['meta' => ['name' => 'openaq-api', 'page' => 1, 'limit' => 100, 'found' => count($results)], 'results' => $results];
    }

    /**
     * @param  array<int, string>  $sensors  sensor id => OpenAQ parameter name
     * @return array<string, mixed>
     */
    private static function location(int $id, string $name, array $sensors, bool $isMonitor = true, string $country = 'BG', ?string $last = '2026-10-18T11:00:00Z'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'country' => ['id' => 1, 'code' => $country, 'name' => $country],
            'isMonitor' => $isMonitor,
            'sensors' => array_map(fn (int $sensor, string $parameter): array => [
                'id' => $sensor,
                'name' => "{$parameter} µg/m³",
                'parameter' => ['id' => 2, 'name' => $parameter, 'units' => 'µg/m³', 'displayName' => strtoupper($parameter)],
            ], array_keys($sensors), $sensors),
            'datetimeLast' => $last === null ? null : ['utc' => $last, 'local' => $last],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private static function hours(array $results): array
    {
        return ['meta' => ['name' => 'openaq-api', 'limit' => 1000, 'found' => count($results)], 'results' => $results];
    }

    /**
     * @return array<string, mixed>
     */
    private static function hour(string $time, int|float|null $value, bool $flagged = false, string $units = 'µg/m³', ?string $from = null): array
    {
        $start = CarbonImmutable::parse("2026-10-18 {$time}", 'UTC');

        return [
            'value' => $value,
            'flagInfo' => ['hasFlags' => $flagged],
            'parameter' => ['id' => 2, 'name' => 'pm25', 'units' => $units, 'displayName' => null],
            'period' => [
                'label' => '1hour',
                'interval' => '01:00:00',
                'datetimeFrom' => ['utc' => $from ?? $start->format('Y-m-d\TH:i:s\Z'), 'local' => $start->setTimezone('Europe/Sofia')->toIso8601String()],
                'datetimeTo' => ['utc' => $start->addHour()->format('Y-m-d\TH:i:s\Z'), 'local' => $start->addHour()->setTimezone('Europe/Sofia')->toIso8601String()],
            ],
            'coverage' => ['expectedCount' => 1, 'observedCount' => 1, 'percentComplete' => 100.0],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function hourRange(CarbonImmutable $start, int $count): array
    {
        return array_map(fn (int $i): array => self::hour('00:00', 5, from: $start->addHours($i)->format('Y-m-d\TH:i:s\Z')), range(0, $count - 1));
    }
}
