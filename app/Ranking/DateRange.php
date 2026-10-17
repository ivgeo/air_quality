<?php

namespace App\Ranking;

use Carbon\CarbonImmutable;

/**
 * An inclusive period of calendar days; only the Y-m-d of each bound is meaningful.
 */
final readonly class DateRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * Number of calendar days in the period, both bounds included.
     */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diff($this->to->startOfDay())->days + 1;
    }
}
