<?php

namespace Tests\Feature\Aggregation;

use App\Data\Measurements;
use App\Enums\Metric;
use App\Models\AirQualityDaily;
use App\Models\AirQualityHourly;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\QualityMetric;
use Database\Seeders\QualityMetricSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider as TestWith;
use Tests\TestCase;

class AggregateDailyCommandTest extends TestCase
{
    use RefreshDatabase;

    private City $sofia;

    private City $ruse;

    private DataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(QualityMetricSeeder::class);
        $this->sofia = City::factory()->create(['slug' => 'sofia']);
        $this->ruse = City::factory()->inactive()->create(['slug' => 'ruse']);
        $this->provider = DataProvider::factory()->create();
    }

    public function test_without_options_it_recomputes_the_whole_stored_period_of_every_city(): void
    {
        $this->storedDaily($this->sofia, '2026-07-10', 99.0);
        $this->hour($this->sofia, '2026-07-14 05:00', 10.0);
        $this->hour($this->sofia, '2026-07-15 05:00', 20.0);
        $this->hour($this->ruse, '2026-07-15 05:00', 30.0);

        $this->artisan('air-quality:aggregate')
            ->expectsOutputToContain('Aggregated 2 city(ies) over 6 day(s) (2026-07-10 to 2026-07-15): 3 daily row(s) written.')
            ->assertSuccessful();

        $this->assertSame(['2026-07-14' => 10.0, '2026-07-15' => 20.0], $this->daily($this->sofia));
        $this->assertSame(['2026-07-15' => 30.0], $this->daily($this->ruse));
    }

    public function test_from_and_to_limit_the_recomputed_days(): void
    {
        $this->storedDaily($this->sofia, '2026-07-10', 99.0);
        $this->hour($this->sofia, '2026-07-14 05:00', 10.0);
        $this->hour($this->sofia, '2026-07-15 05:00', 20.0);
        $this->hour($this->sofia, '2026-07-16 05:00', 30.0);

        $this->artisan('air-quality:aggregate', ['--from' => '2026-07-15', '--to' => '2026-07-16'])
            ->expectsOutputToContain('over 2 day(s) (2026-07-15 to 2026-07-16)')
            ->assertSuccessful();

        $this->assertSame(['2026-07-10' => 99.0, '2026-07-15' => 20.0, '2026-07-16' => 30.0], $this->daily($this->sofia));
    }

    /**
     * @return array<string, array{array<string, string>, string, array<string, float>}>
     */
    public static function openEnds(): array
    {
        return [
            'only --from' => [['--from' => '2026-07-15'], '(2026-07-15 to 2026-07-16)', ['2026-07-15' => 20.0, '2026-07-16' => 30.0]],
            'only --to' => [['--to' => '2026-07-15'], '(2026-07-14 to 2026-07-15)', ['2026-07-14' => 10.0, '2026-07-15' => 20.0]],
        ];
    }

    /**
     * @param  array<string, string>  $options
     * @param  array<string, float>  $expected
     */
    #[TestWith('openEnds')]
    public function test_a_missing_end_comes_from_the_stored_data(array $options, string $range, array $expected): void
    {
        $this->hour($this->sofia, '2026-07-14 05:00', 10.0);
        $this->hour($this->sofia, '2026-07-15 05:00', 20.0);
        $this->hour($this->sofia, '2026-07-16 05:00', 30.0);

        $this->artisan('air-quality:aggregate', $options)
            ->expectsOutputToContain($range)
            ->assertSuccessful();

        $this->assertSame($expected, $this->daily($this->sofia));
    }

    public function test_city_limits_the_recomputation_to_that_city_even_if_inactive(): void
    {
        $this->hour($this->sofia, '2026-07-15 05:00', 10.0);
        $this->hour($this->ruse, '2026-07-14 05:00', 20.0);
        $this->hour($this->ruse, '2026-07-15 05:00', 30.0);

        $this->artisan('air-quality:aggregate', ['--city' => 'ruse'])
            ->expectsOutputToContain('Aggregated 1 city(ies) over 2 day(s) (2026-07-14 to 2026-07-15): 2 daily row(s) written.')
            ->assertSuccessful();

        $this->assertSame([], $this->daily($this->sofia));
        $this->assertSame(['2026-07-14' => 20.0, '2026-07-15' => 30.0], $this->daily($this->ruse));
    }

    public function test_city_combines_with_from_and_to(): void
    {
        $this->hour($this->sofia, '2026-07-15 05:00', 10.0);
        $this->hour($this->ruse, '2026-07-14 05:00', 20.0);
        $this->hour($this->ruse, '2026-07-15 05:00', 30.0);

        $this->artisan('air-quality:aggregate', ['--city' => 'ruse', '--from' => '2026-07-15', '--to' => '2026-07-15'])
            ->expectsOutputToContain('Aggregated 1 city(ies) over 1 day(s) (2026-07-15 to 2026-07-15): 1 daily row(s) written.')
            ->assertSuccessful();

        $this->assertSame([], $this->daily($this->sofia));
        $this->assertSame(['2026-07-15' => 30.0], $this->daily($this->ruse));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidOptions(): array
    {
        return [
            'impossible date' => [['--from' => '2026-02-30'], '--from must be a date in the Y-m-d format, "2026-02-30" given.'],
            'other date format' => [['--to' => '17.10.2026'], '--to must be a date in the Y-m-d format, "17.10.2026" given.'],
            'not a date' => [['--from' => 'abc'], '--from must be a date in the Y-m-d format, "abc" given.'],
            'from after to' => [['--from' => '2026-07-16', '--to' => '2026-07-15'], '--from (2026-07-16) must not be after --to (2026-07-15).'],
            'unknown city' => [['--city' => 'atlantis'], 'Unknown city "atlantis".'],
        ];
    }

    /**
     * @param  array<string, string>  $options
     */
    #[TestWith('invalidOptions')]
    public function test_invalid_options_fail_without_changes(array $options, string $error): void
    {
        $this->storedDaily($this->sofia, '2026-07-15', 99.0);
        $this->hour($this->sofia, '2026-07-15 05:00', 10.0);

        $this->artisan('air-quality:aggregate', $options)
            ->expectsOutputToContain($error)
            ->assertFailed();

        $this->assertSame(['2026-07-15' => 99.0], $this->daily($this->sofia));
    }

    public function test_without_stored_data_there_is_nothing_to_aggregate(): void
    {
        $this->artisan('air-quality:aggregate')
            ->expectsOutputToContain('Nothing to aggregate')
            ->assertSuccessful();

        $this->assertSame(0, AirQualityDaily::count());
    }

    private function hour(City $city, string $utc, float $pm25): void
    {
        AirQualityHourly::factory()->create([
            'city_id' => $city->id,
            'data_provider_id' => $this->provider->id,
            'measure_at' => $utc,
            'data' => Measurements::empty()->with(Metric::Pm25, $pm25),
        ]);
    }

    private function storedDaily(City $city, string $date, float $pm25): void
    {
        AirQualityDaily::factory()->create([
            'date' => $date,
            'city_id' => $city->id,
            'metric_id' => QualityMetric::where('slug', Metric::Pm25->value)->value('id'),
            'metric_sum' => $pm25,
            'metric_count' => 1,
        ]);
    }

    /**
     * @return array<string, float> the city's PM2.5 sums keyed by date
     */
    private function daily(City $city): array
    {
        return AirQualityDaily::where('city_id', $city->id)
            ->orderBy('date')
            ->get()
            ->mapWithKeys(fn (AirQualityDaily $row): array => [$row->date->toDateString() => (float) $row->metric_sum])
            ->all();
    }
}
