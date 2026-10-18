<?php

namespace App\Observability;

use App\Observability\Contracts\ApiMetricsStore;
use App\Observability\Data\ApiMetricsSnapshot;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Stops calling an unavailable store for a while. A failed call (or ping) leaves a marker file;
 * while it is younger than the retry interval every call fails at once instead of waiting for the
 * store again (e.g. ~3 s for the DNS lookup of a stopped Redis container), on every PHP worker.
 */
class CircuitBreakingApiMetricsStore implements ApiMetricsStore
{
    public function __construct(
        private readonly ApiMetricsStore $store,
        private readonly string $markerPath,
        private readonly int $retryAfterSeconds,
    ) {}

    public function record(int $status, int $durationUs): void
    {
        $this->call(fn () => $this->store->record($status, $durationUs));
    }

    public function snapshot(): ApiMetricsSnapshot
    {
        /** @var ApiMetricsSnapshot $snapshot */
        $snapshot = $this->call(fn (): ApiMetricsSnapshot => $this->store->snapshot());

        return $snapshot;
    }

    public function ping(): bool
    {
        if ($this->isOpen()) {
            return false;
        }

        $available = $this->store->ping();

        if (! $available) {
            $this->open();
        }

        return $available;
    }

    private function call(Closure $call): mixed
    {
        if ($this->isOpen()) {
            throw new RuntimeException(sprintf('The metrics store failed less than %d s ago; not retried yet.', $this->retryAfterSeconds));
        }

        try {
            return $call();
        } catch (Throwable $e) {
            $this->open();

            throw $e;
        }
    }

    private function isOpen(): bool
    {
        clearstatcache(true, $this->markerPath);

        return is_file($this->markerPath) && filemtime($this->markerPath) > time() - $this->retryAfterSeconds;
    }

    private function open(): void
    {
        // Best effort: an unwritable marker only means the next call tries the store again.
        @touch($this->markerPath);
    }
}
