<?php

namespace Tests\Unit\Ranking;

use App\Ranking\DateRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateRangeTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, int}>
     */
    public static function ranges(): array
    {
        return [
            'single day' => ['2026-05-01', '2026-05-01', 'UTC', 1],
            'whole month' => ['2026-05-01', '2026-05-31', 'UTC', 31],
            'across the switch to winter time' => ['2026-10-24', '2026-10-26', 'Europe/Sofia', 3],
            'across the switch to summer time' => ['2026-03-28', '2026-03-30', 'Europe/Sofia', 3],
        ];
    }

    #[DataProvider('ranges')]
    public function test_days_counts_both_bounds_as_calendar_days(string $from, string $to, string $timezone, int $days): void
    {
        $range = new DateRange(CarbonImmutable::parse($from, $timezone), CarbonImmutable::parse($to, $timezone));

        $this->assertSame($days, $range->days());
    }
}
