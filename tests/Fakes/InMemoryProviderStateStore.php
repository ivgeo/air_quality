<?php

namespace Tests\Fakes;

use App\Ingestion\Contracts\ProviderState;
use App\Ingestion\Contracts\ProviderStateStore;

/**
 * Keeps a provider's state in memory, so provider tests need no data_provider row.
 */
class InMemoryProviderStateStore implements ProviderStateStore
{
    /**
     * @param  class-string<ProviderState>  $stateClass
     * @param  array<string, mixed>  $data  the state as stored JSON would decode
     */
    public function __construct(
        private readonly string $stateClass,
        public array $data = [],
    ) {}

    public function get(): ProviderState
    {
        return $this->stateClass::fromArray($this->data);
    }

    public function putCity(string $city, array $value): void
    {
        $this->data['cities'][$city] = $value;
    }
}
