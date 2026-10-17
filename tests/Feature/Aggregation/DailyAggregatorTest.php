<?php

namespace Tests\Feature\Aggregation;

use App\Aggregation\DailyAggregator;
use App\Data\Measurements;
use App\Data\StatusFlags;
use App\Models\AirQualityDaily;
use App\Models\AirQualityHourly;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\QualityMetric;
use Carbon\CarbonImmutable;
use Database\Seeders\QualityMetricSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider as TestWith;
use Tests\TestCase;

class DailyAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private DataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(QualityMetricSeeder::class);
        $this->city = City::factory()->create();
        $this->provider = DataProvider::factory()->create();
    }

    public function test_a_day_gets_the_sum_and_count_of_every_metric(): void
    {
        $this->hour('2026-07-15 05:00', ['pm25' => 10.0, 'pm10' => 20.0]);
        $this->hour('2026-07-15 06:00', ['pm25' => 12.5, 'pm10' => 30.25]);

        $written = $this->aggregateDay('2026-07-15');

        $this->assertSame(2, $written);
        $this->assertDaily(['2026-07-15' => ['pm25' => [22.5, 2], 'pm10' => [50.25, 2]]]);
    }

    public function test_values_of_all_providers_for_the_same_hour_are_added_up(): void
    {
        $second = DataProvider::factory()->create();
        $this->hour('2026-07-15 05:00', ['pm25' => 10.0]);
        $this->hour('2026-07-15 05:00', ['pm25' => 14.0], provider: $second);

        $this->aggregateDay('2026-07-15');

        $this->assertDaily(['2026-07-15' => ['pm25' => [24.0, 2]]]);
    }

    public function test_only_the_hours_that_have_a_metric_are_counted_for_it(): void
    {
        $this->hour('2026-07-15 05:00', ['pm25' => 10.0, 'pm10' => 20.0]);
        $this->hour('2026-07-15 07:00', ['pm25' => 11.0]);

        $this->aggregateDay('2026-07-15');

        $this->assertDaily(['2026-07-15' => ['pm25' => [21.0, 2], 'pm10' => [20.0, 1]]]);
    }

    public function test_a_metric_without_any_value_has_no_row_and_a_stale_one_is_deleted(): void
    {
        $this->storedDaily('2026-07-15', 'pm10', 99.0, 5);
        $this->hour('2026-07-15 05:00', ['pm25' => 10.0]);

        $written = $this->aggregateDay('2026-07-15');

        $this->assertSame(1, $written);
        $this->assertDaily(['2026-07-15' => ['pm25' => [10.0, 1]]]);
    }

    public function test_a_day_without_hourly_data_loses_its_rows(): void
    {
        $this->storedDaily('2026-07-15', 'pm25', 99.0, 5);

        $this->assertSame(0, $this->aggregateDay('2026-07-15'));
        $this->assertDaily([]);
    }

    public function test_statuses_do_not_affect_the_aggregation(): void
    {
        $inactive = StatusFlags::of();
        $this->city->update(['status' => $inactive]);
        $this->provider->update(['status' => $inactive]);
        QualityMetric::query()->update(['status' => 0]);
        $this->hour('2026-07-15 05:00', ['pm25' => 10.0, 'pm10' => 20.0]);

        $this->aggregateDay('2026-07-15');

        $this->assertDaily(['2026-07-15' => ['pm25' => [10.0, 1], 'pm10' => [20.0, 1]]]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function midnights(): array
    {
        return [
            'winter (UTC+2)' => ['2026-01-15', '2026-01-15 21:00', '2026-01-15 22:00'],
            'summer (UTC+3)' => ['2026-07-15', '2026-07-15 20:00', '2026-07-15 21:00'],
        ];
    }

    #[TestWith('midnights')]
    public function test_an_hour_belongs_to_the_local_day_it_starts_in(string $date, string $lastHour, string $nextDayHour): void
    {
        $this->hour($lastHour, ['pm25' => 10.0]);
        $this->hour($nextDayHour, ['pm25' => 50.0]);

        $this->aggregateDay($date);

        $this->assertDaily([$date => ['pm25' => [10.0, 1]]]);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function dstDays(): array
    {
        return [
            'switch to summer time' => ['2026-03-29', 23],
            'switch to winter time' => ['2026-10-25', 25],
        ];
    }

    #[TestWith('dstDays')]
    public function test_a_dst_day_collects_all_of_its_hours(string $date, int $hours): void
    {
        $day = CarbonImmutable::parse($date, 'UTC');

        // Every UTC hour from the day before to the day after, so the bounds decide what counts.
        for ($hour = $day->subDay(); $hour < $day->addDays(2); $hour = $hour->addHour()) {
            $this->hour($hour->toDateTimeString(), ['pm25' => 1.0]);
        }

        $this->aggregateDay($date);

        $this->assertDaily([$date => ['pm25' => [(float) $hours, $hours]]]);
    }

    public function test_aggregating_again_gives_the_same_rows(): void
    {
        $this->hour('2026-07-15 05:00', ['pm25' => 10.1, 'pm10' => 20.2]);
        $this->hour('2026-07-15 06:00', ['pm25' => 33.3]);

        $this->aggregateDay('2026-07-15');
        $first = $this->rows();
        $this->aggregateDay('2026-07-15');

        $this->assertSame($first, $this->rows());
    }

    public function test_other_cities_and_days_are_left_untouched(): void
    {
        $other = City::factory()->create();
        $this->storedDaily('2026-07-14', 'pm25', 1.0, 1);
        $this->storedDaily('2026-07-15', 'pm25', 2.0, 1, $other);
        $this->hour('2026-07-15 05:00', ['pm25' => 10.0]);
        $this->hour('2026-07-14 05:00', ['pm25' => 50.0]);
        $this->hour('2026-07-15 05:00', ['pm25' => 60.0], city: $other);

        $this->aggregateDay('2026-07-15');

        $this->assertDaily(['2026-07-14' => ['pm25' => [1.0, 1]], '2026-07-15' => ['pm25' => [10.0, 1]]]);
        $this->assertDaily(['2026-07-15' => ['pm25' => [2.0, 1]]], $other);
    }

    public function test_aggregate_covers_every_day_of_the_period_and_logs_it(): void
    {
        Log::spy();
        $this->hour('2026-07-14 05:00', ['pm25' => 10.0, 'pm10' => 20.0]);
        $this->hour('2026-07-16 05:00', ['pm25' => 30.0]);
        $this->hour('2026-07-17 05:00', ['pm25' => 99.0]);

        $result = app(DailyAggregator::class)->aggregate($this->city, CarbonImmutable::parse('2026-07-14'), CarbonImmutable::parse('2026-07-16'));

        $this->assertSame(3, $result->days);
        $this->assertSame(3, $result->rowsWritten);
        $this->assertDaily(['2026-07-14' => ['pm25' => [10.0, 1], 'pm10' => [20.0, 1]], '2026-07-16' => ['pm25' => [30.0, 1]]]);

        $matches = fn (array $context): bool => $context['city'] === $this->city->slug
            && $context['from'] === '2026-07-14'
            && $context['to'] === '2026-07-16';
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Daily aggregation started' && $matches($context))->once();
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Daily aggregation finished'
            && $matches($context)
            && $context['days'] === 3
            && $context['rows_written'] === 3
            && $context['duration_ms'] >= 0)->once();
    }

    /**
     * @param  array<string, float>  $values
     */
    private function hour(string $utc, array $values, ?City $city = null, ?DataProvider $provider = null): void
    {
        AirQualityHourly::factory()->create([
            'city_id' => ($city ?? $this->city)->id,
            'data_provider_id' => ($provider ?? $this->provider)->id,
            'measure_at' => $utc,
            'data' => Measurements::fromArray($values),
        ]);
    }

    private function storedDaily(string $date, string $metric, float $sum, int $count, ?City $city = null): void
    {
        AirQualityDaily::factory()->create([
            'date' => $date,
            'city_id' => ($city ?? $this->city)->id,
            'metric_id' => QualityMetric::where('slug', $metric)->value('id'),
            'metric_sum' => $sum,
            'metric_count' => $count,
        ]);
    }

    private function aggregateDay(string $date): int
    {
        return app(DailyAggregator::class)->aggregateDay($this->city->id, CarbonImmutable::parse($date));
    }

    /**
     * @param  array<string, array<string, array{float, int}>>  $expected  [date => [metric slug => [sum, count]]]
     */
    private function assertDaily(array $expected, ?City $city = null): void
    {
        /** @var array<string, array<string, array{float, int}>> $actual */
        $actual = [];

        foreach ($this->rows($city) as $row) {
            $actual[$row['date']][$row['metric']] = [(float) $row['metric_sum'], $row['metric_count']];
        }

        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($actual));

        foreach ($expected as $date => $metrics) {
            $this->assertEqualsCanonicalizing(array_keys($metrics), array_keys($actual[$date]), "Metrics of {$date}");

            foreach ($metrics as $metric => [$sum, $count]) {
                $this->assertEqualsWithDelta($sum, $actual[$date][$metric][0], 0.00001, "metric_sum of {$metric} on {$date}");
                $this->assertSame($count, $actual[$date][$metric][1], "metric_count of {$metric} on {$date}");
            }
        }
    }

    /**
     * @return list<array{date: string, metric: string, metric_sum: string, metric_count: int}>
     */
    private function rows(?City $city = null): array
    {
        return AirQualityDaily::with('metric')
            ->where('city_id', ($city ?? $this->city)->id)
            ->orderBy('date')
            ->orderBy('metric_id')
            ->get()
            ->map(fn (AirQualityDaily $row): array => [
                'date' => $row->date->toDateString(),
                'metric' => $row->metric->slug,
                'metric_sum' => $row->metric_sum,
                'metric_count' => $row->metric_count,
            ])
            ->all();
    }
}
