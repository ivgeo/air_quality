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
     * @throws ProviderNotConfiguredException
     */
    public function for(string $slug): AirQualityProvider
    {
        $entry = $this->config->get('air_quality.providers')[$slug] ?? null;

        if (! is_array($entry)) {
            throw ProviderNotConfiguredException::missing($slug);
        }

        $class = $entry['class'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, AirQualityProvider::class)) {
            throw ProviderNotConfiguredException::invalidClass($slug, $class);
        }

        return $this->container->make($class, ['config' => $entry]);
    }
}
