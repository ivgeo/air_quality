<?php

namespace Tests\Feature\Api;

use App\Enums\Metric;
use App\Models\AirQualityDaily;
use App\Models\City;
use App\Models\QualityMetric;
use Database\Seeders\QualityMetricSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RecordApiMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(QualityMetricSeeder::class);
        $this->travelTo('2026-07-20 12:00:00');
        AirQualityDaily::factory()->create([
            'date' => '2026-07-01',
            'city_id' => City::factory()->create()->id,
            'metric_id' => QualityMetric::where('slug', Metric::Pm25->value)->value('id'),
            'metric_sum' => 10.0,
            'metric_count' => 1,
        ]);
    }

    public function test_a_successful_api_request_is_recorded_with_its_status_and_duration(): void
    {
        $this->ranking()->assertOk();

        $this->assertCount(1, $this->metricsStore()->recorded);
        $this->assertSame(200, $this->metricsStore()->recorded[0]['status']);
        $this->assertGreaterThanOrEqual(0, $this->metricsStore()->recorded[0]['duration_us']);
    }

    public function test_a_validation_error_is_counted_as_a_4xx_error(): void
    {
        $this->ranking('2026-07-02', '2026-07-01')->assertUnprocessable();

        $snapshot = $this->metricsStore()->snapshot();
        $this->assertSame(1, $snapshot->requests);
        $this->assertSame(1, $snapshot->byStatus['4xx']);
        $this->assertSame(1, $snapshot->errors());
    }

    public function test_the_metrics_endpoint_is_logged_but_not_counted(): void
    {
        Log::spy();

        $this->getJson('/api/v1/metrics')->assertOk();

        $this->assertSame([], $this->metricsStore()->recorded);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'API request' && $context['route'] === 'api.v1.metrics')->once();
    }

    public function test_every_api_request_is_logged_with_its_context(): void
    {
        Log::spy();

        $this->ranking('2026-07-02', '2026-07-01');

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'API request'
            && $context['method'] === 'GET'
            && $context['path'] === 'api/v1/ranking'
            && $context['route'] === 'api.v1.ranking'
            && $context['status'] === 422
            && is_float($context['duration_ms'])
            && $context['duration_ms'] >= 0)->once();
    }

    public function test_an_unavailable_store_leaves_the_response_unchanged_and_logs_a_warning(): void
    {
        $expected = $this->ranking()->assertOk()->json();
        $this->metricsStore()->available = false;
        Log::spy();

        $this->ranking()->assertOk()->assertExactJson($expected);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'API metrics not recorded' && isset($context['error']))->once();
    }

    public function test_web_requests_are_not_recorded(): void
    {
        $this->get('/')->assertOk();
        $this->getJson('/health');

        $this->assertSame([], $this->metricsStore()->recorded);
    }

    private function ranking(string $from = '2026-07-01', string $to = '2026-07-01'): TestResponse
    {
        return $this->getJson('/api/v1/ranking?'.http_build_query(['from' => $from, 'to' => $to]));
    }
}
