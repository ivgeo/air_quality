<?php

namespace App\Http\Resources;

use App\Models\IngestionRun;
use App\Observability\Data\MetricsReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ingestion metrics from the journal and API metrics from the metrics store.
 *
 * @property MetricsReport $resource
 */
class MetricsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->resource;
        $api = $report->api;

        return [
            'generated_at' => $report->generatedAt->toIso8601String(),
            'ingestion' => [
                /** All runs in the journal. */
                'total' => $report->total,
                /** Runs started in the last 24 hours. */
                'last_24h' => $report->recent,
                /** The newest run of each provider. */
                'last_run_by_provider' => $report->lastRuns->map(fn (IngestionRun $run): array => [
                    ...$this->source($run),
                    'outcome' => $run->outcome->value,
                    'started_at' => $run->started_at->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'rows_written' => $run->rows_written,
                    'rows_rejected' => $run->rows_rejected,
                ])->values()->all(),
                /** The newest failed runs, newest first. */
                'recent_failures' => $report->failures->map(fn (IngestionRun $run): array => [
                    ...$this->source($run),
                    'started_at' => $run->started_at->toIso8601String(),
                    'error' => $run->error,
                ])->values()->all(),
            ],
            /** Requests under /api except this endpoint; null when the metrics store is unavailable. */
            'api' => $api === null ? null : [
                'requests' => $api->requests,
                /** 4xx and 5xx responses. */
                'errors' => $api->errors(),
                'by_status' => $api->byStatus,
                'avg_latency_ms' => $api->averageLatencyMs(),
                'max_latency_ms' => $api->maxLatencyMs(),
            ],
        ];
    }

    /**
     * @return array{provider: array{slug: string, name: string}, city: array{slug: string, name: string}}
     */
    private function source(IngestionRun $run): array
    {
        return [
            'provider' => ['slug' => $run->dataProvider->slug, 'name' => $run->dataProvider->name],
            'city' => ['slug' => $run->city->slug, 'name' => $run->city->name],
        ];
    }
}
