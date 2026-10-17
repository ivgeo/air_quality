<?php

namespace Tests\Feature\Aggregation;

use App\Data\Measurements;
use App\Enums\Metric;
use App\Jobs\AggregateDailyData;
use App\Models\AirQualityDaily;
use App\Models\AirQualityHourly;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Database\Seeders\QualityMetricSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class AggregateDailyDataJobTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(QualityMetricSeeder::class);
        $this->city = City::factory()->create();
    }

    public function test_jobs_for_the_same_city_never_overlap(): void
    {
        $middleware = $this->job()->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame("aggregate:{$this->city->id}", $middleware[0]->key);
        // Released and retried later rather than dropped; the lock expires if a worker dies holding it.
        $this->assertGreaterThan(0, $middleware[0]->releaseAfter);
        $this->assertGreaterThan(0, $middleware[0]->expiresAfter);
    }

    public function test_it_finishes_before_the_queue_hands_it_out_again(): void
    {
        $this->assertLessThan(config('queue.connections.database.retry_after'), $this->job()->timeout);
    }

    public function test_it_aggregates_every_local_day_of_the_utc_period(): void
    {
        $provider = DataProvider::factory()->create();

        foreach (['2026-07-13 20:00', '2026-07-13 21:00', '2026-07-15 12:00', '2026-07-16 20:00', '2026-07-16 21:00'] as $hour) {
            AirQualityHourly::factory()->create([
                'city_id' => $this->city->id,
                'data_provider_id' => $provider->id,
                'measure_at' => $hour,
                'data' => Measurements::empty()->with(Metric::Pm25, 10.0),
            ]);
        }

        // 2026-07-14 00:00 to 2026-07-16 23:00 in Europe/Sofia.
        AggregateDailyData::dispatch($this->city, CarbonImmutable::parse('2026-07-13 21:00', 'UTC'), CarbonImmutable::parse('2026-07-16 20:00', 'UTC'));

        $this->assertSame(
            ['2026-07-14' => 1, '2026-07-15' => 1, '2026-07-16' => 1],
            AirQualityDaily::orderBy('date')->get()->mapWithKeys(fn (AirQualityDaily $row): array => [$row->date->toDateString() => $row->metric_count])->all(),
        );
        $this->assertSame(0, IngestionRun::count());
    }

    public function test_a_failed_job_is_logged_with_its_context(): void
    {
        Log::spy();

        $this->job()->failed(new RuntimeException('Deadlock found'));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'Daily aggregation failed'
            && $context['city'] === $this->city->slug
            && $context['from'] === '2026-07-13 21:00:00'
            && $context['to'] === '2026-07-16 20:00:00'
            && $context['error'] === 'Deadlock found')->once();
        $this->assertSame(0, IngestionRun::count());
    }

    private function job(): AggregateDailyData
    {
        return new AggregateDailyData($this->city, CarbonImmutable::parse('2026-07-13 21:00', 'UTC'), CarbonImmutable::parse('2026-07-16 20:00', 'UTC'));
    }
}
