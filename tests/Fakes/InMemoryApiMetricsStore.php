<?php

namespace Tests\Fakes;

use App\Observability\Contracts\ApiMetricsStore;
use App\Observability\Data\ApiMetricsSnapshot;
use RuntimeException;

/**
 * Bound by Tests\TestCase, so tests need no Redis. Set `$available = false` to simulate an
 * unreachable store: record() and snapshot() then throw and ping() returns false.
 */
class InMemoryApiMetricsStore implements ApiMetricsStore
{
    public bool $available = true;

    /**
     * @var list<array{status: int, duration_us: int}>
     */
    public array $recorded = [];

    public function record(int $status, int $durationUs): void
    {
        $this->ensureAvailable();
        $this->recorded[] = ['status' => $status, 'duration_us' => $durationUs];
    }

    public function snapshot(): ApiMetricsSnapshot
    {
        $this->ensureAvailable();
        /** @var array<string, int> $counters */
        $counters = [ApiMetricsSnapshot::REQUESTS => count($this->recorded), ApiMetricsSnapshot::DURATION_SUM_US => 0, ApiMetricsSnapshot::DURATION_MAX_US => 0];

        foreach ($this->recorded as ['status' => $status, 'duration_us' => $durationUs]) {
            $class = ApiMetricsSnapshot::statusClass($status);
            $counters[$class] = ($counters[$class] ?? 0) + 1;
            $counters[ApiMetricsSnapshot::DURATION_SUM_US] += $durationUs;
            $counters[ApiMetricsSnapshot::DURATION_MAX_US] = max($counters[ApiMetricsSnapshot::DURATION_MAX_US], $durationUs);
        }

        return ApiMetricsSnapshot::fromCounters($counters);
    }

    public function ping(): bool
    {
        return $this->available;
    }

    private function ensureAvailable(): void
    {
        if (! $this->available) {
            throw new RuntimeException('The in-memory metrics store is unavailable.');
        }
    }
}
