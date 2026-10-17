<?php

namespace App\Http\Resources;

use App\Ranking\Ranking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The ranking entries, cleanest first, plus the request metadata.
 */
class RankingCollection extends ResourceCollection
{
    public $collects = RankingEntryResource::class;

    public function __construct(private readonly Ranking $ranking)
    {
        parent::__construct($ranking->entries);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'period' => [...$this->ranking->period->toDateStrings(), 'days' => $this->ranking->period->days()],
                'metric' => [
                    'slug' => $this->ranking->metric->value,
                    'label' => $this->ranking->metric->label(),
                    'unit' => $this->ranking->metric->unit(),
                ],
                /** First and last day any active city has stored data for. */
                'stored' => $this->ranking->stored->toDateStrings(),
            ],
        ];
    }
}
