<?php

namespace App\Jobs;

use App\Enums\IngestionOutcome;
use App\Enums\IngestionType;
use App\Ingestion\HourlyIngestor;
use App\Models\City;
use App\Models\DataProvider;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Ingests one provider's hourly data for one city over one period (UTC).
 */
class IngestHourlyData implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * An unexpected exception fails the job at once; only overlap releases are retried.
     */
    public int $maxExceptions = 1;

    /**
     * The timeout of a provider without `job_timeout` in config/air_quality.php: Open-Meteo's worst
     * case, 3 attempts x (15 s + 5 s connect) + sleeps.
     */
    public const int DEFAULT_TIMEOUT = 80;

    /**
     * The provider's `job_timeout`; it stays below the database queue's retry_after (330 s), so a
     * slow job is never handed out twice.
     */
    public int $timeout;

    /**
     * A job of the same type, provider and city waiting in the queue makes a new one redundant.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly DataProvider $provider,
        public readonly City $city,
        public readonly IngestionType $type,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {
        $this->timeout = (int) config("air_quality.providers.{$provider->slug}.job_timeout", self::DEFAULT_TIMEOUT);
    }

    public function handle(HourlyIngestor $ingestor): void
    {
        $run = $ingestor->ingest($this->provider, $this->city, $this->type, $this->from, $this->to);

        // Recompute the days of the requested period; a failed run changed nothing.
        if ($run->outcome === IngestionOutcome::Success) {
            AggregateDailyData::dispatch($this->city, $run->period_from, $run->period_to);
        }
    }

    /**
     * A scheduled refresh and a backfill of the same provider and city never write at the same
     * time: the later one is released and retried. The lock expires if a worker dies holding it.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("{$this->provider->id}:{$this->city->id}"))->releaseAfter(30)->expireAfter($this->timeout * 2),
        ];
    }

    public function uniqueId(): string
    {
        return "{$this->type->value}:{$this->provider->id}:{$this->city->id}";
    }

    /**
     * Keep retrying overlap releases for up to an hour, by which time the next refresh is due.
     */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHour();
    }
}
