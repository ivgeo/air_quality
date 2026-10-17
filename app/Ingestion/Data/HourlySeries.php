<?php

namespace App\Ingestion\Data;

/**
 * The hours a provider returned for one city, before validation.
 */
final readonly class HourlySeries
{
    /**
     * @param  list<HourlyReading>  $readings
     */
    public function __construct(public array $readings) {}
}
