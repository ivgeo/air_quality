<?php

namespace App\Http\Resources;

use App\Ranking\RankingEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property RankingEntry $resource
 */
class RankingEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            /** Position in the ranking; 1 is the cleanest city. */
            'rank' => $entry->rank,
            'city' => [
                'slug' => $entry->city->slug,
                'name' => $entry->city->name,
            ],
            /** Average over the period weighted by measurements (SUM / COUNT), rounded to 2 decimals. */
            'average' => round((float) $entry->average, 2),
            /** Number of hourly measurements in the period. */
            'measurements' => $entry->measurements,
            /** Number of days in the period with data. */
            'days_with_data' => $entry->daysWithData,
            /** First and last day with stored data for this city. */
            'stored' => $entry->stored->toDateStrings(),
        ];
    }
}
