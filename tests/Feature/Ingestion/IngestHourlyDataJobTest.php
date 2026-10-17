<?php

namespace Tests\Feature\Ingestion;

use App\Enums\IngestionType;
use App\Jobs\IngestHourlyData;
use App\Models\City;
use App\Models\DataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class IngestHourlyDataJobTest extends TestCase
{
    use RefreshDatabase;

    private DataProvider $provider;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = DataProvider::factory()->create();
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

    private function job(IngestionType $type): IngestHourlyData
    {
        return new IngestHourlyData($this->provider, $this->city, $type, CarbonImmutable::now()->subDay(), CarbonImmutable::now());
    }
}
