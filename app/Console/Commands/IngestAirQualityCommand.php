<?php

namespace App\Console\Commands;

use App\Ingestion\IngestionDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('air-quality:ingest')]
#[Description('Queue the refresh of the most recent hourly data for every active provider and city')]
class IngestAirQualityCommand extends Command
{
    public function handle(IngestionDispatcher $dispatcher): int
    {
        $count = $dispatcher->scheduled();

        $count === 0
            ? $this->components->warn('No active provider and city to ingest.')
            : $this->components->info("Dispatched {$count} ingestion job(s); one already waiting for the same city is not duplicated.");

        return self::SUCCESS;
    }
}
