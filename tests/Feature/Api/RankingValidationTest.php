<?php

namespace Tests\Feature\Api;

use App\Data\StatusFlags;
use App\Enums\Metric;
use App\Models\AirQualityDaily;
use App\Models\City;
use App\Models\QualityMetric;
use Database\Seeders\QualityMetricSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RankingValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(QualityMetricSeeder::class);
        $this->travelTo('2026-07-20 12:00:00');
        $city = City::factory()->create();
        $this->daily($city, '2026-07-01');
        $this->daily($city, '2026-07-10');
    }

    /**
     * Today is 2026-07-20 (Europe/Sofia) and PM2.5 is stored for 2026-07-01 – 2026-07-10.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidRequests(): array
    {
        return [
            'missing from' => [['to' => '2026-07-05'], 'from'],
            'missing to' => [['from' => '2026-07-01'], 'to'],
            'slashes' => [['from' => '2026/07/01', 'to' => '2026-07-05'], 'from'],
            'day first' => [['from' => '2026-07-01', 'to' => '05-07-2026'], 'to'],
            'not a date' => [['from' => 'abc', 'to' => '2026-07-05'], 'from'],
            'impossible date' => [['from' => '2026-02-30', 'to' => '2026-07-05'], 'from'],
            'from after to' => [['from' => '2026-07-06', 'to' => '2026-07-05'], 'from'],
            'future to' => [['from' => '2026-07-05', 'to' => '2026-07-21'], 'to'],
            'future from' => [['from' => '2026-07-21', 'to' => '2026-07-21'], 'from'],
            'from before the stored period' => [['from' => '2026-06-30', 'to' => '2026-07-05'], 'from'],
            'to after the stored period' => [['from' => '2026-07-05', 'to' => '2026-07-11'], 'to'],
            'today without data' => [['from' => '2026-07-05', 'to' => '2026-07-20'], 'to'],
            'unknown metric' => [['from' => '2026-07-01', 'to' => '2026-07-05', 'metric' => 'no2'], 'metric'],
            'metric without data' => [['from' => '2026-07-01', 'to' => '2026-07-05', 'metric' => 'pm10'], 'metric'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidRequests')]
    public function test_an_invalid_request_is_rejected_without_a_partial_result(array $query, string $field): void
    {
        $this->ranking($query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field])
            ->assertJsonMissingPath('data');
    }

    public function test_a_future_date_gets_only_the_future_date_message(): void
    {
        $response = $this->ranking(['from' => '2026-07-05', 'to' => '2026-07-21'])->assertUnprocessable();

        $this->assertSame(['to'], array_keys($response->json('errors')));
        $message = $response->json('errors.to.0');
        $this->assertStringContainsString('future', $message);
        $this->assertStringNotContainsString('stored period', $message);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function outsideTheStoredPeriod(): array
    {
        return [
            'from' => [['from' => '2026-06-30', 'to' => '2026-07-05'], 'from'],
            'to' => [['from' => '2026-07-05', 'to' => '2026-07-11'], 'to'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('outsideTheStoredPeriod')]
    public function test_a_date_outside_the_stored_period_names_the_available_period(array $query, string $field): void
    {
        $response = $this->ranking($query)->assertUnprocessable();

        $message = $response->json("errors.{$field}.0");
        $this->assertStringContainsString('2026-07-01', $message);
        $this->assertStringContainsString('2026-07-10', $message);
    }

    public function test_an_inactive_metric_is_rejected(): void
    {
        $this->daily(City::query()->firstOrFail(), '2026-07-01', Metric::Pm10);
        QualityMetric::where('slug', Metric::Pm10->value)->firstOrFail()->update(['status' => StatusFlags::of()]);

        $this->ranking(['from' => '2026-07-01', 'to' => '2026-07-01', 'metric' => 'pm10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['metric']);
    }

    public function test_the_default_metric_is_rejected_when_it_is_inactive(): void
    {
        QualityMetric::where('slug', Metric::Pm25->value)->firstOrFail()->update(['status' => StatusFlags::of()]);

        $this->ranking(['from' => '2026-07-01', 'to' => '2026-07-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['metric' => 'not available']);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function invalidTo(): array
    {
        return [
            'missing to' => [['from' => '2026-07-01']],
            'malformed to' => [['from' => '2026-07-01', 'to' => 'abc']],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidTo')]
    public function test_an_invalid_to_does_not_add_an_error_to_a_valid_from(array $query): void
    {
        $response = $this->ranking($query)->assertUnprocessable();

        $this->assertSame(['to'], array_keys($response->json('errors')));
    }

    public function test_a_metric_without_collected_data_says_so(): void
    {
        $this->ranking(['from' => '2026-07-01', 'to' => '2026-07-05', 'metric' => 'pm10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['metric' => 'not been collected yet']);
    }

    public function test_data_of_inactive_cities_only_counts_as_no_data(): void
    {
        $inactive = City::factory()->inactive()->create();
        $this->daily($inactive, '2026-07-01', Metric::Pm10);

        $this->ranking(['from' => '2026-07-01', 'to' => '2026-07-01', 'metric' => 'pm10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['metric' => 'not been collected yet']);
    }

    private function daily(City $city, string $date, Metric $metric = Metric::Pm25): void
    {
        AirQualityDaily::factory()->create([
            'date' => $date,
            'city_id' => $city->id,
            'metric_id' => QualityMetric::where('slug', $metric->value)->value('id'),
            'metric_sum' => 10.0,
            'metric_count' => 1,
        ]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function ranking(array $query): TestResponse
    {
        return $this->getJson('/api/v1/ranking?'.http_build_query($query));
    }
}
