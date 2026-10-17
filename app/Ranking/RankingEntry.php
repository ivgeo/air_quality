<?php

namespace App\Ranking;

use App\Models\City;

/**
 * One city's place in a ranking.
 */
final readonly class RankingEntry
{
    /**
     * @param  string  $average  exact SUM(metric_sum) / SUM(metric_count) as returned by MySQL
     * @param  DateRange  $stored  the city's stored period for the metric
     */
    public function __construct(
        public int $rank,
        public City $city,
        public string $average,
        public int $measurements,
        public int $daysWithData,
        public DateRange $stored,
    ) {}
}
