<?php

namespace App\Ingestion\Data;

use App\Data\Measurements;

/**
 * The hours of a series that may be stored, plus how many returned hours were rejected.
 */
final readonly class ValidatedSeries
{
    /**
     * @param  array<int, Measurements>  $hours  keyed by the Unix timestamp of the hour (UTC), ascending
     */
    public function __construct(
        public array $hours,
        public int $rejected,
    ) {}

    public function isEmpty(): bool
    {
        return $this->hours === [];
    }
}
