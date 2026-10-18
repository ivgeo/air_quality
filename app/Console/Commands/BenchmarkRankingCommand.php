<?php

namespace App\Console\Commands;

use App\Data\StatusFlags;
use App\Enums\Metric;
use App\Enums\Status;
use App\Models\QualityMetric;
use App\Support\LocalCalendar;
use App\Support\TestDatabase;
use Carbon\CarbonImmutable;
use Database\Seeders\QualityMetricSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

#[Signature('air-quality:benchmark
    {--cities=100 : Number of synthetic cities}
    {--days=1826 : Days of daily history per city, ending today}
    {--repeat=10 : Measured repetitions per period (after one warm-up)}
    {--seed=42 : Seed of the generated values}')]
#[Description('Measure the ranking on synthetic data in the test database (regenerated on every run, kept afterwards)')]
class BenchmarkRankingCommand extends Command
{
    private const int CHUNK = 5000;

    /**
     * Child tables first, so the foreign keys never block a delete.
     */
    private const array TABLES = ['air_quality_daily', 'air_quality_hourly', 'ingestion_runs', 'city'];

    public function handle(LocalCalendar $calendar, QualityMetricSeeder $metrics): int
    {
        try {
            $cities = $this->positiveIntOption('cities');
            $days = $this->positiveIntOption('days');
            $repeat = $this->positiveIntOption('repeat');
            $seed = $this->positiveIntOption('seed');
            $this->useTestDatabase();
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->callSilently('migrate', ['--force' => true]);
        $last = $calendar->today();
        $this->generate($metrics, $cities, $days, $last, $seed);

        $this->components->info(sprintf('The synthetic data stays in %s until the next `make test`.', TestDatabase::NAME));

        return self::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function positiveIntOption(string $name): int
    {
        $value = $this->option($name);
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($int === false) {
            throw new InvalidArgumentException(sprintf('--%s must be a positive integer, "%s" given.', $name, $value));
        }

        return $int;
    }

    /**
     * Points the default connection at the test database, then checks the database it actually
     * reaches before anything is written.
     *
     * @throws RuntimeException
     */
    private function useTestDatabase(): void
    {
        $connection = (string) config('database.default');

        if (DB::connection()->getDatabaseName() !== TestDatabase::NAME) {
            config(["database.connections.{$connection}.database" => TestDatabase::NAME]);
            DB::purge($connection);
        }

        TestDatabase::ensure(DB::connection(), 'The benchmark writes only to the test database: check DB_URL.');
    }

    /**
     * Replaces the stored cities and data with the synthetic ones: per city a base level plus
     * daily noise, 24 measurements a day, for every metric.
     */
    private function generate(QualityMetricSeeder $metrics, int $cities, int $days, CarbonImmutable $last, int $seed): void
    {
        foreach (self::TABLES as $table) {
            // DELETE rather than TRUNCATE, which would commit an open transaction (e.g. in a test).
            DB::table($table)->delete();
        }

        $metrics->run();
        /** @var list<int> $metricIds */
        $metricIds = QualityMetric::query()->whereIn('slug', array_column(Metric::cases(), 'value'))->orderBy('id')->pluck('id')->all();
        $random = new Randomizer(new Mt19937($seed));
        $active = StatusFlags::of(Status::Active)->value;

        for ($i = 1; $i <= $cities; $i++) {
            $number = sprintf('%03d', $i);
            DB::table('city')->insert([
                'name' => "Bench City {$number}",
                'slug' => "bench-{$number}",
                'latitude' => round($random->getFloat(41.2, 44.2), 5),
                'longitude' => round($random->getFloat(22.4, 28.6), 5),
                'status' => $active,
            ]);
        }

        /** @var list<int> $cityIds */
        $cityIds = DB::table('city')->orderBy('id')->pluck('id')->all();
        $first = $last->subDays($days - 1);
        /** @var list<array<string, int|string>> $rows */
        $rows = [];

        foreach ($cityIds as $cityId) {
            $base = $random->getFloat(5.0, 40.0);

            for ($date = $first; $date <= $last; $date = $date->addDay()) {
                foreach ($metricIds as $metricId) {
                    $average = $base * $random->getFloat(0.5, 1.5);
                    $rows[] = [
                        'date' => $date->toDateString(),
                        'city_id' => $cityId,
                        'metric_id' => $metricId,
                        'metric_sum' => sprintf('%.5F', 24 * $average),
                        'metric_count' => 24,
                    ];

                    if (count($rows) === self::CHUNK) {
                        DB::table('air_quality_daily')->insert($rows);
                        $rows = [];
                    }
                }
            }
        }

        if ($rows !== []) {
            DB::table('air_quality_daily')->insert($rows);
        }

        $this->components->info(sprintf(
            'Generated %d cities × %d days × %d metrics = %d daily rows (seed %d), %s – %s.',
            $cities,
            $days,
            count($metricIds),
            $cities * $days * count($metricIds),
            $seed,
            $first->toDateString(),
            $last->toDateString(),
        ));
    }
}
