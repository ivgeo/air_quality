<?php

namespace Tests\Unit\Ingestion;

use App\Ingestion\Data\HourlyReading;
use App\Ingestion\Data\HourlySeries;
use App\Ingestion\Data\ValidatedSeries;
use App\Ingestion\HourlySeriesValidator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HourlySeriesValidatorTest extends TestCase
{
    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 12:34:56', 'UTC'));
        $this->from = CarbonImmutable::parse('2026-10-17 08:00', 'UTC');
        $this->to = CarbonImmutable::parse('2026-10-17 12:00', 'UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_valid_hours_are_kept_with_their_metrics(): void
    {
        $result = $this->validate([
            '2026-10-17 09:00' => ['pm25' => 12.3, 'pm10' => 20],
            '2026-10-17 10:00' => ['pm25' => 0.0, 'pm10' => 0],
        ]);

        $this->assertSame(0, $result->rejected);
        $this->assertSame([
            '2026-10-17 09:00' => ['pm25' => 12.3, 'pm10' => 20.0],
            '2026-10-17 10:00' => ['pm25' => 0.0, 'pm10' => 0.0],
        ], $this->hours($result));
    }

    #[DataProvider('invalidValues')]
    public function test_an_invalid_value_drops_only_that_metric(mixed $value): void
    {
        $result = $this->validate(['2026-10-17 09:00' => ['pm25' => $value, 'pm10' => 20.5]]);

        $this->assertSame(['2026-10-17 09:00' => ['pm10' => 20.5]], $this->hours($result));
        $this->assertSame(0, $result->rejected);
    }

    #[DataProvider('invalidValues')]
    public function test_an_hour_without_any_valid_metric_is_rejected(mixed $value): void
    {
        $result = $this->validate([
            '2026-10-17 09:00' => ['pm25' => $value, 'pm10' => $value],
            '2026-10-17 10:00' => [],
            '2026-10-17 11:00' => ['pm25' => 5.0],
        ]);

        $this->assertSame(['2026-10-17 11:00' => ['pm25' => 5.0]], $this->hours($result));
        $this->assertSame(2, $result->rejected);
    }

    public static function invalidValues(): array
    {
        return [
            'null' => [null],
            'string' => ['12.3'],
            'bool' => [true],
            'NAN' => [NAN],
            'INF' => [INF],
            'negative' => [-0.1],
        ];
    }

    public function test_unknown_metric_keys_are_ignored(): void
    {
        $result = $this->validate(['2026-10-17 09:00' => ['pm25' => 7.0, 'ozone' => 50.0]]);

        $this->assertSame(['2026-10-17 09:00' => ['pm25' => 7.0]], $this->hours($result));
    }

    public function test_future_hours_are_cut_without_counting_them_as_rejected(): void
    {
        $result = $this->validate(
            ['2026-10-17 12:00' => ['pm25' => 1.0], '2026-10-17 13:00' => ['pm25' => 2.0], '2026-10-17 14:00' => ['pm25' => null]],
            to: CarbonImmutable::parse('2026-10-17 18:00', 'UTC'),
        );

        $this->assertSame(['2026-10-17 12:00' => ['pm25' => 1.0]], $this->hours($result));
        $this->assertSame(0, $result->rejected);
    }

    public function test_hours_outside_the_requested_period_are_cut_without_counting_them_as_rejected(): void
    {
        $result = $this->validate([
            '2026-10-17 07:00' => ['pm25' => 1.0],
            '2026-10-17 08:00' => ['pm25' => 2.0],
            '2026-10-17 11:00' => ['pm25' => 3.0],
            '2026-10-17 12:00' => ['pm25' => null],
        ], to: CarbonImmutable::parse('2026-10-17 11:00', 'UTC'));

        $this->assertSame(['2026-10-17 08:00' => ['pm25' => 2.0], '2026-10-17 11:00' => ['pm25' => 3.0]], $this->hours($result));
        $this->assertSame(0, $result->rejected);
    }

    public function test_accepted_hours_are_in_ascending_order(): void
    {
        $result = $this->validate([
            '2026-10-17 11:00' => ['pm25' => 3.0],
            '2026-10-17 08:00' => ['pm25' => null],
            '2026-10-17 09:00' => ['pm25' => 2.0],
            '2026-10-17 13:00' => ['pm25' => 4.0],
        ]);

        $this->assertSame(['2026-10-17 09:00', '2026-10-17 11:00'], array_keys($this->hours($result)));
    }

    public function test_a_time_that_is_not_the_start_of_an_hour_is_rejected(): void
    {
        $result = $this->validate(['2026-10-17 09:30' => ['pm25' => 2.0], '2026-10-17 10:00' => ['pm25' => 3.0]]);

        $this->assertSame(['2026-10-17 10:00' => ['pm25' => 3.0]], $this->hours($result));
        $this->assertSame(1, $result->rejected);
    }

    public function test_a_repeated_hour_keeps_the_first_reading_and_rejects_the_rest(): void
    {
        $readings = [
            new HourlyReading(CarbonImmutable::parse('2026-10-17 10:00', 'UTC'), ['pm25' => 3.0]),
            new HourlyReading(CarbonImmutable::parse('2026-10-17 10:00', 'UTC'), ['pm25' => 9.0]),
        ];

        $result = (new HourlySeriesValidator)->validate(new HourlySeries($readings), $this->from, $this->to);

        $this->assertSame(['2026-10-17 10:00' => ['pm25' => 3.0]], $this->hours($result));
        $this->assertSame(1, $result->rejected);
    }

    public function test_an_empty_series_gives_an_empty_result(): void
    {
        $result = $this->validate([]);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->rejected);
    }

    /**
     * @param  array<string, array<string, mixed>>  $hours  values keyed by 'Y-m-d H:i' (UTC)
     */
    private function validate(array $hours, ?CarbonImmutable $to = null): ValidatedSeries
    {
        /** @var list<HourlyReading> $readings */
        $readings = [];

        foreach ($hours as $hour => $values) {
            $readings[] = new HourlyReading(CarbonImmutable::parse($hour, 'UTC'), $values);
        }

        return (new HourlySeriesValidator)->validate(new HourlySeries($readings), $this->from, $to ?? $this->to);
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function hours(ValidatedSeries $result): array
    {
        /** @var array<string, array<string, float>> $hours */
        $hours = [];

        foreach ($result->hours as $timestamp => $measurements) {
            $hours[CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d H:i')] = $measurements->toArray();
        }

        return $hours;
    }
}
