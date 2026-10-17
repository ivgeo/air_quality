<?php

namespace App\Ingestion;

use App\Enums\IngestionType;
use App\Jobs\IngestHourlyData;
use App\Models\City;
use App\Models\DataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides WHICH ingestion runs happen: one job per active provider and active city. How a provider
 * is reached is up to its config (see ProviderRegistry).
 */
class IngestionDispatcher
{
    /**
     * Re-fetch the most recent days, which providers may still revise.
     *
     * @return int number of queued jobs
     */
    public function scheduled(): int
    {
        return $this->dispatch(IngestionType::Scheduled, (int) config('air_quality.ingestion.refresh_days'));
    }

    /**
     * @return int number of queued jobs
     */
    public function backfill(int $days): int
    {
        return $this->dispatch(IngestionType::Backfill, $days);
    }

    private function dispatch(IngestionType $type, int $days): int
    {
        // The current hour is included; the validator drops anything the provider has not measured yet.
        $to = CarbonImmutable::now('UTC')->startOfHour();
        $from = $to->subDays($days);
        /** @var Collection<int, City> $cities */
        $cities = City::query()->where('is_active', true)->orderBy('id')->get();
        $count = 0;

        foreach (DataProvider::query()->where('is_active', true)->orderBy('id')->get() as $provider) {
            foreach ($cities as $city) {
                IngestHourlyData::dispatch($provider, $city, $type, $from, $to);
                $count++;
            }
        }

        return $count;
    }
}
