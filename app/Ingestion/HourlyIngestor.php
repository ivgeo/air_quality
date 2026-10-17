<?php

namespace App\Ingestion;

use App\Enums\IngestionOutcome;
use App\Enums\IngestionType;
use App\Ingestion\Data\ValidatedSeries;
use App\Ingestion\Exceptions\ProviderException;
use App\Ingestion\Exceptions\ProviderNotConfiguredException;
use App\Models\AirQualityHourly;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches one provider's hourly data for one city and upserts the valid hours, journaling the
 * attempt in `ingestion_runs`.
 *
 * Stored data is never lost to a bad response: a failure leaves the rows untouched, a rejected or
 * missing hour keeps its stored row, and a metric missing from a returned hour keeps its stored
 * value (new values are merged into the stored JSON).
 */
class HourlyIngestor
{
    private const UPSERT_CHUNK = 500;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly HourlySeriesValidator $validator,
    ) {}

    public function ingest(DataProvider $provider, City $city, IngestionType $type, CarbonImmutable $from, CarbonImmutable $to): IngestionRun
    {
        $startedAt = hrtime(true);
        $context = [
            'provider' => $provider->slug,
            'city' => $city->slug,
            'type' => $type->value,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
        ];

        $run = IngestionRun::create([
            'data_provider_id' => $provider->id,
            'city_id' => $city->id,
            'type' => $type,
            'period_from' => $from,
            'period_to' => $to,
            'outcome' => IngestionOutcome::Running,
            'started_at' => CarbonImmutable::now(),
        ]);

        Log::info('Ingestion run started', $context);
        $series = null;

        try {
            $series = $this->validator->validate($this->providers->for($provider->slug)->fetchHourly($city, $from, $to), $from, $to);

            if ($series->isEmpty()) {
                throw new ProviderException(sprintf('The provider returned no valid hours (%d rejected).', $series->rejected));
            }

            $this->store($provider, $city, $series);
        } catch (ProviderNotConfiguredException $e) {
            Log::warning('Ingestion run skipped: provider is not configured', [...$context, 'error' => $e->getMessage()]);

            return $this->finish($run, $startedAt, IngestionOutcome::Failed, error: $e->getMessage());
        } catch (ProviderException $e) {
            Log::error('Ingestion run failed', [...$context, 'error' => $e->getMessage()]);

            return $this->finish($run, $startedAt, IngestionOutcome::Failed, rejected: $series?->rejected ?? 0, error: $e->getMessage());
        } catch (Throwable $e) {
            // Unexpected (e.g. database) errors are journaled too, then left to the queue to report.
            Log::error('Ingestion run failed unexpectedly', [...$context, 'error' => $e->getMessage()]);

            try {
                $this->finish($run, $startedAt, IngestionOutcome::Failed, error: $e->getMessage());
            } catch (Throwable) {
                // The database itself may be the problem; the original error matters more.
            }

            throw $e;
        }

        $run = $this->finish($run, $startedAt, IngestionOutcome::Success, count($series->hours), $series->rejected);

        Log::info('Ingestion run finished', [...$context, 'written' => $run->rows_written, 'rejected' => $run->rows_rejected, 'duration_ms' => $run->duration_ms]);

        return $run;
    }

    /**
     * Insert the valid hours or merge them into the stored ones (unique city, provider, hour),
     * atomically, so readers never see the period half written.
     */
    private function store(DataProvider $provider, City $city, ValidatedSeries $series): void
    {
        $rows = [];

        foreach ($series->hours as $timestamp => $measurements) {
            $rows[] = [
                'city_id' => $city->id,
                'data_provider_id' => $provider->id,
                'measure_at' => CarbonImmutable::createFromTimestamp($timestamp, 'UTC')->toDateTimeString(),
                'data' => $measurements->toJson(),
            ];
        }

        // VALUES() is the form Laravel's MySQL grammar uses for upserts without a row alias.
        $merge = ['data' => DB::raw('JSON_MERGE_PATCH(`data`, VALUES(`data`))')];

        DB::transaction(function () use ($rows, $merge) {
            foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
                AirQualityHourly::query()->toBase()->upsert($chunk, ['city_id', 'data_provider_id', 'measure_at'], $merge);
            }
        });
    }

    private function finish(IngestionRun $run, int $startedAt, IngestionOutcome $outcome, int $written = 0, int $rejected = 0, ?string $error = null): IngestionRun
    {
        $run->update([
            'outcome' => $outcome,
            'rows_written' => $written,
            'rows_rejected' => $rejected,
            'error' => $error,
            'finished_at' => CarbonImmutable::now(),
            'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
        ]);

        return $run;
    }
}
