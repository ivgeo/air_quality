<?php

namespace App\Aggregation;

/**
 * Outcome of aggregating one city over a period of local days.
 */
final readonly class AggregationResult
{
    public function __construct(
        public int $days,
        public int $rowsWritten,
    ) {}
}
