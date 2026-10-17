<?php

namespace App\Models;

use App\Casts\StatusCast;
use Database\Factories\QualityMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Metric nomenclature; every App\Enums\Metric case has a row with the same slug.
 */
#[Table('quality_metric')]
#[Fillable(['name', 'slug', 'status'])]
class QualityMetric extends Model
{
    /** @use HasFactory<QualityMetricFactory> */
    use HasFactory;

    /**
     * The status column defaults to 0 in the database; mirror it so a new model always casts to StatusFlags.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 0,
    ];

    public const ?string UPDATED_AT = null;

    /**
     * @return HasMany<AirQualityDaily, $this>
     */
    public function daily(): HasMany
    {
        return $this->hasMany(AirQualityDaily::class, 'metric_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StatusCast::class,
            'is_active' => 'boolean',
        ];
    }
}
