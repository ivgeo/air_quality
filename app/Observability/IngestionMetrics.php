<?php

namespace App\Observability;

use App\Enums\IngestionOutcome;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection as RowCollection;

/**
 * Ingestion metrics computed from the `ingestion_runs` journal. Provider-independent: every
 * provider that ever ran is reported.
 */
class IngestionMetrics
{
    /**
     * Run counts per outcome, rows and average duration, for the whole history (`total`) and the
     * runs started in the last 24 hours (`last_24h`). Running runs are counted but left out of the
     * average duration.
     *
     * `total` is one scan of the journal (it grows with the history); `last_24h` reads only its
     * range through a covering index.
     *
     * @return array{total: array{runs: array{success: int, failed: int, running: int}, rows_written: int, rows_rejected: int, avg_duration_ms: int|null}, last_24h: array{runs: array{success: int, failed: int, running: int}, rows_written: int, rows_rejected: int, avg_duration_ms: int|null}}
     */
    public function summary(CarbonImmutable $now): array
    {
        return ['total' => $this->block(null), 'last_24h' => $this->block($now->subDay())];
    }

    /**
     * The newest run of each provider (the id grows with started_at).
     *
     * @return Collection<int, IngestionRun>
     */
    public function lastRunByProvider(): Collection
    {
        return IngestionRun::query()
            ->select('ingestion_runs.*')
            ->joinSub(IngestionRun::query()->selectRaw('MAX(id) AS id')->groupBy('data_provider_id'), 'last_run', 'last_run.id', '=', 'ingestion_runs.id')
            ->with(['dataProvider:id,slug,name', 'city:id,slug,name'])
            ->orderBy('ingestion_runs.data_provider_id')
            ->get();
    }

    /**
     * The newest failed runs, newest first.
     *
     * @return Collection<int, IngestionRun>
     */
    public function recentFailures(int $limit): Collection
    {
        return IngestionRun::query()
            ->where('outcome', IngestionOutcome::Failed)
            ->with(['dataProvider:id,slug,name', 'city:id,slug,name'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{runs: array{success: int, failed: int, running: int}, rows_written: int, rows_rejected: int, avg_duration_ms: int|null}
     */
    private function block(?CarbonImmutable $since): array
    {
        /** @var RowCollection<int, object{outcome: string, runs: int|string, rows_written: int|string|null, rows_rejected: int|string|null, duration_sum: int|string|null, durations: int|string}> $rows */
        $rows = IngestionRun::query()
            ->toBase()
            ->selectRaw('outcome, COUNT(*) AS runs, SUM(rows_written) AS rows_written, SUM(rows_rejected) AS rows_rejected, SUM(duration_ms) AS duration_sum, COUNT(duration_ms) AS durations')
            ->when($since !== null, fn (Builder $query): Builder => $query->where('started_at', '>=', $since?->toDateTimeString()))
            ->groupBy('outcome')
            ->get();
        /** @var array<string, int> $runs */
        $runs = array_fill_keys(array_column(IngestionOutcome::cases(), 'value'), 0);
        $written = 0;
        $rejected = 0;
        $durationSum = 0;
        $durations = 0;

        foreach ($rows as $row) {
            $runs[$row->outcome] = (int) $row->runs;
            $written += (int) $row->rows_written;
            $rejected += (int) $row->rows_rejected;

            if ($row->outcome !== IngestionOutcome::Running->value) {
                $durationSum += (int) $row->duration_sum;
                $durations += (int) $row->durations;
            }
        }

        return [
            'runs' => ['success' => $runs['success'], 'failed' => $runs['failed'], 'running' => $runs['running']],
            'rows_written' => $written,
            'rows_rejected' => $rejected,
            'avg_duration_ms' => $durations === 0 ? null : (int) round($durationSum / $durations),
        ];
    }
}
