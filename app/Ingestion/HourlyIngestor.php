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
 * Fetches one provider's hourly data for one city and replaces the stored hours of the period it
 * returned, journaling the attempt in `ingestion_runs`.
 *
 * A failure never touches the stored rows: the run is marked failed with the reason instead.
 */
class HourlyIngestor
{
    private const INSERT_CHUNK = 500;

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

            $this->replace($provider, $city, $series);
        } catch (ProviderNotConfiguredException $e) {
            Log::warning('Ingestion run skipped: provider is not configured', [...$context, 'error' => $e->getMessage()]);

            return $this->finish($run, $startedAt, IngestionOutcome::Failed, error: $e->getMessage());
        } catch (ProviderException $e) {
            Log::error('Ingestion run failed', [...$context, 'error' => $e->getMessage()]);

            return $this->finish($run, $startedAt, IngestionOutcome::Failed, rejected: $series?->rejected ?? 0, error: $e->getMessage());
        } catch (Throwable $e) {
            // Unexpected (e.g. database) errors are journaled too, then left to the queue to report.
            Log::error('Ingestion run failed unexpectedly', [...$context, 'error' => $e->getMessage()]);
            $this->finish($run, $startedAt, IngestionOutcome::Failed, error: $e->getMessage());

            throw $e;
        }

        $run = $this->finish($run, $startedAt, IngestionOutcome::Success, count($series->hours), $series->rejected);

        Log::info('Ingestion run finished', [...$context, 'written' => $run->rows_written, 'rejected' => $run->rows_rejected, 'duration_ms' => $run->duration_ms]);

        return $run;
    }

    /**
     * Delete the stored hours between the first and the last returned hour and insert the new ones,
     * atomically, so readers never see the period half replaced.
     */
    private function replace(DataProvider $provider, City $city, ValidatedSeries $series): void
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

        DB::transaction(function () use ($provider, $city, $series, $rows) {
            AirQualityHourly::query()
                ->where('city_id', $city->id)
                ->where('data_provider_id', $provider->id)
                ->whereBetween('measure_at', [$series->first()->toDateTimeString(), $series->last()->toDateTimeString()])
                ->delete();

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                AirQualityHourly::query()->insert($chunk);
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
