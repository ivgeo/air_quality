<?php

namespace Tests\Unit\Support;

use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LocalCalendarTest extends TestCase
{
    private LocalCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = new LocalCalendar('Europe/Sofia');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function utcHours(): array
    {
        return [
            'winter, last hour of the day' => ['2026-01-15 21:59:59', '2026-01-15'],
            'winter, local midnight (UTC+2)' => ['2026-01-15 22:00:00', '2026-01-16'],
            'summer, last hour of the day' => ['2026-07-15 20:59:59', '2026-07-15'],
            'summer, local midnight (UTC+3)' => ['2026-07-15 21:00:00', '2026-07-16'],
        ];
    }

    #[DataProvider('utcHours')]
    public function test_date_of_returns_the_local_calendar_day_of_a_utc_moment(string $utc, string $date): void
    {
        $this->assertSame($date, $this->calendar->dateOf(CarbonImmutable::parse($utc, 'UTC'))->toDateString());
    }

    /**
     * @return array<string, array{string, string, string, int}>
     */
    public static function days(): array
    {
        return [
            'winter day' => ['2026-01-15', '2026-01-14 22:00:00', '2026-01-15 22:00:00', 24],
            'summer day' => ['2026-07-15', '2026-07-14 21:00:00', '2026-07-15 21:00:00', 24],
            'switch to summer time' => ['2026-03-29', '2026-03-28 22:00:00', '2026-03-29 21:00:00', 23],
            'switch to winter time' => ['2026-10-25', '2026-10-24 21:00:00', '2026-10-25 22:00:00', 25],
        ];
    }

    #[DataProvider('days')]
    public function test_utc_bounds_span_the_local_day_from_midnight_to_midnight(string $date, string $start, string $end, int $hours): void
    {
        [$from, $to] = $this->calendar->utcBounds(CarbonImmutable::parse($date));

        $this->assertSame('UTC', $from->getTimezone()->getName());
        $this->assertSame('UTC', $to->getTimezone()->getName());
        $this->assertSame($start, $from->toDateTimeString());
        $this->assertSame($end, $to->toDateTimeString());
        $this->assertSame($hours, (int) $from->diffInHours($to));
    }

    public function test_utc_bounds_use_only_the_calendar_date_of_the_given_value(): void
    {
        [$from] = $this->calendar->utcBounds(CarbonImmutable::parse('2026-01-15 23:30', 'UTC'));

        $this->assertSame('2026-01-14 22:00:00', $from->toDateTimeString());
    }

    public function test_today_is_the_local_calendar_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 21:30', 'UTC'));

        $this->assertSame('2026-07-16', $this->calendar->today()->toDateString());
    }
}
