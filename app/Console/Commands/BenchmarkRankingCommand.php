<?php

namespace App\Console\Commands;

use App\Support\TestDatabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

#[Signature('air-quality:benchmark
    {--cities=100 : Number of synthetic cities}
    {--days=1826 : Days of daily history per city, ending today}
    {--repeat=10 : Measured repetitions per period (after one warm-up)}
    {--seed=42 : Seed of the generated values}')]
#[Description('Measure the ranking on synthetic data in the test database (regenerated on every run, kept afterwards)')]
class BenchmarkRankingCommand extends Command
{
    public function handle(): int
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
}
