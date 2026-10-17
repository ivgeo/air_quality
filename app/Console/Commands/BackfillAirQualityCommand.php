<?php

namespace App\Console\Commands;

use App\Ingestion\IngestionDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('air-quality:backfill {--days= : Days of history to fetch (default: air_quality.ingestion.backfill_days)}')]
#[Description('Queue the fetch of the hourly history for every active provider and city; safe to repeat')]
class BackfillAirQualityCommand extends Command
{
    private const MAX_DAYS = 3650;

    public function handle(IngestionDispatcher $dispatcher): int
    {
        $days = (string) ($this->option('days') ?? config('air_quality.ingestion.backfill_days'));

        if (! ctype_digit($days) || (int) $days === 0 || (int) $days > self::MAX_DAYS) {
            $this->components->error(sprintf('--days must be a whole number from 1 to %d, "%s" given.', self::MAX_DAYS, $days));

            return self::FAILURE;
        }

        $count = $dispatcher->backfill((int) $days);

        $count === 0
            ? $this->components->warn('No active provider and city to backfill.')
            : $this->components->info("Dispatched {$count} backfill job(s) for the last {$days} days; one already waiting for the same city is not duplicated.");

        return self::SUCCESS;
    }
}
