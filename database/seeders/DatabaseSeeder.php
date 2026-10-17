<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the reference data (cities, providers, metrics); safe to run on every start.
     */
    public function run(): void
    {
        $this->call([
            CitySeeder::class,
            DataProviderSeeder::class,
            QualityMetricSeeder::class,
        ]);
    }
}
