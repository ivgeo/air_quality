<?php

namespace Database\Factories;

use App\Data\StatusFlags;
use App\Enums\Status;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::limit(Str::slug($name), 24, '').'-'.fake()->unique()->numberBetween(1, 9999999),
            'latitude' => fake()->latitude(41.2, 44.2),
            'longitude' => fake()->longitude(22.4, 28.6),
            'status' => StatusFlags::of(Status::Active),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => StatusFlags::of()]);
    }
}
