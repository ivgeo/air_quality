<?php

namespace Database\Seeders;

use App\Data\StatusFlags;
use App\Enums\Status;
use App\Models\City;
use Illuminate\Database\Seeder;

/**
 * Seeds the supported cities. Safe to re-run: name and coordinates are refreshed, while the
 * status of an existing city (e.g. deactivated by an operator) is left untouched.
 */
class CitySeeder extends Seeder
{
    /**
     * City centre coordinates (WGS84), keyed by slug.
     */
    private const CITIES = [
        'sofia' => ['name' => 'Sofia', 'latitude' => 42.69751, 'longitude' => 23.32415],
        'plovdiv' => ['name' => 'Plovdiv', 'latitude' => 42.14354, 'longitude' => 24.74966],
        'varna' => ['name' => 'Varna', 'latitude' => 43.21405, 'longitude' => 27.91473],
        'burgas' => ['name' => 'Burgas', 'latitude' => 42.50606, 'longitude' => 27.46781],
        'ruse' => ['name' => 'Ruse', 'latitude' => 43.85639, 'longitude' => 25.97083],
    ];

    public function run(): void
    {
        foreach (self::CITIES as $slug => $attributes) {
            $city = City::firstOrNew(['slug' => $slug]);
            $city->fill($attributes);

            if (! $city->exists) {
                $city->status = StatusFlags::of(Status::Active);
            }

            $city->save();
        }
    }
}
