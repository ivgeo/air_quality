<?php

namespace App\Models;

use App\Casts\ProviderStateCast;
use App\Casts\StatusCast;
use Database\Factories\DataProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('data_provider')]
#[Fillable(['name', 'slug', 'status'])]
#[WithoutTimestamps]
class DataProvider extends Model
{
    /** @use HasFactory<DataProviderFactory> */
    use HasFactory;

    /**
     * The status column defaults to 0 in the database; mirror it so a new model always casts to StatusFlags.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 0,
    ];

    /**
     * @return HasMany<AirQualityHourly, $this>
     */
    public function hourly(): HasMany
    {
        return $this->hasMany(AirQualityHourly::class);
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
            'state' => ProviderStateCast::class,
            'is_active' => 'boolean',
        ];
    }
}
