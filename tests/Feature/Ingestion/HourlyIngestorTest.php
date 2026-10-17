<?php

namespace Tests\Feature\Ingestion;

use App\Data\Measurements;
use App\Enums\IngestionOutcome;
use App\Enums\IngestionType;
use App\Enums\Metric;
use App\Ingestion\Data\HourlyReading;
use App\Ingestion\Data\HourlySeries;
use App\Ingestion\Exceptions\ProviderException;
use App\Ingestion\HourlyIngestor;
use App\Models\AirQualityHourly;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeAirQualityProvider;
use Tests\TestCase;

class HourlyIngestorTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private DataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-17 12:34:56');
        $this->city = City::factory()->create();
        $this->provider = DataProvider::factory()->create(['slug' => 'fake']);
    }

    public function test_valid_hours_are_stored_in_utc_and_the_run_is_successful(): void
    {
        $this->respond(['2026-10-17 10:00' => ['pm25' => 12.3, 'pm10' => 20.1], '2026-10-17 11:00' => ['pm25' => 8.0, 'pm10' => null]]);

        $run = $this->ingest();

        $this->assertSame([
            '2026-10-17 10:00:00' => ['pm25' => 12.3, 'pm10' => 20.1],
            '2026-10-17 11:00:00' => ['pm25' => 8.0],
        ], $this->stored());

        $run->refresh();
        $this->assertSame(IngestionOutcome::Success, $run->outcome);
        $this->assertSame(IngestionType::Scheduled, $run->type);
        $this->assertTrue($run->city->is($this->city));
        $this->assertTrue($run->dataProvider->is($this->provider));
        $this->assertSame('2026-10-17 09:00:00', $run->period_from->toDateTimeString());
        $this->assertSame('2026-10-17 12:00:00', $run->period_to->toDateTimeString());
        $this->assertSame(2, $run->rows_written);
        $this->assertSame(0, $run->rows_rejected);
        $this->assertNull($run->error);
        $this->assertSame('2026-10-17 12:34:56', $run->started_at->toDateTimeString());
        $this->assertNotNull($run->finished_at);
        $this->assertGreaterThanOrEqual(0, $run->duration_ms);
    }

    public function test_hours_without_a_valid_value_are_counted_as_rejected_and_not_stored(): void
    {
        $this->respond(['2026-10-17 09:00' => ['pm25' => null, 'pm10' => -1], '2026-10-17 10:00' => ['pm25' => 'n/a'], '2026-10-17 11:00' => ['pm25' => 5.0]]);

        $run = $this->ingest()->refresh();

        $this->assertSame(['2026-10-17 11:00:00' => ['pm25' => 5.0]], $this->stored());
        $this->assertSame(IngestionOutcome::Success, $run->outcome);
        $this->assertSame(1, $run->rows_written);
        $this->assertSame(2, $run->rows_rejected);
    }

    public function test_future_hours_are_never_stored(): void
    {
        $this->respond(['2026-10-17 12:00' => ['pm25' => 1.0], '2026-10-17 13:00' => ['pm25' => 2.0], '2026-10-18 00:00' => ['pm25' => 3.0]]);

        $this->ingest(to: CarbonImmutable::parse('2026-10-18 00:00'));

        $this->assertSame(['2026-10-17 12:00:00' => ['pm25' => 1.0]], $this->stored());
        $this->assertSame(0, AirQualityHourly::where('measure_at', '>', now()->startOfHour())->count());
    }

    public function test_repeating_a_run_gives_the_same_rows(): void
    {
        $this->respond(['2026-10-17 10:00' => ['pm25' => 12.3], '2026-10-17 11:00' => ['pm25' => 8.0]]);

        $this->ingest();
        $first = $this->stored();
        $this->ingest();

        $this->assertSame($first, $this->stored());
        $this->assertSame(2, IngestionRun::where('outcome', IngestionOutcome::Success)->count());
    }

    public function test_a_revised_value_replaces_the_stored_one(): void
    {
        $this->respond(['2026-10-17 10:00' => ['pm25' => 12.3, 'pm10' => 20.0]]);
        $this->ingest();
        $id = AirQualityHourly::sole()->id;

        $this->respond(['2026-10-17 10:00' => ['pm25' => 14.0, 'pm10' => 21.0]]);
        $this->ingest();

        $this->assertSame(['2026-10-17 10:00:00' => ['pm25' => 14.0, 'pm10' => 21.0]], $this->stored());
        $this->assertSame($id, AirQualityHourly::sole()->id);
    }

    public function test_a_metric_missing_from_a_returned_hour_keeps_its_stored_value(): void
    {
        $this->respond(['2026-10-17 10:00' => ['pm25' => 12.3, 'pm10' => 20.0]]);
        $this->ingest();

        $this->respond(['2026-10-17 10:00' => ['pm25' => null, 'pm10' => 21.0]]);
        $this->ingest();

        $this->assertSame(['2026-10-17 10:00:00' => ['pm25' => 12.3, 'pm10' => 21.0]], $this->stored());
    }

    public function test_a_rejected_hour_keeps_its_stored_row(): void
    {
        $this->storeHour('2026-10-17 10:00', 1.0);
        $this->respond(['2026-10-17 09:00' => ['pm25' => 2.0], '2026-10-17 10:00' => ['pm25' => null, 'pm10' => -3], '2026-10-17 11:00' => ['pm25' => 4.0]]);

        $run = $this->ingest()->refresh();

        $this->assertSame([
            '2026-10-17 09:00:00' => ['pm25' => 2.0],
            '2026-10-17 10:00:00' => ['pm25' => 1.0],
            '2026-10-17 11:00:00' => ['pm25' => 4.0],
        ], $this->stored());
        $this->assertSame(1, $run->rows_rejected);
    }

    public function test_hours_the_provider_did_not_return_and_rows_of_other_cities_or_providers_are_kept(): void
    {
        $before = $this->storeHour('2026-10-17 09:00', 1.0);
        $after = $this->storeHour('2026-10-17 12:00', 2.0);
        $otherCity = $this->storeHour('2026-10-17 10:00', 3.0, city: City::factory()->create());
        $otherProvider = $this->storeHour('2026-10-17 10:00', 4.0, provider: DataProvider::factory()->create());
        $skipped = $this->storeHour('2026-10-17 11:00', 5.0);

        $this->respond(['2026-10-17 10:00' => ['pm25' => 6.0]]);
        $this->ingest();

        $this->assertModelExists($before);
        $this->assertModelExists($after);
        $this->assertModelExists($otherCity);
        $this->assertModelExists($otherProvider);
        $this->assertModelExists($skipped);
        $this->assertSame([
            '2026-10-17 09:00:00' => ['pm25' => 1.0],
            '2026-10-17 10:00:00' => ['pm25' => 6.0],
            '2026-10-17 11:00:00' => ['pm25' => 5.0],
            '2026-10-17 12:00:00' => ['pm25' => 2.0],
        ], $this->stored());
        $this->assertSame(['pm25' => 3.0], AirQualityHourly::where('city_id', '!=', $this->city->id)->sole()->data->toArray());
        $this->assertSame(['pm25' => 4.0], AirQualityHourly::where('data_provider_id', '!=', $this->provider->id)->sole()->data->toArray());
    }

    public function test_a_provider_failure_keeps_the_stored_rows_and_fails_the_run(): void
    {
        $this->storeHour('2026-10-17 10:00', 1.0);
        $this->respond(fn () => throw new ProviderException('Open-Meteo responded with HTTP 502.'));
        Log::spy();

        $run = $this->ingest()->refresh();

        $this->assertSame(['2026-10-17 10:00:00' => ['pm25' => 1.0]], $this->stored());
        $this->assertSame(IngestionOutcome::Failed, $run->outcome);
        $this->assertSame('Open-Meteo responded with HTTP 502.', $run->error);
        $this->assertSame(0, $run->rows_written);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_ms);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['error'] === 'Open-Meteo responded with HTTP 502.' && $context['city'] === $this->city->slug);
    }

    public function test_a_response_without_any_valid_hour_deletes_nothing_and_fails_the_run(): void
    {
        $this->storeHour('2026-10-17 10:00', 1.0);
        $this->respond(['2026-10-17 10:00' => ['pm25' => null, 'pm10' => null], '2026-10-17 11:00' => ['pm25' => null]]);

        $run = $this->ingest()->refresh();

        $this->assertSame(['2026-10-17 10:00:00' => ['pm25' => 1.0]], $this->stored());
        $this->assertSame(IngestionOutcome::Failed, $run->outcome);
        $this->assertSame(2, $run->rows_rejected);
        $this->assertStringContainsString('no valid hours', $run->error);
    }

    public function test_an_active_provider_without_config_logs_a_warning_and_fails_the_run(): void
    {
        $provider = DataProvider::factory()->create(['slug' => 'not-configured']);
        Log::spy();

        $run = app(HourlyIngestor::class)->ingest($provider, $this->city, IngestionType::Backfill, CarbonImmutable::now()->subDay(), CarbonImmutable::now())->refresh();

        $this->assertSame(IngestionOutcome::Failed, $run->outcome);
        $this->assertSame(IngestionType::Backfill, $run->type);
        $this->assertStringContainsString('"not-configured"', $run->error);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context['provider'] === 'not-configured');
        Log::shouldNotHaveReceived('error');
    }

    /**
     * @param  array<string, array<string, mixed>>|Closure  $hours  raw values keyed by 'Y-m-d H:i' (UTC), or a handler
     */
    private function respond(array|Closure $hours): void
    {
        $handler = $hours instanceof Closure ? $hours : fn () => new HourlySeries(array_map(
            fn (string $hour, array $values) => new HourlyReading(CarbonImmutable::parse($hour, 'UTC'), $values),
            array_keys($hours),
            $hours,
        ));

        config(['air_quality.providers.fake' => ['class' => FakeAirQualityProvider::class, 'handler' => $handler]]);
    }

    private function ingest(?CarbonImmutable $to = null): IngestionRun
    {
        return app(HourlyIngestor::class)->ingest(
            $this->provider,
            $this->city,
            IngestionType::Scheduled,
            CarbonImmutable::parse('2026-10-17 09:00'),
            $to ?? CarbonImmutable::parse('2026-10-17 12:00'),
        );
    }

    private function storeHour(string $hour, float $pm25, ?City $city = null, ?DataProvider $provider = null): AirQualityHourly
    {
        return AirQualityHourly::factory()->create([
            'city_id' => ($city ?? $this->city)->id,
            'data_provider_id' => ($provider ?? $this->provider)->id,
            'measure_at' => $hour,
            'data' => Measurements::empty()->with(Metric::Pm25, $pm25),
        ]);
    }

    /**
     * @return array<string, array<string, float>> this city's and provider's rows keyed by measure_at
     */
    private function stored(): array
    {
        return AirQualityHourly::where('city_id', $this->city->id)
            ->where('data_provider_id', $this->provider->id)
            ->orderBy('measure_at')
            ->get()
            ->mapWithKeys(fn (AirQualityHourly $row) => [$row->measure_at->toDateTimeString() => $row->data->toArray()])
            ->all();
    }
}
