<?php

namespace App\Ingestion\Exceptions;

use RuntimeException;

/**
 * A provider is active in `data_provider` but config/air_quality.php has no usable entry for it.
 */
class ProviderNotConfiguredException extends RuntimeException
{
    public static function missing(string $slug): self
    {
        return new self(sprintf('No configuration for data provider "%s" in config/air_quality.php.', $slug));
    }

    public static function invalidClass(string $slug, mixed $class): self
    {
        return new self(sprintf(
            'The class configured for data provider "%s" (%s) does not implement the AirQualityProvider contract.',
            $slug,
            is_string($class) ? $class : get_debug_type($class),
        ));
    }
}
