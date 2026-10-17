<?php

namespace Database\Factories;

use App\Data\Measurements;
use App\Enums\Metric;
use App\Models\AirQualityHourly;
use App\Models\City;
use App\Models\DataProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AirQualityHourly>
 */
class AirQualityHourlyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'data_provider_id' => DataProvider::factory(),
            'measure_at' => now('UTC')->subHours(fake()->numberBetween(1, 24 * 180))->startOfHour(),
            'data' => Measurements::empty()
                ->with(Metric::Pm25, fake()->randomFloat(2, 0, 80))
                ->with(Metric::Pm10, fake()->randomFloat(2, 0, 120)),
        ];
    }
}
