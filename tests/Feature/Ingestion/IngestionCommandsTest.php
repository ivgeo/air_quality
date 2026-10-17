<?php

namespace Tests\Feature\Ingestion;

use App\Data\StatusFlags;
use App\Enums\IngestionOutcome;
use App\Enums\IngestionType;
use App\Jobs\IngestHourlyData;
use App\Models\AirQualityHourly;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider as TestWith;
use Tests\TestCase;

class IngestionCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-17 12:34:56');
    }

    public function test_ingest_queues_the_last_days_for_every_active_provider_and_city(): void
    {
        Queue::fake();
        [$providers, $cities] = $this->activeAndInactive();

        $this->artisan('air-quality:ingest')
            ->expectsOutputToContain('Dispatched 4 ingestion job(s)')
            ->assertSuccessful();

        Queue::assertPushed(IngestHourlyData::class, 4);
        $this->assertPushedPairs($providers, $cities);
        Queue::assertPushed(IngestHourlyData::class, fn (IngestHourlyData $job) => $job->type === IngestionType::Scheduled
            && $job->from->toDateTimeString() === '2026-10-14 12:00:00'
            && $job->to->toDateTimeString() === '2026-10-17 12:00:00');
    }

    public function test_backfill_queues_the_configured_history_by_default(): void
    {
        Queue::fake();
        [$providers, $cities] = $this->activeAndInactive();

        $this->artisan('air-quality:backfill')->assertSuccessful();

        Queue::assertPushed(IngestHourlyData::class, 4);
        $this->assertPushedPairs($providers, $cities);
        Queue::assertPushed(IngestHourlyData::class, fn (IngestHourlyData $job) => $job->type === IngestionType::Backfill
            && $job->from->toDateTimeString() === '2026-04-14 12:00:00'
            && $job->to->toDateTimeString() === '2026-10-17 12:00:00');
    }

    public function test_backfill_accepts_the_number_of_days(): void
    {
        Queue::fake();
        $this->activeAndInactive();

        $this->artisan('air-quality:backfill', ['--days' => 10])->assertSuccessful();

        Queue::assertPushed(IngestHourlyData::class, fn (IngestHourlyData $job) => $job->from->toDateTimeString() === '2026-10-07 12:00:00');
    }

    #[TestWith('invalidDays')]
    public function test_backfill_rejects_an_invalid_number_of_days(string $days): void
    {
        Queue::fake();
        $this->activeAndInactive();

        $this->artisan('air-quality:backfill', ['--days' => $days])
            ->expectsOutputToContain('--days must be a whole number from 1 to 3650')
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public static function invalidDays(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
            'text' => ['many'],
            'fraction' => ['1.5'],
            'too many' => ['3651'],
            'overflow' => ['99999999999999999999'],
        ];
    }

    public function test_a_failing_city_does_not_stop_the_others(): void
    {
        Sleep::fake();
        DataProvider::factory()->create(['slug' => 'open-meteo']);
        $failing = City::factory()->create(['latitude' => 41.11111]);
        $others = City::factory()->count(2)->create(['latitude' => 43.22222]);
        Http::fake(fn (Request $request) => str_contains($request->url(), 'latitude=41.11111')
            ? Http::response('Internal error', 500)
            : Http::response($this->openMeteoBody()));

        $this->artisan('air-quality:ingest')->assertSuccessful();

        $this->assertSame(IngestionOutcome::Failed, IngestionRun::where('city_id', $failing->id)->sole()->outcome);
        $this->assertSame(0, AirQualityHourly::where('city_id', $failing->id)->count());

        foreach ($others as $city) {
            $this->assertSame(IngestionOutcome::Success, IngestionRun::where('city_id', $city->id)->sole()->outcome);
            $this->assertSame(3, AirQualityHourly::where('city_id', $city->id)->count());
        }
    }

    /**
     * Two active and one inactive of each.
     *
     * @return array{0: list<DataProvider>, 1: list<City>}
     */
    private function activeAndInactive(): array
    {
        DataProvider::factory()->create(['status' => StatusFlags::of()]);
        City::factory()->inactive()->create();

        return [
            DataProvider::factory()->count(2)->create()->all(),
            City::factory()->count(2)->create()->all(),
        ];
    }

    /**
     * @param  list<DataProvider>  $providers
     * @param  list<City>  $cities
     */
    private function assertPushedPairs(array $providers, array $cities): void
    {
        foreach ($providers as $provider) {
            foreach ($cities as $city) {
                Queue::assertPushed(IngestHourlyData::class, fn (IngestHourlyData $job) => $job->provider->is($provider) && $job->city->is($city));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function openMeteoBody(): array
    {
        $first = CarbonImmutable::parse('2026-10-17 10:00')->getTimestamp();

        return [
            'hourly_units' => ['time' => 'unixtime', 'pm2_5' => 'μg/m³', 'pm10' => 'μg/m³'],
            'hourly' => [
                'time' => [$first, $first + 3600, $first + 7200],
                'pm2_5' => [10.0, 11.0, 12.0],
                'pm10' => [20.0, 21.0, 22.0],
            ],
        ];
    }
}
