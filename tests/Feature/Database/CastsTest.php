<?php

namespace Tests\Feature\Database;

use App\Data\Measurements;
use App\Data\StatusFlags;
use App\Enums\Metric;
use App\Enums\Status;
use App\Models\AirQualityHourly;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class CastsTest extends TestCase
{
    use RefreshDatabase;

    public function test_measurements_are_stored_as_a_json_object_keyed_by_metric(): void
    {
        $hourly = AirQualityHourly::factory()->create([
            'data' => Measurements::empty()->with(Metric::Pm25, 12.3)->with(Metric::Pm10, 20.1),
        ]);

        $raw = DB::table('air_quality_hourly')->where('id', $hourly->id)->value('data');

        $this->assertEquals(['pm25' => 12.3, 'pm10' => 20.1], json_decode($raw, true));
    }

    public function test_measurements_survive_a_round_trip_through_the_database(): void
    {
        $measurements = Measurements::empty()->with(Metric::Pm25, 0.0)->with(Metric::Pm10, 20.1);
        $hourly = AirQualityHourly::factory()->create(['data' => $measurements]);

        $stored = $hourly->refresh()->data;

        $this->assertInstanceOf(Measurements::class, $stored);
        $this->assertEquals($measurements, $stored);
        $this->assertSame(0.0, $stored->get(Metric::Pm25));
    }

    public function test_a_missing_metric_stays_missing_after_a_round_trip(): void
    {
        $hourly = AirQualityHourly::factory()->create(['data' => Measurements::empty()->with(Metric::Pm10, 20.1)]);

        $this->assertNull($hourly->refresh()->data->get(Metric::Pm25));
    }

    public function test_empty_measurements_are_stored_as_an_empty_json_object(): void
    {
        $hourly = AirQualityHourly::factory()->create(['data' => Measurements::empty()]);

        $this->assertSame('{}', DB::table('air_quality_hourly')->where('id', $hourly->id)->value('data'));
        $this->assertTrue($hourly->refresh()->data->isEmpty());
    }

    public function test_assigning_anything_but_measurements_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $hourly = new AirQualityHourly;
        $hourly->data = ['pm25' => 12.3];
    }

    public function test_status_flags_are_stored_as_the_bitmask(): void
    {
        $city = City::factory()->create(['status' => StatusFlags::of(Status::Active)]);

        $this->assertStoredStatus($city, 1, 1);
        $this->assertTrue($city->refresh()->status->isActive());
    }

    public function test_clearing_the_active_flag_deactivates_the_record(): void
    {
        $city = City::factory()->create(['status' => StatusFlags::of(Status::Active)]);

        $city->status = $city->status->without(Status::Active);
        $city->save();

        $this->assertStoredStatus($city, 0, 0);
        $this->assertFalse($city->refresh()->status->isActive());
    }

    public function test_a_single_status_flag_can_be_assigned_directly(): void
    {
        $city = City::factory()->inactive()->create();

        $city->status = Status::Active;
        $city->save();

        $this->assertStoredStatus($city, 1, 1);
    }

    public function test_assigning_a_raw_status_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $city = new City;
        $city->status = 1;
    }

    private function assertStoredStatus(City $city, int $status, int $isActive): void
    {
        $row = DB::table('city')->where('id', $city->id)->first(['status', 'is_active']);

        $this->assertSame($status, (int) $row->status);
        $this->assertSame($isActive, (int) $row->is_active);
    }
}
