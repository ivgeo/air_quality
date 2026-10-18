<?php

namespace App\Ingestion\Providers\OpenAq;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * The sensors discovered around one city and when; an empty list (no station nearby) is kept
 * too, so it is not looked up again on every run.
 */
final readonly class CityStations
{
    /**
     * @param  list<Sensor>  $sensors
     */
    public function __construct(
        public CarbonImmutable $discoveredAt,
        public array $sensors,
    ) {}

    /**
     * Null when any field is missing or invalid: the city then counts as not discovered.
     */
    public static function tryFromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['discovered_at'] ?? null) || ! is_array($data['sensors'] ?? null) || ! array_is_list($data['sensors'])) {
            return null;
        }

        try {
            $discoveredAt = CarbonImmutable::createFromFormat(DATE_ATOM, $data['discovered_at']);
        } catch (InvalidFormatException) {
            return null;
        }

        /** @var list<Sensor> $sensors */
        $sensors = [];

        foreach ($data['sensors'] as $item) {
            $sensor = Sensor::tryFromArray($item);

            if ($sensor === null) {
                return null;
            }

            $sensors[] = $sensor;
        }

        return new self($discoveredAt->utc(), $sensors);
    }

    public function isStale(int $ttlHours): bool
    {
        return $this->discoveredAt->addHours($ttlHours)->lessThanOrEqualTo(CarbonImmutable::now());
    }

    /**
     * @return array{discovered_at: string, sensors: list<array{id: int, metric: string, location: string}>}
     */
    public function toArray(): array
    {
        return [
            'discovered_at' => $this->discoveredAt->utc()->toAtomString(),
            'sensors' => array_map(fn (Sensor $sensor): array => $sensor->toArray(), $this->sensors),
        ];
    }
}
