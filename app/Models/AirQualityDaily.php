<?php

namespace App\Models;

use Database\Factories\AirQualityDailyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sum and count of one metric for one city over one Europe/Sofia calendar day, across all providers.
 */
#[Table('air_quality_daily')]
#[Fillable(['date', 'city_id', 'metric_id', 'metric_sum', 'metric_count'])]
class AirQualityDaily extends Model
{
    /** @use HasFactory<AirQualityDailyFactory> */
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
     * @return BelongsTo<QualityMetric, $this>
     */
    public function metric(): BelongsTo
    {
        return $this->belongsTo(QualityMetric::class, 'metric_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'metric_sum' => 'decimal:5',
            'metric_count' => 'integer',
        ];
    }
}
