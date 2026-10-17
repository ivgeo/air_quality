<?php

namespace App\Ingestion\Data;

use Carbon\CarbonImmutable;

/**
 * One hour as returned by a provider, before validation.
 */
final readonly class HourlyReading
{
    /**
     * @param  CarbonImmutable  $measureAt  start of the hour, in UTC
     * @param  array<string, mixed>  $values  raw values keyed by App\Enums\Metric value
     */
    public function __construct(
        public CarbonImmutable $measureAt,
        public array $values,
    ) {}
}
