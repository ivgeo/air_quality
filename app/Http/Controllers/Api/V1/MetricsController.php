<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MetricsResource;
use App\Observability\Contracts\ApiMetricsStore;
use App\Observability\Data\ApiMetricsSnapshot;
use App\Observability\Data\MetricsReport;
use App\Observability\IngestionMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetricsController extends Controller
{
    /**
     * Basic metrics of the data collection and the API.
     *
     * Ingestion runs per outcome, rows and duration (all time and the last 24 hours), the last run
     * of each provider and the newest failures, plus request counts, errors and latency of the API.
     * Requests to this endpoint are not counted. `api` is null when the metrics store is unavailable.
     */
    public function __invoke(IngestionMetrics $ingestion, ApiMetricsStore $store): MetricsResource
    {
        $now = CarbonImmutable::now();
        $summary = $ingestion->summary($now);

        return new MetricsResource(new MetricsReport(
            $now,
            $summary['total'],
            $summary['last_24h'],
            $ingestion->lastRunByProvider(),
            $ingestion->recentFailures((int) config('air_quality.metrics.recent_failures')),
            $this->apiSnapshot($store),
        ));
    }

    private function apiSnapshot(ApiMetricsStore $store): ?ApiMetricsSnapshot
    {
        try {
            return $store->snapshot();
        } catch (Throwable $e) {
            Log::warning('API metrics unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
