<?php

namespace Tests\Unit\Data;

use App\Data\Measurements;
use App\Enums\Metric;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MeasurementsTest extends TestCase
{
    public function test_from_array_skips_unknown_keys_and_null_values(): void
    {
        $measurements = Measurements::fromArray(['pm25' => 12.3, 'pm10' => null, 'ozone' => 40.0]);

        $this->assertSame(['pm25' => 12.3], $measurements->toArray());
    }

    public function test_get_distinguishes_a_missing_metric_from_a_zero_value(): void
    {
        $measurements = Measurements::fromArray(['pm25' => 0]);

        $this->assertSame(0.0, $measurements->get(Metric::Pm25));
        $this->assertNull($measurements->get(Metric::Pm10));
    }

    public function test_has_reports_only_present_metrics(): void
    {
        $measurements = Measurements::fromArray(['pm10' => 20.1]);

        $this->assertTrue($measurements->has(Metric::Pm10));
        $this->assertFalse($measurements->has(Metric::Pm25));
    }

    public function test_to_array_follows_the_metric_case_order(): void
    {
        $measurements = Measurements::fromArray(['pm10' => 20.1, 'pm25' => 12.3]);

        $this->assertSame(['pm25' => 12.3, 'pm10' => 20.1], $measurements->toArray());
    }

    public function test_with_returns_a_new_instance(): void
    {
        $original = Measurements::empty();
        $changed = $original->with(Metric::Pm25, 7.5);

        $this->assertNotSame($original, $changed);
        $this->assertFalse($original->has(Metric::Pm25));
        $this->assertSame(7.5, $changed->get(Metric::Pm25));
    }

    public function test_empty_measurements_convert_to_an_empty_array(): void
    {
        $this->assertSame([], Measurements::empty()->toArray());
        $this->assertTrue(Measurements::empty()->isEmpty());
    }

    public function test_to_json_stores_empty_measurements_as_an_object(): void
    {
        $this->assertSame('{}', Measurements::empty()->toJson());
    }

    public function test_to_json_follows_the_metric_case_order(): void
    {
        $measurements = Measurements::fromArray(['pm10' => 20.1, 'pm25' => 12.3]);

        $this->assertSame('{"pm25":12.3,"pm10":20.1}', $measurements->toJson());
    }

    #[DataProvider('nonFiniteValues')]
    public function test_non_finite_values_are_rejected(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Measurements::empty()->with(Metric::Pm25, $value);
    }

    #[DataProvider('nonFiniteValues')]
    public function test_non_finite_values_are_rejected_from_array(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Measurements::fromArray(['pm25' => $value]);
    }

    public function test_non_numeric_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Measurements::fromArray(['pm25' => 'high']);
    }

    public static function nonFiniteValues(): array
    {
        return [
            'NAN' => [NAN],
            'INF' => [INF],
            '-INF' => [-INF],
        ];
    }
}
