<?php

namespace Tests\Feature\Database;

use App\Enums\Metric;
use App\Enums\Status;
use App\Models\City;
use App\Models\DataProvider;
use App\Models\QualityMetric;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_cities_inside_bulgaria(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(['sofia', 'plovdiv', 'varna', 'burgas', 'ruse'], City::pluck('slug')->all());

        foreach (City::all() as $city) {
            $this->assertTrue($city->status->isActive(), "{$city->slug} is not active.");
            $this->assertTrue($city->is_active);
            $this->assertGreaterThanOrEqual(41.2, $city->latitude);
            $this->assertLessThanOrEqual(44.3, $city->latitude);
            $this->assertGreaterThanOrEqual(22.3, $city->longitude);
            $this->assertLessThanOrEqual(28.7, $city->longitude);
        }
    }

    public function test_it_seeds_open_meteo_as_an_active_provider(): void
    {
        $this->seed(DatabaseSeeder::class);

        $provider = DataProvider::where('slug', 'open-meteo')->sole();

        $this->assertSame('open-meteo', $provider->slug);
        $this->assertSame('Open-Meteo', $provider->name);
        $this->assertTrue($provider->is_active);
    }

    public function test_it_seeds_openaq_as_active_when_its_api_key_is_set(): void
    {
        config(['air_quality.providers.openaq.api_key' => 'test-key']);

        $this->seed(DatabaseSeeder::class);

        $provider = DataProvider::where('slug', 'openaq')->sole();
        $this->assertSame('OpenAQ', $provider->name);
        $this->assertTrue($provider->is_active);
    }

    public function test_it_seeds_openaq_as_inactive_without_an_api_key(): void
    {
        config(['air_quality.providers.openaq.api_key' => null]);

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(DataProvider::where('slug', 'openaq')->sole()->is_active);
        $this->assertTrue(DataProvider::where('slug', 'open-meteo')->sole()->is_active);
    }

    public function test_a_key_added_later_does_not_activate_openaq(): void
    {
        config(['air_quality.providers.openaq.api_key' => '']);
        $this->seed(DatabaseSeeder::class);

        config(['air_quality.providers.openaq.api_key' => 'test-key']);
        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(DataProvider::where('slug', 'openaq')->sole()->is_active);
    }

    public function test_every_metric_case_has_an_active_nomenclature_row(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(
            array_column(Metric::cases(), 'value'),
            QualityMetric::pluck('slug')->all(),
        );

        foreach (Metric::cases() as $metric) {
            $row = QualityMetric::where('slug', $metric->value)->sole();

            $this->assertSame($metric->label(), $row->name);
            $this->assertTrue($row->is_active);
        }
    }

    public function test_seeding_twice_creates_no_duplicates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, City::count());
        $this->assertSame(2, DataProvider::count());
        $this->assertSame(count(Metric::cases()), QualityMetric::count());
    }

    public function test_reseeding_keeps_records_deactivated_by_an_operator(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([City::where('slug', 'varna')->sole(), DataProvider::where('slug', 'open-meteo')->sole(), QualityMetric::where('slug', Metric::Pm10->value)->sole()] as $model) {
            $model->status = $model->status->without(Status::Active);
            $model->save();
        }

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(City::where('slug', 'varna')->sole()->is_active);
        $this->assertTrue(City::where('slug', 'sofia')->sole()->is_active);
        $this->assertFalse(DataProvider::where('slug', 'open-meteo')->sole()->is_active);
        $this->assertFalse(QualityMetric::where('slug', Metric::Pm10->value)->sole()->is_active);
    }

    public function test_reseeding_restores_the_name_and_coordinates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $original = City::where('slug', 'ruse')->sole();

        City::whereKey($original->id)->update(['name' => 'Rousse', 'latitude' => 10, 'longitude' => 10]);

        $this->seed(DatabaseSeeder::class);

        $city = City::where('slug', 'ruse')->sole();
        $this->assertSame($original->name, $city->name);
        $this->assertSame($original->latitude, $city->latitude);
        $this->assertSame($original->longitude, $city->longitude);
    }
}
