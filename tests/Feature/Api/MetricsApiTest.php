<?php

namespace Tests\Feature\Api;

use App\Models\City;
use App\Models\DataProvider;
use App\Models\IngestionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MetricsApiTest extends TestCase
{
    use RefreshDatabase;

    private DataProvider $provider;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-18 12:00:00');
        $this->provider = DataProvider::factory()->create(['slug' => 'open-meteo', 'name' => 'Open-Meteo']);
        $this->city = City::factory()->create(['name' => 'Sofia']);
    }

    public function test_the_totals_and_the_last_24_hours_match_the_runs(): void
    {
        $this->ingestionRun(['started_at' => '2026-10-18 11:00:00', 'rows_written' => 72, 'rows_rejected' => 2, 'duration_ms' => 1000]);
        $this->ingestionRun(['started_at' => '2026-10-17 13:00:00', 'rows_written' => 70, 'rows_rejected' => 0, 'duration_ms' => 3000], 'failed');
        $this->ingestionRun(['started_at' => '2026-10-18 11:59:00'], 'running');
        // Started 25 hours ago: only in the totals.
        $this->ingestionRun(['started_at' => '2026-10-17 11:00:00', 'rows_written' => 10, 'rows_rejected' => 5, 'duration_ms' => 500]);

        $this->metrics()
            ->assertOk()
            ->assertJsonPath('data.generated_at', '2026-10-18T12:00:00+00:00')
            ->assertJsonPath('data.ingestion.total', [
                'runs' => ['success' => 2, 'failed' => 1, 'running' => 1],
                'rows_written' => 82,
                'rows_rejected' => 7,
                'avg_duration_ms' => 1500,
            ])
            ->assertJsonPath('data.ingestion.last_24h', [
                'runs' => ['success' => 1, 'failed' => 1, 'running' => 1],
                'rows_written' => 72,
                'rows_rejected' => 2,
                'avg_duration_ms' => 2000,
            ]);
    }

    public function test_a_run_started_23_hours_ago_is_in_both_blocks(): void
    {
        $this->ingestionRun(['started_at' => '2026-10-17 13:00:00']);

        $this->metrics()
            ->assertJsonPath('data.ingestion.total.runs.success', 1)
            ->assertJsonPath('data.ingestion.last_24h.runs.success', 1);
    }

    public function test_an_empty_journal_gives_zeros(): void
    {
        $empty = ['runs' => ['success' => 0, 'failed' => 0, 'running' => 0], 'rows_written' => 0, 'rows_rejected' => 0, 'avg_duration_ms' => null];

        $this->metrics()
            ->assertOk()
            ->assertJsonPath('data.ingestion.total', $empty)
            ->assertJsonPath('data.ingestion.last_24h', $empty)
            ->assertJsonPath('data.ingestion.last_run_by_provider', [])
            ->assertJsonPath('data.ingestion.recent_failures', []);
    }

    public function test_the_last_run_of_each_provider_is_listed(): void
    {
        $other = DataProvider::factory()->create(['slug' => 'other', 'name' => 'Other']);
        $this->ingestionRun(['started_at' => '2026-10-18 10:00:00']);
        $this->ingestionRun(['started_at' => '2026-10-18 11:00:00', 'rows_written' => 5, 'rows_rejected' => 1, 'duration_ms' => 2000], 'failed');
        $this->ingestionRun(['data_provider_id' => $other->id, 'started_at' => '2026-10-18 09:00:00', 'rows_written' => 3, 'rows_rejected' => 0, 'duration_ms' => 1000]);

        $this->metrics()->assertJsonPath('data.ingestion.last_run_by_provider', [
            [
                'provider' => ['slug' => 'open-meteo', 'name' => 'Open-Meteo'],
                'city' => ['slug' => $this->city->slug, 'name' => 'Sofia'],
                'outcome' => 'failed',
                'started_at' => '2026-10-18T11:00:00+00:00',
                'finished_at' => '2026-10-18T11:00:02+00:00',
                'rows_written' => 0,
                'rows_rejected' => 1,
            ],
            [
                'provider' => ['slug' => 'other', 'name' => 'Other'],
                'city' => ['slug' => $this->city->slug, 'name' => 'Sofia'],
                'outcome' => 'success',
                'started_at' => '2026-10-18T09:00:00+00:00',
                'finished_at' => '2026-10-18T09:00:01+00:00',
                'rows_written' => 3,
                'rows_rejected' => 0,
            ],
        ]);
    }

    public function test_the_five_newest_failures_are_listed_newest_first(): void
    {
        foreach (range(1, 6) as $hour) {
            $this->ingestionRun(['started_at' => sprintf('2026-10-18 %02d:00:00', $hour), 'error' => "Failure {$hour}"], 'failed');
        }
        $this->ingestionRun(['started_at' => '2026-10-18 11:00:00']);

        $response = $this->metrics();

        $this->assertSame(['Failure 6', 'Failure 5', 'Failure 4', 'Failure 3', 'Failure 2'], $response->json('data.ingestion.recent_failures.*.error'));
        $response->assertJsonPath('data.ingestion.recent_failures.0', [
            'provider' => ['slug' => 'open-meteo', 'name' => 'Open-Meteo'],
            'city' => ['slug' => $this->city->slug, 'name' => 'Sofia'],
            'started_at' => '2026-10-18T06:00:00+00:00',
            'error' => 'Failure 6',
        ]);
    }

    public function test_the_api_block_comes_from_the_metrics_store(): void
    {
        $store = $this->metricsStore();
        $store->record(200, 10_000);
        $store->record(422, 4_000);
        $store->record(500, 31_000);

        $this->metrics()->assertJsonPath('data.api', [
            'requests' => 3,
            'errors' => 2,
            'by_status' => ['2xx' => 1, '3xx' => 0, '4xx' => 1, '5xx' => 1],
            'avg_latency_ms' => 15,
            'max_latency_ms' => 31,
        ]);
    }

    public function test_an_unavailable_metrics_store_gives_a_null_api_block_and_a_warning(): void
    {
        $this->metricsStore()->available = false;
        $this->ingestionRun(['started_at' => '2026-10-18 11:00:00']);
        Log::spy();

        $this->metrics()
            ->assertOk()
            ->assertJsonPath('data.api', null)
            ->assertJsonPath('data.ingestion.total.runs.success', 1);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'API metrics unavailable' && isset($context['error']))->once();
    }

    public function test_the_metrics_never_call_the_provider(): void
    {
        $this->metrics()->assertOk();

        Http::assertNothingSent();
    }

    private function metrics(): TestResponse
    {
        return $this->getJson('/api/v1/metrics');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  'success'|'failed'|'running'  $state
     */
    private function ingestionRun(array $attributes, string $state = 'success'): void
    {
        IngestionRun::factory()->{$state}()->create([
            'data_provider_id' => $this->provider->id,
            'city_id' => $this->city->id,
            ...$attributes,
        ]);
    }
}
