<?php

namespace App\Console\Commands;

use App\Data\StatusFlags;
use App\Enums\Metric;
use App\Enums\Status;
use App\Models\QualityMetric;
use App\Ranking\CityRanking;
use App\Ranking\DateRange;
use App\Support\LocalCalendar;
use App\Support\TestDatabase;
use Carbon\CarbonImmutable;
use Database\Seeders\QualityMetricSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
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

    /**
     * Measured period lengths in days, each ending on the last stored day.
     */
    private const array PERIODS = [1, 30, 182, 1826];

    /**
     * The CityRanking method whose queries are being captured, or null while not capturing.
     */
    private ?string $capturing = null;

    /**
     * The query listener cannot be removed, so it is added once per process (Artisan reuses the
     * command instance, e.g. when a test runs it twice).
     */
    private bool $listening = false;

    /**
     * @var list<array{method: string, query: QueryExecuted}>
     */
    private array $captured = [];

    public function handle(LocalCalendar $calendar, QualityMetricSeeder $metrics, CityRanking $ranking, Kernel $kernel): int
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

        if ($this->callSilently('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->components->error(sprintf('Migrating the %s database failed.', TestDatabase::NAME));

            return self::FAILURE;
        }

        $last = $calendar->today();
        $this->generate($metrics, $cities, $days, $last, $seed);

        if (! $this->listening) {
            DB::listen(function (QueryExecuted $query): void {
                if ($this->capturing !== null) {
                    $this->captured[] = ['method' => $this->capturing, 'query' => $query];
                }
            });
            $this->listening = true;
        }

        $metric = QualityMetric::query()->where('slug', Metric::Pm25->value)->firstOrFail();
        /** @var list<list<string>> $summary */
        $summary = [];

        foreach (self::PERIODS as $length) {
            $label = $length === 1 ? '1 day' : "{$length} days";

            if ($length > $days) {
                $this->components->warn("{$label}: skipped, longer than the {$days} generated days.");

                continue;
            }

            $period = new DateRange($last->subDays($length - 1), $last);
            $this->newLine();
            $this->line(sprintf('<options=bold>%s (%s – %s)</>', $label, $period->from->toDateString(), $period->to->toDateString()));
            $sql = $this->measureSql($ranking, $metric, $period, $repeat);

            try {
                $endpoint = $this->measureEndpoint($kernel, $period, $repeat);
            } catch (RuntimeException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $summary[] = [
                $label,
                ...array_map(fn (float $ms): string => number_format($ms, 2), [...array_values($sql), array_sum($sql), $endpoint]),
            ];
        }

        $this->newLine();
        $this->line(sprintf('Medians of %d run(s) in ms, %s:', $repeat, Metric::Pm25->label()));
        $this->table(['Period', 'storedPeriods SQL', 'rank SQL', 'SQL total', 'Endpoint (in-process)'], $summary);
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

        // Fresh index statistics, as a long-running database has: right after a bulk insert they
        // are stale and the optimizer can miss the loose index scan of the stored periods.
        // ANALYZE TABLE commits implicitly, so it is skipped inside a transaction (a test's).
        if (DB::transactionLevel() === 0) {
            DB::statement('ANALYZE TABLE air_quality_daily, city');
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

    /**
     * Runs the queries of CityRanking once to warm up, prints their EXPLAIN ANALYZE, then runs
     * them `$repeat` times.
     *
     * @return array<string, float> the median duration in ms per CityRanking method
     */
    private function measureSql(CityRanking $ranking, QualityMetric $metric, DateRange $period, int $repeat): array
    {
        /** @var array<string, list<float>> $durations */
        $durations = [];

        for ($run = 0; $run <= $repeat; $run++) {
            $this->captured = [];
            $this->capturing = 'storedPeriods';
            $stored = $ranking->storedPeriods($metric);
            $this->capturing = 'rank';
            $ranking->rank($metric, $period, $stored);
            $this->capturing = null;

            if ($run === 0) {
                foreach ($this->captured as ['method' => $method, 'query' => $query]) {
                    $this->line("EXPLAIN ANALYZE {$method}:");
                    /** @var object{EXPLAIN: string} $plan */
                    $plan = DB::selectOne('EXPLAIN ANALYZE '.$query->sql, $query->bindings);
                    $this->line($plan->EXPLAIN);
                }

                continue;
            }

            /** @var array<string, float> $totals */
            $totals = ['storedPeriods' => 0.0, 'rank' => 0.0];

            foreach ($this->captured as ['method' => $method, 'query' => $query]) {
                $totals[$method] += $query->time;
            }

            foreach ($totals as $method => $ms) {
                $durations[$method][] = $ms;
            }
        }

        return array_map($this->median(...), $durations);
    }

    /**
     * Sends GET /api/v1/ranking straight to the HTTP kernel (routing, middleware, validation, the
     * queries and the JSON), once to warm up and then `$repeat` times. terminate() is not called,
     * so no API metrics or request logs are recorded.
     *
     * @return float the median duration in ms
     *
     * @throws RuntimeException when a response is not 200
     */
    private function measureEndpoint(Kernel $kernel, DateRange $period, int $repeat): float
    {
        /** @var list<float> $durations */
        $durations = [];

        for ($run = 0; $run <= $repeat; $run++) {
            $request = Request::create('/api/v1/ranking', 'GET', $period->toDateStrings());
            $request->headers->set('Accept', 'application/json');
            $startedAt = hrtime(true);
            $response = $kernel->handle($request);
            $ms = (hrtime(true) - $startedAt) / 1_000_000;

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException(sprintf('GET %s returned %d: %s', $request->getRequestUri(), $response->getStatusCode(), $response->getContent()));
            }

            if ($run > 0) {
                $durations[] = $ms;
            }
        }

        $this->line(sprintf('Endpoint GET /api/v1/ranking: median %.2f ms', $this->median($durations)));

        return $this->median($durations);
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
