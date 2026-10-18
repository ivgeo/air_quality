<?php

namespace App\Http\Controllers;

use App\Enums\Metric;
use App\Models\QualityMetric;
use App\Support\LocalCalendar;
use Illuminate\Contracts\View\View;

/**
 * The ranking web page: a thin client of the ranking API.
 *
 * Renders only what the page script cannot know by itself (the active metrics and today in
 * Europe/Sofia); the ranking and its errors come from the API.
 */
class RankingPageController extends Controller
{
    public function __invoke(LocalCalendar $calendar): View
    {
        $metrics = $this->activeMetrics();

        return view('ranking', [
            'metrics' => $metrics,
            'defaultMetric' => in_array(Metric::Pm25, $metrics, true) ? Metric::Pm25 : ($metrics[0] ?? null),
            'today' => $calendar->today()->toDateString(),
        ]);
    }

    /**
     * The active metrics in the order of the Metric cases.
     *
     * @return list<Metric>
     */
    private function activeMetrics(): array
    {
        /** @var list<string> $slugs */
        $slugs = QualityMetric::query()->where('is_active', 1)->pluck('slug')->all();

        return array_values(array_filter(
            Metric::cases(),
            fn (Metric $metric): bool => in_array($metric->value, $slugs, true),
        ));
    }
}
