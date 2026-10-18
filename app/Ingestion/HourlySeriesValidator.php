<?php

namespace App\Ingestion;

use App\Data\Measurements;
use App\Enums\Metric;
use App\Ingestion\Data\HourlySeries;
use App\Ingestion\Data\ValidatedSeries;
use Carbon\CarbonImmutable;

/**
 * Value rules shared by every provider.
 *
 * A metric value that is missing, not a number, not finite or negative is dropped; an hour left
 * without any valid metric is rejected rather than stored as zero, and so is a time that is not
 * the start of an hour or repeats an earlier one. Hours after the current one
 * (forecast) or outside the requested period are cut silently: they are not bad data.
 */
class HourlySeriesValidator
{
    public function validate(HourlySeries $series, CarbonImmutable $from, CarbonImmutable $to): ValidatedSeries
    {
        $first = $from->getTimestamp();
        $last = min($to->getTimestamp(), CarbonImmutable::now()->startOfHour()->getTimestamp());
        /** @var array<int, Measurements> $hours */
        $hours = [];
        $rejected = 0;

        foreach ($series->readings as $reading) {
            $timestamp = $reading->measureAt->getTimestamp();

            if ($timestamp < $first || $timestamp > $last) {
                continue;
            }

            $measurements = $this->measurements($reading->values);

            if ($measurements->isEmpty() || $timestamp % 3600 !== 0 || isset($hours[$timestamp])) {
                $rejected++;

                continue;
            }

            $hours[$timestamp] = $measurements;
        }

        ksort($hours);

        return new ValidatedSeries($hours, $rejected);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function measurements(array $values): Measurements
    {
        $measurements = Measurements::empty();

        foreach ($values as $key => $value) {
            $metric = Metric::tryFrom((string) $key);

            if ($metric !== null && self::isValidValue($value)) {
                $measurements = $measurements->with($metric, (float) $value);
            }
        }

        return $measurements;
    }

    /**
     * The value rule alone, for a provider that combines several raw values into one (it must
     * leave invalid ones out before averaging rather than let them skew the result).
     */
    public static function isValidValue(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
    }
}
