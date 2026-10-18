<?php

namespace App\Observability;

use App\Enums\IngestionOutcome;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ingestion metrics computed from the `ingestion_runs` journal. Provider-independent: every
 * provider that ever ran is reported.
 */
class IngestionMetrics
{
    private const string RECENT = 'last_24h';

    /**
     * Run counts per outcome, rows and average duration, for the whole history (`total`) and the
     * runs started in the last 24 hours (`last_24h`), in one pass over the journal. Running runs
     * are counted but left out of the average duration.
     *
     * @return array{total: array{runs: array{success: int, failed: int, running: int}, rows_written: int, rows_rejected: int, avg_duration_ms: int|null}, last_24h: array{runs: array{success: int, failed: int, running: int}, rows_written: int, rows_rejected: int, avg_duration_ms: int|null}}
     */
    public function summary(CarbonImmutable $now): array
    {
        /** @var list<string> $columns */
        $columns = [];

        // The outcomes are enum constants, so they are inlined; `recent` marks the last 24 hours.
        foreach (['total' => 'TRUE', self::RECENT => 'recent'] as $block => $in) {
            foreach (IngestionOutcome::cases() as $outcome) {
                $columns[] = "COUNT(CASE WHEN {$in} AND outcome = '{$outcome->value}' THEN 1 END) AS {$block}_{$outcome->value}";
            }

            $columns[] = "SUM(CASE WHEN {$in} THEN rows_written END) AS {$block}_rows_written";
            $columns[] = "SUM(CASE WHEN {$in} THEN rows_rejected END) AS {$block}_rows_rejected";
            $columns[] = "AVG(CASE WHEN {$in} AND outcome <> '".IngestionOutcome::Running->value."' THEN duration_ms END) AS {$block}_avg_duration_ms";
        }

        $sql = sprintf(
            'SELECT %s FROM (SELECT outcome, rows_written, rows_rejected, duration_ms, started_at >= ? AS recent FROM ingestion_runs) AS runs',
            implode(', ', $columns),
        );
        /** @var array<string, int|float|string|null> $values */
        $values = (array) DB::selectOne($sql, [$now->subDay()->toDateTimeString()]);

        return ['total' => $this->block($values, 'total'), self::RECENT => $this->block($values, self::RECENT)];
    }

    /**
     * The newest run of each provider (the id grows with started_at).
     *
     * @return Collection<int, IngestionRun>
     */
    public function lastRunByProvider(): Collection
    {
        return IngestionRun::query()
            ->whereIn('id', IngestionRun::query()->selectRaw('MAX(id)')->groupBy('data_provider_id'))
            ->with(['dataProvider:id,slug,name', 'city:id,slug,name'])
            ->orderBy('data_provider_id')
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
     * @param  array<string, int|float|string|null>  $values
     * @return array{runs: array{success: int, failed: int, running: int}, rows_written: int, rows_rejected: int, avg_duration_ms: int|null}
     */
    private function block(array $values, string $block): array
    {
        $average = $values["{$block}_avg_duration_ms"];

        return [
            'runs' => [
                'success' => (int) $values["{$block}_success"],
                'failed' => (int) $values["{$block}_failed"],
                'running' => (int) $values["{$block}_running"],
            ],
            'rows_written' => (int) $values["{$block}_rows_written"],
            'rows_rejected' => (int) $values["{$block}_rows_rejected"],
            'avg_duration_ms' => $average === null ? null : (int) round((float) $average),
        ];
    }
}
