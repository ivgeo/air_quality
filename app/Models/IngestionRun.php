<?php

namespace App\Models;

use App\Enums\IngestionOutcome;
use App\Enums\IngestionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal entry for fetching one provider's hourly data for one city over one period (UTC).
 */
#[Table('ingestion_runs')]
#[Fillable(['data_provider_id', 'city_id', 'type', 'period_from', 'period_to', 'outcome', 'rows_written', 'rows_rejected', 'error', 'started_at', 'finished_at', 'duration_ms'])]
#[WithoutTimestamps]
class IngestionRun extends Model
{
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
            'type' => IngestionType::class,
            'outcome' => IngestionOutcome::class,
            'period_from' => 'immutable_datetime',
            'period_to' => 'immutable_datetime',
            'rows_written' => 'integer',
            'rows_rejected' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
        ];
    }
}
