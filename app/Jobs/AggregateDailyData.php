<?php

namespace App\Jobs;

use App\Aggregation\DailyAggregator;
use App\Models\City;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recomputes one city's daily rows for the local days touched by a UTC period, e.g. after an
 * ingestion run. Not unique: jobs for different periods must all run.
 */
class AggregateDailyData implements ShouldQueue
{
    use Queueable;

    /**
     * Unexpected exceptions (e.g. a deadlock that outlived the transaction retries) are retried a
     * few times; overlap releases do not count towards this.
     */
    public int $maxExceptions = 3;

    /**
     * Stays below the database queue's retry_after (90 s), so a slow job is never handed out twice.
     */
    public int $timeout = 80;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly City $city,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public function handle(DailyAggregator $aggregator, LocalCalendar $calendar): void
    {
        $aggregator->aggregate($this->city, $calendar->dateOf($this->from), $calendar->dateOf($this->to));
    }

    /**
     * Jobs for the same city (e.g. after runs of two providers) run one after another; the later
     * one is released and retried. The lock expires if a worker dies holding it.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("aggregate:{$this->city->id}"))->releaseAfter(10)->expireAfter($this->timeout * 2),
        ];
    }

    /**
     * Keep retrying overlap releases for up to an hour, by which time the next refresh is due.
     */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHour();
    }

    /**
     * The days keep their previous rows until the next run recomputes them.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Daily aggregation failed', [
            'city' => $this->city->slug,
            'from' => $this->from->toDateTimeString(),
            'to' => $this->to->toDateTimeString(),
            'error' => $e->getMessage(),
        ]);
    }
}
