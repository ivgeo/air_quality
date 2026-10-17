<?php

namespace Database\Factories;

use App\Data\StatusFlags;
use App\Enums\Status;
use App\Models\DataProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataProvider>
 */
class DataProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'provider-'.fake()->unique()->numberBetween(1, 9999999);

        return [
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => StatusFlags::of(Status::Active),
        ];
    }
}
