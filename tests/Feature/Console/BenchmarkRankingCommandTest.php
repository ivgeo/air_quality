<?php

namespace Tests\Feature\Console;

use App\Enums\Metric;
use App\Models\AirQualityDaily;
use App\Models\AirQualityHourly;
use App\Models\City;
use App\Models\IngestionRun;
use App\Models\QualityMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A small volume (3 cities × 40 days) keeps the run fast; the default volume is only measured.
 */
class BenchmarkRankingCommandTest extends TestCase
{
    use RefreshDatabase;

    private const array SMALL = ['--cities' => 3, '--days' => 40, '--repeat' => 1];

    protected function setUp(): void
    {
        parent::setUp();

        // 00:30 on 2026-10-19 in Europe/Sofia, still 2026-10-18 in UTC.
        $this->travelTo('2026-10-18 21:30:00');
    }

    public function test_it_generates_every_city_day_and_metric_ending_today_in_local_time(): void
    {
        $this->artisan('air-quality:benchmark', self::SMALL)
            ->expectsOutputToContain('Generated 3 cities × 40 days × 2 metrics = 240 daily rows (seed 42)')
            ->assertSuccessful();

        $this->assertSame(240, AirQualityDaily::query()->count());
        $this->assertSame(['bench-001', 'bench-002', 'bench-003'], City::query()->where('is_active', 1)->orderBy('slug')->pluck('slug')->all());

        /** @var object{first: string, last: string, days: int} $range */
        $range = AirQualityDaily::query()->toBase()->selectRaw('MIN(date) AS first, MAX(date) AS last, COUNT(DISTINCT date) AS days')->first();
        $this->assertSame(['2026-09-10', '2026-10-19', 40], [$range->first, $range->last, (int) $range->days]);
        $this->assertSame(0, AirQualityDaily::query()->where('metric_count', '!=', 24)->count());
    }

    public function test_a_second_run_with_the_same_seed_regenerates_the_same_data_without_piling_up(): void
    {
        $this->artisan('air-quality:benchmark', self::SMALL)->assertSuccessful();
        $first = $this->sums();

        $this->artisan('air-quality:benchmark', self::SMALL)->assertSuccessful();

        $this->assertSame(240, AirQualityDaily::query()->count());
        $this->assertSame(3, City::query()->count());
        $this->assertSame($first, $this->sums());
    }

    public function test_another_seed_generates_other_values(): void
    {
        $this->artisan('air-quality:benchmark', self::SMALL)->assertSuccessful();
        $first = $this->sums();

        $this->artisan('air-quality:benchmark', [...self::SMALL, '--seed' => 7])
            ->expectsOutputToContain('(seed 7)')
            ->assertSuccessful();

        $this->assertNotSame($first, $this->sums());
    }

    public function test_previous_data_is_cleared_and_the_generated_data_is_kept(): void
    {
        $old = City::factory()->create(['slug' => 'sofia']);
        AirQualityHourly::factory()->create(['city_id' => $old->id]);
        AirQualityDaily::factory()->create(['city_id' => $old->id]);
        IngestionRun::factory()->create(['city_id' => $old->id]);

        $this->artisan('air-quality:benchmark', self::SMALL)
            ->expectsOutputToContain('The synthetic data stays in air_quality_test')
            ->assertSuccessful();

        $this->assertFalse(City::query()->where('slug', 'sofia')->exists());
        $this->assertSame(0, AirQualityHourly::query()->count());
        $this->assertSame(0, IngestionRun::query()->count());
        $this->assertSame(240, AirQualityDaily::query()->count());
    }

    public function test_it_prints_the_plans_the_sql_times_and_the_endpoint_median_and_skips_the_longer_periods(): void
    {
        $this->artisan('air-quality:benchmark', self::SMALL)
            ->expectsOutputToContain('1 day (2026-10-19 – 2026-10-19)')
            ->expectsOutputToContain('30 days (2026-09-20 – 2026-10-19)')
            ->expectsOutputToContain('EXPLAIN ANALYZE storedPeriods')
            ->expectsOutputToContain('EXPLAIN ANALYZE rank')
            ->expectsOutputToContain('-> ')
            ->expectsOutputToContain('idx_air_quality_daily_metric_date_city')
            ->expectsOutputToContain('Endpoint')
            ->expectsOutputToContain('182 days: skipped, longer than the 40 generated days')
            ->expectsOutputToContain('1826 days: skipped, longer than the 40 generated days')
            ->assertSuccessful();
    }

    public function test_the_in_process_requests_record_no_api_metrics(): void
    {
        $this->artisan('air-quality:benchmark', self::SMALL)->assertSuccessful();

        $this->assertSame([], $this->metricsStore()->recorded);
    }

    /**
     * @return array<string, array{array<string, int|string>, string}>
     */
    public static function invalidOptions(): array
    {
        return [
            'no cities' => [['--cities' => 0], '--cities must be a positive integer, "0" given.'],
            'no days' => [['--days' => 0], '--days must be a positive integer, "0" given.'],
            'no repeats' => [['--repeat' => 'ten'], '--repeat must be a positive integer, "ten" given.'],
            'negative seed' => [['--seed' => -1], '--seed must be a positive integer, "-1" given.'],
        ];
    }

    /**
     * @param  array<string, int|string>  $options
     */
    #[DataProvider('invalidOptions')]
    public function test_invalid_options_fail_before_anything_is_written(array $options, string $error): void
    {
        $city = City::factory()->create();

        $this->artisan('air-quality:benchmark', [...self::SMALL, ...$options])
            ->expectsOutputToContain($error)
            ->assertFailed();

        $this->assertModelExists($city);
        $this->assertSame(1, City::query()->count());
    }

    /**
     * A DB_URL wins over the database name the command switches to, so the actual database is
     * checked. information_schema is read-only, so even a broken guard could not write to it.
     */
    public function test_it_refuses_to_run_when_the_connection_does_not_reach_the_test_database(): void
    {
        /** @var array{host: string, port: string, username: string, password: string} $config */
        $config = config('database.connections.mysql');
        config(['database.connections.mysql.url' => sprintf('mysql://%s:%s@%s:%s/information_schema', $config['username'], $config['password'], $config['host'], $config['port'])]);
        DB::purge('mysql');

        $this->artisan('air-quality:benchmark', self::SMALL)
            ->expectsOutputToContain('Expected the "air_quality_test" database, but "information_schema" is connected.')
            ->assertFailed();
    }

    /**
     * @return array<string, string>
     */
    private function sums(): array
    {
        $metric = QualityMetric::query()->where('slug', Metric::Pm25->value)->firstOrFail();

        /** @var array<string, string> $sums */
        $sums = AirQualityDaily::query()
            ->join('city', 'city.id', '=', 'air_quality_daily.city_id')
            ->where('metric_id', $metric->id)
            ->whereIn('date', ['2026-09-10', '2026-10-19'])
            ->orderBy('city.slug')
            ->orderBy('date')
            ->get(['city.slug', 'date', 'metric_sum'])
            ->mapWithKeys(fn (AirQualityDaily $row): array => ["{$row->getAttribute('slug')} {$row->date->toDateString()}" => (string) $row->metric_sum])
            ->all();

        return $sums;
    }
}
