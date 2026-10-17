<?php

namespace App\Ranking;

/**
 * The days with stored daily data of one metric, per active city and overall.
 */
final readonly class StoredPeriods
{
    /**
     * @param  ?DateRange  $overall  earliest to latest day any active city has data for; null when there is none
     * @param  array<int, DateRange>  $byCity  keyed by city id
     */
    public function __construct(
        public ?DateRange $overall,
        public array $byCity,
    ) {}
}
