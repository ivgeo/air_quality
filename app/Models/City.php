<?php

namespace App\Models;

use App\Casts\StatusCast;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('city')]
#[Fillable(['name', 'slug', 'latitude', 'longitude', 'status'])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    /**
     * @return HasMany<AirQualityHourly, $this>
     */
    public function hourly(): HasMany
    {
        return $this->hasMany(AirQualityHourly::class);
    }

    /**
     * @return HasMany<AirQualityDaily, $this>
     */
    public function daily(): HasMany
    {
        return $this->hasMany(AirQualityDaily::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'status' => StatusCast::class,
            'is_active' => 'boolean',
        ];
    }
}
