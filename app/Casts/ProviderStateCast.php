<?php

namespace App\Casts;

use App\Ingestion\Contracts\ProviderState;
use App\Ingestion\Contracts\ProviderStateStore;
use App\Ingestion\Data\NoProviderState;
use App\Ingestion\Exceptions\ProviderNotConfiguredException;
use App\Ingestion\ProviderRegistry;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Maps `data_provider.state` to the state class of the row's provider (found by its slug through
 * config); a provider that is not configured reads as NoProviderState. Read only: the state is
 * written per city through App\Ingestion\Contracts\ProviderStateStore.
 *
 * @implements CastsAttributes<ProviderState, never>
 */
class ProviderStateCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ProviderState
    {
        try {
            $stateClass = app(ProviderRegistry::class)->classFor((string) ($attributes['slug'] ?? ''))::stateClass();
        } catch (ProviderNotConfiguredException) {
            return new NoProviderState;
        }

        /** @var mixed $data */
        $data = is_string($value) ? json_decode($value, true) : null;

        return $stateClass::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): never
    {
        throw new LogicException(sprintf('The "%s" attribute is written per city through %s.', $key, ProviderStateStore::class));
    }
}
