<?php

namespace Tests\Feature\Database;

use App\Models\City;
use App\Models\DataProvider;
use App\Models\QualityMetric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider as TestWith;
use Tests\TestCase;

/**
 * Checks the schema itself, so rows are written with the query builder rather than models.
 */
class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_tables_exist_and_the_user_tables_are_gone(): void
    {
        foreach (['city', 'data_provider', 'quality_metric', 'air_quality_hourly', 'air_quality_daily', 'sessions', 'cache', 'jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} is missing.");
        }

        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
    }

    public function test_daily_has_the_covering_index_for_the_ranking_query(): void
    {
        $this->assertSame(
            ['metric_id', 'date', 'city_id', 'metric_sum', 'metric_count'],
            $this->indexColumns('air_quality_daily', 'idx_air_quality_daily_metric_date_city'),
        );
    }

    #[TestWith('uniqueIndexes')]
    public function test_unique_indexes_cover_the_expected_columns(string $table, string $index, array $columns): void
    {
        $this->assertSame($columns, $this->indexColumns($table, $index));
        $this->assertTrue($this->index($table, $index)['unique']);
    }

    public static function uniqueIndexes(): array
    {
        return [
            'city slug' => ['city', 'unq_city_slug', ['slug']],
            'city name' => ['city', 'unq_city_name', ['name']],
            'provider slug' => ['data_provider', 'unq_data_provider_slug', ['slug']],
            'metric slug' => ['quality_metric', 'unq_quality_metric_slug', ['slug']],
            'hourly' => ['air_quality_hourly', 'unq_city_provider_measure', ['city_id', 'data_provider_id', 'measure_at']],
            'daily' => ['air_quality_daily', 'unq_city_date_metric', ['city_id', 'date', 'metric_id']],
        ];
    }

    public function test_foreign_keys_reuse_leading_index_columns_instead_of_own_indexes(): void
    {
        $this->assertNotContains(['city_id'], $this->allIndexColumns('air_quality_hourly'));
        $this->assertNotContains(['city_id'], $this->allIndexColumns('air_quality_daily'));
        $this->assertNotContains(['metric_id'], $this->allIndexColumns('air_quality_daily'));

        $this->assertSame(['data_provider_id'], $this->indexColumns('air_quality_hourly', 'idx_air_quality_hourly_data_provider'));
    }

    public function test_foreign_keys_point_to_the_reference_tables(): void
    {
        $this->assertEqualsCanonicalizing(
            ['city_id' => 'city', 'data_provider_id' => 'data_provider'],
            $this->foreignTables('air_quality_hourly'),
        );
        $this->assertEqualsCanonicalizing(
            ['city_id' => 'city', 'metric_id' => 'quality_metric'],
            $this->foreignTables('air_quality_daily'),
        );
    }

    #[TestWith('statusTables')]
    public function test_is_active_is_derived_from_the_active_bit(string $table, array $row): void
    {
        foreach ([0 => 0, 1 => 1, 2 => 0, 3 => 1] as $status => $active) {
            $id = DB::table($table)->insertGetId([...$row, 'name' => "{$table} {$status}", 'slug' => "{$table}-{$status}", 'status' => $status]);

            $this->assertSame($active, (int) DB::table($table)->where('id', $id)->value('is_active'), "status {$status}");
        }
    }

    public static function statusTables(): array
    {
        return [
            'city' => ['city', ['latitude' => 42.69751, 'longitude' => 23.32415]],
            'data_provider' => ['data_provider', []],
            'quality_metric' => ['quality_metric', []],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[TestWith('statusModels')]
    public function test_is_active_cannot_be_mass_assigned(string $model): void
    {
        $this->assertArrayNotHasKey('is_active', (new $model)->fill(['is_active' => 0])->getAttributes());
    }

    public static function statusModels(): array
    {
        return [
            'city' => [City::class],
            'data_provider' => [DataProvider::class],
            'quality_metric' => [QualityMetric::class],
        ];
    }

    public function test_hourly_rejects_a_second_row_for_the_same_city_provider_and_hour(): void
    {
        $row = ['city_id' => $this->insertCity(), 'data_provider_id' => $this->insertProvider('a'), 'measure_at' => '2026-10-01 10:00:00', 'data' => '{}'];
        DB::table('air_quality_hourly')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('air_quality_hourly')->insert($row);
    }

    public function test_hourly_allows_the_same_hour_from_another_provider(): void
    {
        $row = ['city_id' => $this->insertCity(), 'data_provider_id' => $this->insertProvider('a'), 'measure_at' => '2026-10-01 10:00:00', 'data' => '{}'];
        DB::table('air_quality_hourly')->insert($row);
        DB::table('air_quality_hourly')->insert([...$row, 'data_provider_id' => $this->insertProvider('b')]);

        $this->assertSame(2, DB::table('air_quality_hourly')->count());
    }

    public function test_daily_rejects_a_second_row_for_the_same_city_date_and_metric(): void
    {
        $metricId = DB::table('quality_metric')->insertGetId(['name' => 'PM2.5', 'slug' => 'pm25', 'status' => 1]);
        $row = ['city_id' => $this->insertCity(), 'date' => '2026-10-01', 'metric_id' => $metricId, 'metric_sum' => 240.5, 'metric_count' => 24];
        DB::table('air_quality_daily')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('air_quality_daily')->insert($row);
    }

    private function insertCity(): int
    {
        return DB::table('city')->insertGetId(['name' => 'Sofia', 'slug' => 'sofia', 'latitude' => 42.69751, 'longitude' => 23.32415, 'status' => 1]);
    }

    private function insertProvider(string $slug): int
    {
        return DB::table('data_provider')->insertGetId(['name' => $slug, 'slug' => $slug, 'status' => 1]);
    }

    /**
     * @return array{name: string, columns: list<string>, unique: bool}
     */
    private function index(string $table, string $name): array
    {
        $index = Arr::first(Schema::getIndexes($table), fn (array $index) => $index['name'] === $name);
        $this->assertNotNull($index, "Index {$name} is missing on {$table}.");

        return $index;
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $table, string $name): array
    {
        return $this->index($table, $name)['columns'];
    }

    /**
     * @return list<list<string>>
     */
    private function allIndexColumns(string $table): array
    {
        return array_column(Schema::getIndexes($table), 'columns');
    }

    /**
     * @return array<string, string> local column => referenced table
     */
    private function foreignTables(string $table): array
    {
        return collect(Schema::getForeignKeys($table))
            ->mapWithKeys(fn (array $key) => [implode(',', $key['columns']) => $key['foreign_table']])
            ->all();
    }
}
