<?php

namespace Database\Factories;

use App\Models\AirQualityDaily;
use App\Models\City;
use App\Models\QualityMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AirQualityDaily>
 */
class AirQualityDailyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $count = fake()->numberBetween(1, 24);

        return [
            'date' => now('Europe/Sofia')->subDays(fake()->numberBetween(1, 180))->toDateString(),
            'city_id' => City::factory(),
            'metric_id' => QualityMetric::factory(),
            'metric_sum' => round($count * fake()->randomFloat(2, 0, 80), 5),
            'metric_count' => $count,
        ];
    }
}
