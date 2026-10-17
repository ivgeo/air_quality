<?php

namespace Tests\Feature\Api;

use App\Enums\Metric;
use App\Models\AirQualityDaily;
use App\Models\City;
use App\Models\QualityMetric;
use Database\Seeders\QualityMetricSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RankingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(QualityMetricSeeder::class);
        $this->travelTo('2026-07-20 12:00:00');
    }

    public function test_cities_are_ranked_from_the_lowest_to_the_highest_average(): void
    {
        $sofia = $this->city('Sofia');
        $varna = $this->city('Varna');
        $plovdiv = $this->city('Plovdiv');
        $this->daily($sofia, '2026-07-01', 30.0, 1);
        $this->daily($varna, '2026-07-01', 10.0, 1);
        $this->daily($plovdiv, '2026-07-01', 20.0, 1);

        $response = $this->ranking('2026-07-01', '2026-07-01')->assertOk();

        $this->assertSame(['Varna', 'Plovdiv', 'Sofia'], $response->json('data.*.city.name'));
        $this->assertSame([1, 2, 3], $response->json('data.*.rank'));
        $response->assertJsonPath('data.0.city.slug', $varna->slug);
        $this->assertEquals(10.0, $response->json('data.0.average'));
    }

    public function test_the_average_is_weighted_by_the_number_of_measurements(): void
    {
        $city = $this->city('Sofia');
        $this->daily($city, '2026-07-01', 10.0, 1);
        $this->daily($city, '2026-07-02', 200.0, 10);

        $this->ranking('2026-07-01', '2026-07-02')
            ->assertOk()
            ->assertJsonPath('data.0.average', 19.09)
            ->assertJsonPath('data.0.measurements', 11)
            ->assertJsonPath('data.0.days_with_data', 2);
    }

    public function test_cities_with_an_equal_average_are_ordered_by_name(): void
    {
        $this->daily($this->city('Varna'), '2026-07-01', 24.0, 2);
        $this->daily($this->city('Burgas'), '2026-07-01', 36.0, 3);
        $this->daily($this->city('Ruse'), '2026-07-01', 12.0, 1);

        $response = $this->ranking('2026-07-01', '2026-07-01')->assertOk();

        $this->assertSame(['Burgas', 'Ruse', 'Varna'], $response->json('data.*.city.name'));
    }

    public function test_the_average_is_rounded_to_two_decimals(): void
    {
        $this->daily($this->city('Sofia'), '2026-07-01', 10.0, 3);

        $this->ranking('2026-07-01', '2026-07-01')->assertJsonPath('data.0.average', 3.33);
    }

    public function test_pm25_is_the_default_metric_and_another_one_can_be_requested(): void
    {
        $sofia = $this->city('Sofia');
        $varna = $this->city('Varna');
        $this->daily($sofia, '2026-07-01', 10.0, 1);
        $this->daily($varna, '2026-07-01', 20.0, 1);
        $this->daily($sofia, '2026-07-01', 40.0, 1, Metric::Pm10);
        $this->daily($varna, '2026-07-01', 30.0, 1, Metric::Pm10);

        $default = $this->ranking('2026-07-01', '2026-07-01')->assertOk();
        $pm10 = $this->ranking('2026-07-01', '2026-07-01', 'pm10')->assertOk();

        $this->assertSame(['Sofia', 'Varna'], $default->json('data.*.city.name'));
        $default->assertJsonPath('meta.metric', ['slug' => 'pm25', 'label' => 'PM2.5', 'unit' => 'µg/m³']);
        $this->assertSame(['Varna', 'Sofia'], $pm10->json('data.*.city.name'));
        $this->assertEquals([30.0, 40.0], $pm10->json('data.*.average'));
        $pm10->assertJsonPath('meta.metric', ['slug' => 'pm10', 'label' => 'PM10', 'unit' => 'µg/m³']);
    }

    public function test_an_inactive_city_is_left_out_and_does_not_widen_the_stored_period(): void
    {
        $this->daily($this->city('Sofia'), '2026-07-01', 10.0, 1);
        $inactive = City::factory()->inactive()->create(['name' => 'Ruse']);
        $this->daily($inactive, '2026-06-01', 5.0, 1);
        $this->daily($inactive, '2026-07-01', 5.0, 1);

        $response = $this->ranking('2026-07-01', '2026-07-01')->assertOk();

        $this->assertSame(['Sofia'], $response->json('data.*.city.name'));
        $response->assertJsonPath('meta.stored', ['from' => '2026-07-01', 'to' => '2026-07-01']);
    }

    public function test_an_active_city_without_data_in_the_period_is_left_out(): void
    {
        $this->daily($this->city('Sofia'), '2026-07-02', 10.0, 1);
        $varna = $this->city('Varna');
        $this->daily($varna, '2026-07-01', 10.0, 1);
        $this->daily($varna, '2026-07-03', 10.0, 1);

        $response = $this->ranking('2026-07-02', '2026-07-02')->assertOk();

        $this->assertSame(['Sofia'], $response->json('data.*.city.name'));
    }

    public function test_a_gap_in_the_stored_data_gives_an_empty_ranking(): void
    {
        $city = $this->city('Sofia');
        $this->daily($city, '2026-07-01', 10.0, 1);
        $this->daily($city, '2026-07-10', 10.0, 1);

        $this->ranking('2026-07-04', '2026-07-06')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.period', ['from' => '2026-07-04', 'to' => '2026-07-06', 'days' => 3])
            ->assertJsonPath('meta.metric.slug', 'pm25')
            ->assertJsonPath('meta.stored', ['from' => '2026-07-01', 'to' => '2026-07-10']);
    }

    public function test_a_city_whose_data_starts_later_takes_part_with_the_days_it_has(): void
    {
        $sofia = $this->city('Sofia');
        $ruse = $this->city('Ruse');
        foreach (['2026-04-14', '2026-04-20', '2026-05-01', '2026-05-02'] as $date) {
            $this->daily($sofia, $date, 20.0, 1);
        }
        foreach (['2026-05-01', '2026-05-02'] as $date) {
            $this->daily($ruse, $date, 10.0, 1);
        }

        $response = $this->ranking('2026-04-20', '2026-05-02')->assertOk();

        $this->assertSame(['Ruse', 'Sofia'], $response->json('data.*.city.name'));
        $response->assertJsonPath('data.0.days_with_data', 2)
            ->assertJsonPath('data.0.stored', ['from' => '2026-05-01', 'to' => '2026-05-02'])
            ->assertJsonPath('data.1.days_with_data', 3)
            ->assertJsonPath('data.1.stored', ['from' => '2026-04-14', 'to' => '2026-05-02'])
            ->assertJsonPath('meta.stored', ['from' => '2026-04-14', 'to' => '2026-05-02']);
    }

    public function test_a_single_day_period_is_valid(): void
    {
        $this->daily($this->city('Sofia'), '2026-07-01', 10.0, 1);

        $this->ranking('2026-07-01', '2026-07-01')
            ->assertOk()
            ->assertJsonPath('meta.period', ['from' => '2026-07-01', 'to' => '2026-07-01', 'days' => 1]);
    }

    public function test_today_in_the_local_timezone_is_allowed_when_it_has_data(): void
    {
        $this->travelTo('2026-07-15 21:30:00');
        $this->daily($this->city('Sofia'), '2026-07-16', 10.0, 1);

        $this->ranking('2026-07-16', '2026-07-16')
            ->assertOk()
            ->assertJsonPath('data.0.city.name', 'Sofia');
    }

    public function test_the_response_has_the_entry_fields_and_the_request_metadata(): void
    {
        $city = $this->city('Sofia');
        $this->daily($city, '2026-06-30', 12.0, 2);
        $this->daily($city, '2026-07-01', 24.0, 4);
        $this->daily($city, '2026-07-05', 6.0, 1);

        $this->ranking('2026-07-01', '2026-07-05')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'rank' => 1,
                    'city' => ['slug' => $city->slug, 'name' => 'Sofia'],
                    'average' => 6.0,
                    'measurements' => 5,
                    'days_with_data' => 2,
                    'stored' => ['from' => '2026-06-30', 'to' => '2026-07-05'],
                ]],
                'meta' => [
                    'period' => ['from' => '2026-07-01', 'to' => '2026-07-05', 'days' => 5],
                    'metric' => ['slug' => 'pm25', 'label' => 'PM2.5', 'unit' => 'µg/m³'],
                    'stored' => ['from' => '2026-06-30', 'to' => '2026-07-05'],
                ],
            ]);
    }

    public function test_the_provider_is_never_called(): void
    {
        Http::fake();
        $this->daily($this->city('Sofia'), '2026-07-01', 10.0, 1);

        $this->ranking('2026-07-01', '2026-07-01')->assertOk();

        Http::assertNothingSent();
    }

    public function test_only_daily_data_is_read_with_the_same_number_of_queries_for_any_period_length(): void
    {
        $this->travelTo('2026-10-17 12:00:00');
        foreach (['Sofia', 'Plovdiv', 'Varna'] as $name) {
            $city = $this->city($name);
            $this->daily($city, '2026-04-20', 10.0, 1);
            $this->daily($city, '2026-10-16', 10.0, 1);
        }

        $oneDay = $this->recordQueries(fn (): TestResponse => $this->ranking('2026-10-16', '2026-10-16')->assertOk());
        $halfYear = $this->recordQueries(fn (): TestResponse => $this->ranking('2026-04-20', '2026-10-16')->assertOk());

        $this->assertCount(count($oneDay), $halfYear);
        foreach ([...$oneDay, ...$halfYear] as $sql) {
            $this->assertStringNotContainsString('air_quality_hourly', $sql);
        }
    }

    private function city(string $name): City
    {
        return City::factory()->create(['name' => $name]);
    }

    private function daily(City $city, string $date, float $sum, int $count, Metric $metric = Metric::Pm25): void
    {
        AirQualityDaily::factory()->create([
            'date' => $date,
            'city_id' => $city->id,
            'metric_id' => QualityMetric::where('slug', $metric->value)->value('id'),
            'metric_sum' => $sum,
            'metric_count' => $count,
        ]);
    }

    private function ranking(string $from, string $to, ?string $metric = null): TestResponse
    {
        return $this->getJson('/api/v1/ranking?'.http_build_query(['from' => $from, 'to' => $to, 'metric' => $metric]));
    }

    /**
     * @param  callable(): TestResponse  $request
     * @return list<string>
     */
    private function recordQueries(callable $request): array
    {
        /** @var list<string> $queries */
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $request();

        return $queries;
    }
}
