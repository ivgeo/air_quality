<?php

namespace Tests\Feature\Ingestion;

use App\Enums\IngestionOutcome;
use App\Enums\IngestionType;
use App\Ingestion\Data\HourlyReading;
use App\Ingestion\Data\HourlySeries;
use App\Ingestion\Exceptions\ProviderException;
use App\Jobs\AggregateDailyData;
use App\Jobs\IngestHourlyData;
use App\Models\AirQualityDaily;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeAirQualityProvider;
use Tests\TestCase;

class IngestHourlyDataJobTest extends TestCase
{
    use RefreshDatabase;

    private DataProvider $provider;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-17 12:34:56');
        $this->provider = DataProvider::factory()->create(['slug' => 'fake']);
        $this->city = City::factory()->create();
    }

    public function test_runs_for_the_same_provider_and_city_never_overlap(): void
    {
        [$scheduled, $backfill] = [$this->job(IngestionType::Scheduled), $this->job(IngestionType::Backfill)];

        $middleware = $scheduled->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame($middleware[0]->key, $backfill->middleware()[0]->key);
        $this->assertSame("{$scheduled->provider->id}:{$scheduled->city->id}", $middleware[0]->key);
        // Released and retried later rather than dropped; the lock expires if a worker dies holding it.
        $this->assertGreaterThan(0, $middleware[0]->releaseAfter);
        $this->assertGreaterThan(0, $middleware[0]->expiresAfter);
    }

    public function test_only_one_job_per_type_provider_and_city_waits_in_the_queue(): void
    {
        $scheduled = $this->job(IngestionType::Scheduled);
        $backfill = $this->job(IngestionType::Backfill);

        $this->assertSame("scheduled:{$scheduled->provider->id}:{$scheduled->city->id}", $scheduled->uniqueId());
        $this->assertNotSame($scheduled->uniqueId(), $backfill->uniqueId());
    }

    public function test_the_timeout_comes_from_the_providers_job_timeout(): void
    {
        config(['air_quality.providers.fake' => ['class' => FakeAirQualityProvider::class, 'job_timeout' => 300]]);

        $job = $this->job(IngestionType::Backfill);

        $this->assertSame(300, $job->timeout);
        $this->assertSame(600, $job->middleware()[0]->expiresAfter);
    }

    public function test_the_timeout_defaults_to_80_seconds(): void
    {
        config(['air_quality.providers.fake' => ['class' => FakeAirQualityProvider::class]]);

        $this->assertSame(80, $this->job(IngestionType::Scheduled)->timeout);
    }

    public function test_every_job_timeout_stays_below_the_queues_retry_after(): void
    {
        $retryAfter = config('queue.connections.database.retry_after');

        foreach (config('air_quality.providers') as $slug => $entry) {
            $this->assertLessThan($retryAfter, $entry['job_timeout'] ?? IngestHourlyData::DEFAULT_TIMEOUT, "{$slug} job_timeout");
        }
    }

    public function test_a_successful_run_queues_the_aggregation_of_its_period(): void
    {
        Queue::fake([AggregateDailyData::class]);
        $this->respond(fn (): HourlySeries => new HourlySeries([new HourlyReading(CarbonImmutable::parse('2026-10-17 10:00', 'UTC'), ['pm25' => 12.3])]));

        IngestHourlyData::dispatch($this->provider, $this->city, IngestionType::Scheduled, CarbonImmutable::parse('2026-10-14 12:00'), CarbonImmutable::parse('2026-10-17 12:00'));

        $this->assertSame(IngestionOutcome::Success, IngestionRun::sole()->outcome);
        Queue::assertPushed(AggregateDailyData::class, 1);
        Queue::assertPushed(AggregateDailyData::class, fn (AggregateDailyData $job): bool => $job->city->is($this->city)
            && $job->from->toDateTimeString() === '2026-10-14 12:00:00'
            && $job->to->toDateTimeString() === '2026-10-17 12:00:00');
    }

    public function test_a_failed_run_does_not_queue_the_aggregation(): void
    {
        Queue::fake([AggregateDailyData::class]);
        $this->respond(fn (): never => throw new ProviderException('Open-Meteo responded with HTTP 502.'));

        IngestHourlyData::dispatch($this->provider, $this->city, IngestionType::Scheduled, CarbonImmutable::parse('2026-10-14 12:00'), CarbonImmutable::parse('2026-10-17 12:00'));

        $this->assertSame(IngestionOutcome::Failed, IngestionRun::sole()->outcome);
        Queue::assertNotPushed(AggregateDailyData::class);
        $this->assertSame(0, AirQualityDaily::count());
    }

    private function respond(Closure $handler): void
    {
        config(['air_quality.providers.fake' => ['class' => FakeAirQualityProvider::class, 'handler' => $handler]]);
    }

    private function job(IngestionType $type): IngestHourlyData
    {
        return new IngestHourlyData($this->provider, $this->city, $type, CarbonImmutable::now()->subDay(), CarbonImmutable::now());
    }
}
