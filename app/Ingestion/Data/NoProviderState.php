<?php

namespace App\Ingestion\Data;

use App\Ingestion\Contracts\ProviderState;

/**
 * The state of a provider that keeps none (stored as `{}`).
 */
final readonly class NoProviderState implements ProviderState
{
    public static function fromArray(array $data): static
    {
        return new self;
    }

    public function toArray(): array
    {
        return [];
    }
}
