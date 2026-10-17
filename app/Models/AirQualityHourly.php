<?php

namespace App\Models;

use App\Casts\MeasurementsCast;
use Database\Factories\AirQualityHourlyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metrics measured by one provider for one city during one hour (measure_at, UTC).
 */
#[Table('air_quality_hourly')]
#[Fillable(['city_id', 'data_provider_id', 'measure_at', 'data'])]
class AirQualityHourly extends Model
{
    /** @use HasFactory<AirQualityHourlyFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<DataProvider, $this>
     */
    public function dataProvider(): BelongsTo
    {
        return $this->belongsTo(DataProvider::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measure_at' => 'immutable_datetime',
            'data' => MeasurementsCast::class,
        ];
    }
}
