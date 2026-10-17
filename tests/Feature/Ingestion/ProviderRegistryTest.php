<?php

namespace Tests\Feature\Ingestion;

use App\Ingestion\Exceptions\ProviderNotConfiguredException;
use App\Ingestion\ProviderRegistry;
use App\Ingestion\Providers\OpenMeteoProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Fakes\FakeAirQualityProvider;
use Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    public function test_the_open_meteo_provider_is_configured(): void
    {
        $this->assertInstanceOf(OpenMeteoProvider::class, app(ProviderRegistry::class)->for('open-meteo'));
    }

    public function test_a_configured_slug_builds_its_class_with_the_config_entry(): void
    {
        config(['air_quality.providers.fake' => ['class' => FakeAirQualityProvider::class, 'url' => 'https://fake.test']]);

        $provider = app(ProviderRegistry::class)->for('fake');

        $this->assertInstanceOf(FakeAirQualityProvider::class, $provider);
        $this->assertSame('https://fake.test', $provider->config['url']);
    }

    public function test_a_slug_without_config_is_not_configured(): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->expectExceptionMessage('"unknown"');

        app(ProviderRegistry::class)->for('unknown');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    #[DataProvider('invalidEntries')]
    public function test_an_entry_without_a_provider_class_is_not_configured(array $entry): void
    {
        config(['air_quality.providers.broken' => $entry]);

        $this->expectException(ProviderNotConfiguredException::class);

        app(ProviderRegistry::class)->for('broken');
    }

    public static function invalidEntries(): array
    {
        return [
            'no class' => [['url' => 'https://fake.test']],
            'missing class' => [['class' => 'App\\Ingestion\\Providers\\DoesNotExist']],
            'not a provider' => [['class' => stdClass::class]],
        ];
    }
}
