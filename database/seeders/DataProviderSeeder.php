<?php

namespace Database\Seeders;

use App\Data\StatusFlags;
use App\Enums\Status;
use App\Models\DataProvider;
use Illuminate\Database\Seeder;

/**
 * Seeds the data providers. Safe to re-run: the status of an existing provider is left untouched.
 *
 * A provider that needs a setting (e.g. an API key) is created active only when the setting is
 * configured at that moment; activating it later is an operator's decision.
 */
class DataProviderSeeder extends Seeder
{
    /**
     * Slug => name and the config/air_quality.php setting it needs to run (null for none).
     *
     * @var array<string, array{name: string, requires: string|null}>
     */
    private const array PROVIDERS = [
        'open-meteo' => ['name' => 'Open-Meteo', 'requires' => null],
        'openaq' => ['name' => 'OpenAQ', 'requires' => 'api_key'],
    ];

    public function run(): void
    {
        foreach (self::PROVIDERS as $slug => ['name' => $name, 'requires' => $requires]) {
            $provider = DataProvider::firstOrNew(['slug' => $slug]);
            $provider->name = $name;

            if (! $provider->exists) {
                $provider->status = $requires === null || filled(config("air_quality.providers.{$slug}.{$requires}"))
                    ? StatusFlags::of(Status::Active)
                    : StatusFlags::of();
            }

            $provider->save();
        }
    }
}
