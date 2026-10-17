<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RankingRequest;
use App\Http\Resources\RankingCollection;
use App\Ranking\CityRanking;

class RankingController extends Controller
{
    /**
     * Rank cities by average air pollution.
     *
     * Ranks the active cities from the cleanest (lowest average of the metric) to the most polluted
     * over an inclusive period of Europe/Sofia calendar days. Served from stored data only.
     */
    public function __invoke(RankingRequest $request, CityRanking $ranking): RankingCollection
    {
        return new RankingCollection($ranking->rank($request->qualityMetric(), $request->period(), $request->storedPeriods()));
    }
}
