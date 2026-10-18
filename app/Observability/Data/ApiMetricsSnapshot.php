<?php

namespace App\Observability\Data;

/**
 * The API counters at one moment; latency is kept in microseconds and reported in milliseconds.
 */
final readonly class ApiMetricsSnapshot
{
    /**
     * Counter field names, shared by every store.
     */
    public const string REQUESTS = 'requests';

    public const string DURATION_SUM_US = 'duration_us_sum';

    public const string DURATION_MAX_US = 'duration_us_max';

    /**
     * @var list<string>
     */
    public const array STATUS_CLASSES = ['2xx', '3xx', '4xx', '5xx'];

    /**
     * @param  array<string, int>  $byStatus  request count per status class, e.g. ['2xx' => 10, ...]
     */
    public function __construct(
        public int $requests,
        public array $byStatus,
        public int $durationSumUs,
        public int $durationMaxUs,
    ) {}

    /**
     * Build a snapshot from the stored counter fields; missing fields count as zero.
     *
     * @param  array<string, int|string>  $counters
     */
    public static function fromCounters(array $counters): self
    {
        /** @var array<string, int> $byStatus */
        $byStatus = [];

        foreach (self::STATUS_CLASSES as $class) {
            $byStatus[$class] = (int) ($counters[$class] ?? 0);
        }

        return new self(
            (int) ($counters[self::REQUESTS] ?? 0),
            $byStatus,
            (int) ($counters[self::DURATION_SUM_US] ?? 0),
            (int) ($counters[self::DURATION_MAX_US] ?? 0),
        );
    }

    /**
     * The counter field of a status, e.g. 404 → "4xx".
     */
    public static function statusClass(int $status): string
    {
        return intdiv($status, 100).'xx';
    }

    /**
     * Client and server errors (4xx and 5xx).
     */
    public function errors(): int
    {
        return $this->byStatus['4xx'] + $this->byStatus['5xx'];
    }

    public function averageLatencyMs(): ?float
    {
        return $this->requests === 0 ? null : round($this->durationSumUs / $this->requests / 1000, 2);
    }

    public function maxLatencyMs(): ?float
    {
        return $this->requests === 0 ? null : round($this->durationMaxUs / 1000, 2);
    }
}
