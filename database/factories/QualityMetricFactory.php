<?php

namespace Database\Factories;

use App\Data\StatusFlags;
use App\Enums\Status;
use App\Models\QualityMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityMetric>
 */
class QualityMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'metric-'.fake()->unique()->numberBetween(1, 9999999);

        return [
            'name' => strtoupper($slug),
            'slug' => $slug,
            'status' => StatusFlags::of(Status::Active),
        ];
    }
}
