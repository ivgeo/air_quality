<?php

namespace App\Ingestion\Providers\OpenAq;

use App\Enums\Metric;

/**
 * One OpenAQ sensor of a reference station: it measures a single metric.
 */
final readonly class Sensor
{
    public function __construct(
        public int $id,
        public Metric $metric,
        public string $location,
    ) {}

    public static function tryFromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_int($data['id'] ?? null) || ! is_string($data['metric'] ?? null) || ! is_string($data['location'] ?? null)) {
            return null;
        }

        $metric = Metric::tryFrom($data['metric']);

        return $metric === null ? null : new self($data['id'], $metric, $data['location']);
    }

    /**
     * @return array{id: int, metric: string, location: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'metric' => $this->metric->value, 'location' => $this->location];
    }
}
