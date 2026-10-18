<?php

namespace App\Ingestion\Contracts;

/**
 * Reads and writes one provider's state. Writes go per city, so concurrent jobs of the same
 * provider for different cities never overwrite each other.
 */
interface ProviderStateStore
{
    public function get(): ProviderState;

    /**
     * Replace the state kept for one city (`cities.<slug>`), leaving everything else as is.
     *
     * @param  array<string, mixed>  $value
     */
    public function putCity(string $city, array $value): void;
}
