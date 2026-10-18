<?php

namespace App\Ingestion\Exceptions;

use RuntimeException;

/**
 * A provider is active in `data_provider` but config/air_quality.php has no usable entry for it
 * (none, an invalid class or a required setting missing).
 */
class ProviderNotConfiguredException extends RuntimeException
{
    public static function missing(string $slug): self
    {
        return new self(sprintf('No configuration for data provider "%s" in config/air_quality.php.', $slug));
    }

    public static function missingSetting(string $slug, string $setting, string $env): self
    {
        return new self(sprintf('Data provider "%s" has no "%s" setting in config/air_quality.php; set %s in .env.', $slug, $setting, $env));
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
