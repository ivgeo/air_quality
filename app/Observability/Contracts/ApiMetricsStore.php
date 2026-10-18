<?php

namespace App\Observability\Contracts;

use App\Observability\Data\ApiMetricsSnapshot;
use Throwable;

/**
 * Counters of the requests served under /api: totals per status class and latency (sum and max).
 * Kept outside the database, so recording never competes with the queries it measures.
 */
interface ApiMetricsStore
{
    /**
     * Count one request with its HTTP status and duration in microseconds.
     *
     * @throws Throwable when the store is unavailable; callers decide whether that matters
     */
    public function record(int $status, int $durationUs): void;

    /**
     * @throws Throwable when the store is unavailable
     */
    public function snapshot(): ApiMetricsSnapshot;

    /**
     * Whether the store answers; never throws.
     */
    public function ping(): bool;
}
