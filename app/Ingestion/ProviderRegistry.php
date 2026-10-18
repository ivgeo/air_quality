<?php

namespace App\Ingestion;

use App\Ingestion\Contracts\AirQualityProvider;
use App\Ingestion\Exceptions\ProviderNotConfiguredException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Builds the provider configured for a `data_provider.slug` (config/air_quality.php: providers).
 */
class ProviderRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    /**
     * Build the provider with its config entry and a store for its `data_provider.state`.
     *
     * @throws ProviderNotConfiguredException
     */
    public function for(string $slug): AirQualityProvider
    {
        return $this->container->make($this->classFor($slug), [
            'config' => $this->entry($slug),
            'state' => new DatabaseProviderStateStore($slug),
        ]);
    }

    /**
     * @return class-string<AirQualityProvider>
     *
     * @throws ProviderNotConfiguredException
     */
    public function classFor(string $slug): string
    {
        /** @var mixed $class */
        $class = $this->entry($slug)['class'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, AirQualityProvider::class)) {
            throw ProviderNotConfiguredException::invalidClass($slug, $class);
        }

        return $class;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ProviderNotConfiguredException
     */
    private function entry(string $slug): array
    {
        /** @var mixed $entry */
        $entry = $this->config->get('air_quality.providers')[$slug] ?? null;

        if (! is_array($entry)) {
            throw ProviderNotConfiguredException::missing($slug);
        }

        return $entry;
    }
}
