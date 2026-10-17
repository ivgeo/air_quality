<?php

namespace Database\Seeders;

use App\Data\StatusFlags;
use App\Enums\Status;
use App\Models\DataProvider;
use Illuminate\Database\Seeder;

/**
 * Seeds the data providers. Safe to re-run: the status of an existing provider is left untouched.
 */
class DataProviderSeeder extends Seeder
{
    /**
     * Provider names, keyed by slug.
     */
    private const PROVIDERS = [
        'open-meteo' => 'Open-Meteo',
    ];

    public function run(): void
    {
        foreach (self::PROVIDERS as $slug => $name) {
            $provider = DataProvider::firstOrNew(['slug' => $slug]);
            $provider->name = $name;

            if (! $provider->exists) {
                $provider->status = StatusFlags::of(Status::Active);
            }

            $provider->save();
        }
    }
}
