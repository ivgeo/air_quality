<?php

namespace App\Data;

use App\Enums\Metric;
use InvalidArgumentException;

/**
 * Immutable set of metric values measured for one city, provider and hour.
 *
 * A metric that was not measured is absent (get() returns null), which is different from a
 * measured value of 0.0.
 */
final readonly class Measurements
{
    /**
     * @param  array<string, float>  $values  keyed by Metric value
     */
    private function __construct(private array $values) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Build from the stored JSON shape, e.g. ['pm25' => 12.3, 'pm10' => 20.1].
     * Unknown keys (e.g. a metric removed from the enum) and null values are skipped.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $measurements = self::empty();

        foreach ($data as $key => $value) {
            $metric = Metric::tryFrom((string) $key);

            if ($metric === null || $value === null) {
                continue;
            }

            if (! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException(sprintf('Metric "%s" must be numeric, %s given.', $key, get_debug_type($value)));
            }

            $measurements = $measurements->with($metric, $value);
        }

        return $measurements;
    }

    public function with(Metric $metric, float $value): self
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException(sprintf('Metric "%s" must be a finite number.', $metric->value));
        }

        return new self([...$this->values, $metric->value => $value]);
    }

    public function get(Metric $metric): ?float
    {
        return $this->values[$metric->value] ?? null;
    }

    public function has(Metric $metric): bool
    {
        return array_key_exists($metric->value, $this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return array<string, float> present metrics only, in Metric::cases() order
     */
    public function toArray(): array
    {
        $result = [];

        foreach (Metric::cases() as $metric) {
            if ($this->has($metric)) {
                $result[$metric->value] = $this->values[$metric->value];
            }
        }

        return $result;
    }

    /**
     * The stored JSON shape; empty measurements become {} rather than [].
     */
    public function toJson(): string
    {
        return json_encode((object) $this->toArray(), JSON_THROW_ON_ERROR);
    }
}
