<?php

namespace App\Ingestion\Providers\OpenAq;

use App\Ingestion\Contracts\ProviderState;

/**
 * OpenAQ's `data_provider.state`: the discovered stations keyed by city slug.
 */
final readonly class OpenAqState implements ProviderState
{
    /**
     * @param  array<string, CityStations>  $cities
     */
    public function __construct(public array $cities) {}

    public static function fromArray(array $data): static
    {
        /** @var array<string, CityStations> $cities */
        $cities = [];

        foreach (is_array($data['cities'] ?? null) ? $data['cities'] : [] as $slug => $city) {
            $stations = CityStations::tryFromArray($city);

            if ($stations !== null) {
                $cities[(string) $slug] = $stations;
            }
        }

        return new self($cities);
    }

    public function city(string $slug): ?CityStations
    {
        return $this->cities[$slug] ?? null;
    }

    public function toArray(): array
    {
        return ['cities' => array_map(fn (CityStations $city): array => $city->toArray(), $this->cities)];
    }
}
