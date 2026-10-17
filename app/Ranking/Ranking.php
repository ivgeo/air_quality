<?php

namespace App\Ranking;

use App\Enums\Metric;

/**
 * Active cities ordered from the cleanest (lowest average) to the most polluted over a period.
 */
final readonly class Ranking
{
    /**
     * @param  DateRange  $stored  the overall stored period for the metric
     * @param  list<RankingEntry>  $entries
     */
    public function __construct(
        public Metric $metric,
        public DateRange $period,
        public DateRange $stored,
        public array $entries,
    ) {}
}
