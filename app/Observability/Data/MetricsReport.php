<?php

namespace App\Observability\Data;

use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything GET /api/v1/metrics reports, gathered at one moment.
 */
final readonly class MetricsReport
{
    /**
     * @param  array{runs: array{success: int, failed: int, running: int}, rows_written: int, rows_rejected: int, avg_duration_ms: int|null}  $total  all runs in the journal
     * @param  array{runs: array{success: int, failed: int, running: int}, rows_written: int, rows_rejected: int, avg_duration_ms: int|null}  $recent  runs started in the last 24 hours
     * @param  Collection<int, IngestionRun>  $lastRuns  the newest run of each provider
     * @param  Collection<int, IngestionRun>  $failures  the newest failed runs, newest first
     * @param  ApiMetricsSnapshot|null  $api  null when the metrics store is unavailable
     */
    public function __construct(
        public CarbonImmutable $generatedAt,
        public array $total,
        public array $recent,
        public Collection $lastRuns,
        public Collection $failures,
        public ?ApiMetricsSnapshot $api,
    ) {}
}
