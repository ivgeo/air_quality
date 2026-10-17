<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Maps UTC moments to local calendar days and back (config air_quality.timezone).
 *
 * A day runs from local midnight to the next local midnight, so across a DST switch it spans
 * 23 or 25 UTC hours.
 */
class LocalCalendar
{
    public function __construct(private readonly string $timezone) {}

    /**
     * The local calendar day of a moment, as local midnight.
     */
    public function dateOf(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone($this->timezone)->startOfDay();
    }

    /**
     * The UTC interval [start, end) of the local calendar day of `$date` (only its Y-m-d is used).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function utcBounds(CarbonImmutable $date): array
    {
        $start = CarbonImmutable::parse($date->toDateString(), $this->timezone);

        return [$start->utc(), $start->addDay()->utc()];
    }

    public function today(): CarbonImmutable
    {
        return $this->dateOf(CarbonImmutable::now());
    }
}
