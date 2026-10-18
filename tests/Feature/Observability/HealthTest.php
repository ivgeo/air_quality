<?php

namespace Tests\Feature\Observability;

use App\Data\StatusFlags;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    private DataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-18 12:00:00');
        $this->provider = DataProvider::factory()->create(['slug' => 'open-meteo', 'name' => 'Open-Meteo']);
    }

    public function test_fresh_data_and_a_reachable_database_are_ok(): void
    {
        $this->finishedRun($this->provider, '2026-10-18 11:00:00');

        $this->health()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checked_at', '2026-10-18T12:00:00+00:00')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.freshness.threshold_hours', 3)
            ->assertJsonPath('checks.freshness.providers', [[
                'provider' => 'open-meteo',
                'name' => 'Open-Meteo',
                'status' => 'ok',
                'last_success_at' => '2026-10-18T11:00:00+00:00',
            ]])
            ->assertJsonPath('checks.metrics_store.status', 'ok');
    }

    public function test_a_provider_whose_last_success_is_older_than_the_threshold_is_stale(): void
    {
        $this->finishedRun($this->provider, '2026-10-18 08:00:00');

        $this->health()
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.freshness.providers.0.status', 'stale')
            ->assertJsonPath('checks.freshness.providers.0.last_success_at', '2026-10-18T08:00:00+00:00');
    }

    public function test_a_provider_without_any_successful_run_is_stale(): void
    {
        IngestionRun::factory()->failed()->create(['data_provider_id' => $this->provider->id, 'started_at' => '2026-10-18 11:30:00']);
        IngestionRun::factory()->running()->create(['data_provider_id' => $this->provider->id, 'started_at' => '2026-10-18 11:55:00']);

        $this->health()
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.freshness.providers.0.status', 'stale')
            ->assertJsonPath('checks.freshness.providers.0.last_success_at', null);
    }

    public function test_a_success_exactly_at_the_threshold_is_still_fresh(): void
    {
        $this->finishedRun($this->provider, '2026-10-18 09:00:00');

        $this->health()->assertJsonPath('status', 'ok');
    }

    public function test_the_threshold_comes_from_the_config(): void
    {
        config(['air_quality.health.freshness_hours' => 1]);
        $this->finishedRun($this->provider, '2026-10-18 10:30:00');

        $this->health()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.freshness.threshold_hours', 1);
    }

    public function test_an_inactive_provider_is_ignored(): void
    {
        $this->finishedRun($this->provider, '2026-10-18 11:00:00');
        $inactive = DataProvider::factory()->create(['status' => StatusFlags::of()]);
        $this->finishedRun($inactive, '2026-10-01 11:00:00');

        $this->health()
            ->assertJsonPath('status', 'ok')
            ->assertJsonCount(1, 'checks.freshness.providers');
    }

    public function test_one_stale_provider_of_two_degrades_the_status_and_is_the_only_stale_one(): void
    {
        $this->finishedRun($this->provider, '2026-10-18 11:00:00');
        $late = DataProvider::factory()->create(['slug' => 'late']);
        $this->finishedRun($late, '2026-10-17 23:00:00');

        $this->health()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.freshness.providers.0.provider', 'open-meteo')
            ->assertJsonPath('checks.freshness.providers.0.status', 'ok')
            ->assertJsonPath('checks.freshness.providers.1.provider', 'late')
            ->assertJsonPath('checks.freshness.providers.1.status', 'stale');
    }

    public function test_freshness_is_judged_per_provider_not_per_city(): void
    {
        $this->finishedRun($this->provider, '2026-10-18 11:00:00');
        $this->finishedRun($this->provider, '2026-10-10 11:00:00', City::factory()->create());

        $this->health()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.freshness.providers.0.last_success_at', '2026-10-18T11:00:00+00:00');
    }

    public function test_an_unavailable_metrics_store_is_reported_without_changing_the_status(): void
    {
        $this->finishedRun($this->provider, '2026-10-18 11:00:00');
        $this->metricsStore()->available = false;

        $this->health()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.metrics_store.status', 'unavailable');
    }

    public function test_the_health_check_never_calls_the_provider(): void
    {
        $this->health()->assertOk();

        Http::assertNothingSent();
    }

    private function health(): TestResponse
    {
        return $this->getJson('/health');
    }

    private function finishedRun(DataProvider $provider, string $finishedAt, ?City $city = null): void
    {
        IngestionRun::factory()->success()->create([
            'data_provider_id' => $provider->id,
            'city_id' => ($city ?? City::factory()->create())->id,
            'started_at' => CarbonImmutable::parse($finishedAt)->subSecond(),
            'duration_ms' => 1000,
        ]);
    }
}
