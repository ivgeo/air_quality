<?php

namespace App\Ranking;

use App\Enums\Metric;
use App\Models\City;
use App\Models\QualityMetric;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ranks the active cities by the average of a metric over a period, reading only the daily
 * aggregates: the number of queries and of rows read do not depend on the hourly data.
 */
class CityRanking
{
    /**
     * The first and last day with daily data of the metric per active city, in one query.
     */
    public function storedPeriods(QualityMetric $metric): StoredPeriods
    {
        /** @var array<int, DateRange> $byCity */
        $byCity = DB::table('air_quality_daily as d')
            ->join('city as c', fn (JoinClause $join): JoinClause => $join->on('c.id', '=', 'd.city_id')->where('c.is_active', 1))
            ->where('d.metric_id', $metric->id)
            ->groupBy('d.city_id')
            ->select('d.city_id')
            ->selectRaw('MIN(d.date) AS first_day, MAX(d.date) AS last_day')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->city_id => new DateRange(CarbonImmutable::parse($row->first_day), CarbonImmutable::parse($row->last_day)),
            ])
            ->all();

        if ($byCity === []) {
            return new StoredPeriods(null, []);
        }

        $overall = new DateRange(
            min(array_map(fn (DateRange $range): CarbonImmutable => $range->from, $byCity)),
            max(array_map(fn (DateRange $range): CarbonImmutable => $range->to, $byCity)),
        );

        return new StoredPeriods($overall, $byCity);
    }

    /**
     * The active cities with data of the metric in the period, cleanest first; equal averages are
     * ordered by city name. The average is weighted by the number of measurements.
     *
     * @throws InvalidArgumentException when `$stored` has no data
     */
    public function rank(QualityMetric $metric, DateRange $period, StoredPeriods $stored): Ranking
    {
        $overall = $stored->overall ?? throw new InvalidArgumentException('A ranking needs stored data of the metric.');

        // Aggregating in a derived table keeps the scan on the covering (metric_id, date, ...) index.
        $totals = DB::table('air_quality_daily')
            ->select('city_id')
            ->selectRaw('SUM(metric_sum) AS total_sum, SUM(metric_count) AS total_count, COUNT(*) AS days')
            ->where('metric_id', $metric->id)
            ->whereBetween('date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('city_id');

        $cities = City::query()
            ->joinSub($totals, 'a', 'a.city_id', '=', 'city.id')
            ->where('city.is_active', 1)
            ->select('city.*')
            ->selectRaw('a.total_sum / a.total_count AS average, a.total_count AS measurements, a.days AS days_with_data')
            ->orderBy('average')
            ->orderBy('city.name')
            ->get();

        /** @var list<RankingEntry> $entries */
        $entries = [];
        foreach ($cities as $city) {
            $entries[] = new RankingEntry(
                rank: count($entries) + 1,
                city: $city,
                average: (string) $city->getAttribute('average'),
                measurements: (int) $city->getAttribute('measurements'),
                daysWithData: (int) $city->getAttribute('days_with_data'),
                stored: $stored->byCity[$city->id],
            );
        }

        return new Ranking(Metric::from($metric->slug), $period, $overall, $entries);
    }
}
