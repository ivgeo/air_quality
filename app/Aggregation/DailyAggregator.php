<?php

namespace App\Aggregation;

use App\Enums\Metric;
use App\Models\AirQualityDaily;
use App\Models\City;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Projects `air_quality_hourly` onto `air_quality_daily`: per city, local calendar day and metric,
 * the sum and count of the stored hourly values of all providers.
 *
 * Every day is recomputed in full from the hourly rows, so running it again gives the same result
 * and a day without hourly values loses its daily rows. Statuses are not read: whatever was
 * collected is aggregated.
 */
class DailyAggregator
{
    private const int DEADLOCK_ATTEMPTS = 3;

    public function __construct(private readonly LocalCalendar $calendar) {}

    /**
     * Recompute the local days from `$from` to `$to` inclusive (only their Y-m-d is used).
     */
    public function aggregate(City $city, CarbonImmutable $from, CarbonImmutable $to): AggregationResult
    {
        $startedAt = hrtime(true);
        $context = ['city' => $city->slug, 'from' => $from->toDateString(), 'to' => $to->toDateString()];
        $days = 0;
        $rows = 0;

        Log::info('Daily aggregation started', $context);

        for ($date = CarbonImmutable::parse($from->toDateString()); $date->toDateString() <= $to->toDateString(); $date = $date->addDay()) {
            $rows += $this->aggregateDay($city->id, $date);
            $days++;
        }

        Log::info('Daily aggregation finished', [
            ...$context,
            'days' => $days,
            'rows_written' => $rows,
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
        ]);

        return new AggregationResult($days, $rows);
    }

    /**
     * Replace the city's daily rows of one local day (only the Y-m-d of `$date` is used) atomically,
     * so readers see either the old or the new day, never half of it.
     *
     * @return int number of daily rows written
     */
    public function aggregateDay(int $cityId, CarbonImmutable $date): int
    {
        [$start, $end] = $this->calendar->utcBounds($date);
        $day = $date->toDateString();
        /** @var list<string> $metrics */
        $metrics = array_column(Metric::cases(), 'value');
        $placeholders = implode(', ', array_fill(0, count($metrics), '?'));
        // A metric missing from an hour's JSON extracts as NULL and is not counted. Measurements
        // never stores a JSON null, which would extract as a value.
        $value = "CAST(JSON_EXTRACT(h.data, CONCAT('$.\"', m.slug, '\"')) AS DOUBLE)";

        $sql = <<<SQL
            INSERT INTO air_quality_daily (date, city_id, metric_id, metric_sum, metric_count)
            SELECT ?, h.city_id, m.id, SUM({$value}), COUNT({$value})
            FROM air_quality_hourly h
            JOIN quality_metric m ON m.slug IN ({$placeholders})
            WHERE h.city_id = ? AND h.measure_at >= ? AND h.measure_at < ?
            GROUP BY h.city_id, m.id
            HAVING COUNT({$value}) > 0
            SQL;
        /** @var list<int|string> $bindings */
        $bindings = [$day, ...$metrics, $cityId, $start->toDateTimeString(), $end->toDateTimeString()];

        return DB::transaction(function () use ($cityId, $day, $sql, $bindings): int {
            AirQualityDaily::query()->where('city_id', $cityId)->where('date', $day)->delete();

            return DB::affectingStatement($sql, $bindings);
        }, self::DEADLOCK_ATTEMPTS);
    }
}
