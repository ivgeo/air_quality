<?php

namespace Database\Factories;

use App\Enums\IngestionOutcome;
use App\Enums\IngestionType;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A finished scheduled run started an hour ago by default; pass `started_at` (and the state) to
 * place it in time.
 *
 * @extends Factory<IngestionRun>
 */
class IngestionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = CarbonImmutable::now()->subHour();

        return [
            'data_provider_id' => DataProvider::factory(),
            'city_id' => City::factory(),
            'type' => IngestionType::Scheduled,
            'period_from' => $startedAt->subDays(3)->startOfHour(),
            'period_to' => $startedAt->startOfHour(),
            'outcome' => IngestionOutcome::Success,
            'rows_written' => fake()->numberBetween(1, 72),
            'rows_rejected' => 0,
            'error' => null,
            'started_at' => $startedAt,
            'finished_at' => fn (array $attributes): ?CarbonImmutable => $this->finishedAt($attributes),
            'duration_ms' => 1000,
        ];
    }

    public function success(): static
    {
        return $this->state(['outcome' => IngestionOutcome::Success, 'error' => null]);
    }

    public function failed(): static
    {
        return $this->state([
            'outcome' => IngestionOutcome::Failed,
            'rows_written' => 0,
            'error' => 'The provider responded with HTTP 502.',
        ]);
    }

    public function running(): static
    {
        return $this->state([
            'outcome' => IngestionOutcome::Running,
            'rows_written' => 0,
            'rows_rejected' => 0,
            'finished_at' => null,
            'duration_ms' => null,
        ]);
    }

    /**
     * A finished run ends `duration_ms` after it started.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finishedAt(array $attributes): ?CarbonImmutable
    {
        if ($attributes['duration_ms'] === null) {
            return null;
        }

        return CarbonImmutable::parse($attributes['started_at'])->addMilliseconds((int) $attributes['duration_ms']);
    }
}
