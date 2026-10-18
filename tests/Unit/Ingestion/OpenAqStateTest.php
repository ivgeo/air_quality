<?php

namespace Tests\Unit\Ingestion;

use App\Enums\Metric;
use App\Ingestion\Providers\OpenAq\CityStations;
use App\Ingestion\Providers\OpenAq\OpenAqState;
use App\Ingestion\Providers\OpenAq\Sensor;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OpenAqStateTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_stations_survive_a_round_trip(): void
    {
        $stations = new CityStations(CarbonImmutable::parse('2026-10-18 10:00', 'UTC'), [
            new Sensor(101, Metric::Pm25, 'Sofia - Hipodruma'),
            new Sensor(102, Metric::Pm10, 'Sofia - Hipodruma'),
        ]);

        $data = ['cities' => ['sofia' => $stations->toArray(), 'burgas' => (new CityStations(CarbonImmutable::parse('2026-10-18 11:00', 'UTC'), []))->toArray()]];
        $state = OpenAqState::fromArray(json_decode(json_encode($data), true));

        $this->assertEquals($stations, $state->city('sofia'));
        $this->assertSame([], $state->city('burgas')->sensors);
        $this->assertNull($state->city('varna'));
        $this->assertSame($data, $state->toArray());
        $this->assertSame(
            ['discovered_at' => '2026-10-18T10:00:00+00:00', 'sensors' => [
                ['id' => 101, 'metric' => 'pm25', 'location' => 'Sofia - Hipodruma'],
                ['id' => 102, 'metric' => 'pm10', 'location' => 'Sofia - Hipodruma'],
            ]],
            $stations->toArray(),
        );
    }

    public function test_an_empty_state_has_no_cities(): void
    {
        $state = OpenAqState::fromArray([]);

        $this->assertNull($state->city('sofia'));
        $this->assertSame(['cities' => []], $state->toArray());
    }

    /**
     * @param  array<mixed>  $city
     */
    #[DataProvider('invalidCities')]
    public function test_a_city_with_missing_or_invalid_fields_is_not_discovered(mixed $city): void
    {
        $state = OpenAqState::fromArray(['cities' => ['sofia' => $city, 'varna' => self::valid()]]);

        $this->assertNull($state->city('sofia'));
        $this->assertNotNull($state->city('varna'));
    }

    public static function invalidCities(): array
    {
        $sensor = ['id' => 101, 'metric' => 'pm25', 'location' => 'Varna'];

        return [
            'not an object' => ['sofia'],
            'no discovered_at' => [['sensors' => [$sensor]]],
            'bad discovered_at' => [['discovered_at' => 'yesterday-ish', 'sensors' => [$sensor]]],
            'no sensors' => [['discovered_at' => '2026-10-18T10:00:00+00:00']],
            'sensors not a list' => [['discovered_at' => '2026-10-18T10:00:00+00:00', 'sensors' => ['a' => $sensor]]],
            'sensor without id' => [['discovered_at' => '2026-10-18T10:00:00+00:00', 'sensors' => [['metric' => 'pm25', 'location' => 'Varna']]]],
            'sensor with a string id' => [['discovered_at' => '2026-10-18T10:00:00+00:00', 'sensors' => [[...$sensor, 'id' => '101']]]],
            'unknown metric' => [['discovered_at' => '2026-10-18T10:00:00+00:00', 'sensors' => [[...$sensor, 'metric' => 'no2']]]],
            'sensor without location' => [['discovered_at' => '2026-10-18T10:00:00+00:00', 'sensors' => [['id' => 101, 'metric' => 'pm25']]]],
        ];
    }

    public function test_cities_that_are_not_an_object_read_as_none(): void
    {
        $this->assertNull(OpenAqState::fromArray(['cities' => 'sofia'])->city('sofia'));
    }

    public function test_stations_are_stale_once_older_than_the_ttl(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-19 10:00', 'UTC'));

        $this->assertFalse((new CityStations(CarbonImmutable::parse('2026-10-18 10:00:01', 'UTC'), []))->isStale(24));
        $this->assertTrue((new CityStations(CarbonImmutable::parse('2026-10-18 10:00', 'UTC'), []))->isStale(24));
    }

    /**
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        return ['discovered_at' => '2026-10-18T10:00:00+00:00', 'sensors' => [['id' => 7, 'metric' => 'pm10', 'location' => 'Varna']]];
    }
}
