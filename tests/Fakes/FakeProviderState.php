<?php

namespace Tests\Fakes;

use App\Ingestion\Contracts\ProviderState;

/**
 * The state of FakeAirQualityProvider: the decoded JSON as it is.
 */
final readonly class FakeProviderState implements ProviderState
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data) {}

    public static function fromArray(array $data): static
    {
        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
