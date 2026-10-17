<?php

namespace App\Ingestion\Data;

use App\Data\Measurements;
use Carbon\CarbonImmutable;

/**
 * The hours of a series that may be stored, plus how many returned hours were rejected.
 *
 * The stored period that these hours replace runs from the first to the last accepted hour.
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

    public function first(): ?CarbonImmutable
    {
        return $this->isEmpty() ? null : CarbonImmutable::createFromTimestampUTC(array_key_first($this->hours));
    }

    public function last(): ?CarbonImmutable
    {
        return $this->isEmpty() ? null : CarbonImmutable::createFromTimestampUTC(array_key_last($this->hours));
    }
}
