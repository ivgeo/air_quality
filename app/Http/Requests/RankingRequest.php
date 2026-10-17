<?php

namespace App\Http\Requests;

use App\Enums\Metric;
use App\Models\QualityMetric;
use App\Ranking\CityRanking;
use App\Ranking\DateRange;
use App\Ranking\StoredPeriods;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * A ranking period of local calendar days (Europe/Sofia) inside the stored data, and a metric.
 */
class RankingRequest extends FormRequest
{
    private ?QualityMetric $qualityMetric = null;

    private ?StoredPeriods $storedPeriods = null;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(LocalCalendar $calendar): array
    {
        $notInFuture = $this->notAfter($calendar->today()->toDateString());

        return [
            /** First day of the period (Y-m-d, Europe/Sofia), inclusive; on or before `to`. */
            'from' => ['bail', 'required', 'date_format:Y-m-d', $notInFuture],
            /** Last day of the period (Y-m-d, Europe/Sofia), inclusive. */
            'to' => ['bail', 'required', 'date_format:Y-m-d', $notInFuture],
            /** Metric to rank by; `pm25` when omitted or empty. Only active metrics are accepted. */
            'metric' => ['bail', 'sometimes', 'nullable', 'string', Rule::enum(Metric::class)],
        ];
    }

    /**
     * Once the input is well formed: the order of the dates, the metric being active (the default
     * one included) and the period against the days that have stored data.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(CityRanking $ranking): array
    {
        return [
            function (Validator $validator) use ($ranking): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $period = $this->period();

                if ($period->from->gt($period->to)) {
                    $validator->errors()->add('from', 'The from date must be on or before the to date.');

                    return;
                }

                $this->qualityMetric = QualityMetric::query()
                    ->where('slug', $this->metric()->value)
                    ->where('is_active', 1)
                    ->first();

                if ($this->qualityMetric === null) {
                    $validator->errors()->add('metric', 'The selected metric is not available.');

                    return;
                }

                $this->storedPeriods = $ranking->storedPeriods($this->qualityMetric);
                $stored = $this->storedPeriods->overall;

                if ($stored === null) {
                    $validator->errors()->add('metric', "{$this->metric()->label()} data has not been collected yet.");

                    return;
                }

                $available = "{$stored->from->toDateString()} – {$stored->to->toDateString()}";

                if ($period->from->lt($stored->from)) {
                    $validator->errors()->add('from', "The from date must be within the stored period {$available}.");
                }

                if ($period->to->gt($stored->to)) {
                    $validator->errors()->add('to', "The to date must be within the stored period {$available}.");
                }
            },
        ];
    }

    public function metric(): Metric
    {
        return $this->enum('metric', Metric::class) ?? Metric::Pm25;
    }

    /**
     * The active metric loaded while validating.
     */
    public function qualityMetric(): QualityMetric
    {
        return $this->qualityMetric ?? throw new LogicException('The request has not been validated yet.');
    }

    public function period(): DateRange
    {
        return new DateRange(
            CarbonImmutable::parse($this->string('from')->toString()),
            CarbonImmutable::parse($this->string('to')->toString()),
        );
    }

    /**
     * The stored periods read while validating, so they are not queried twice.
     */
    public function storedPeriods(): StoredPeriods
    {
        return $this->storedPeriods ?? throw new LogicException('The request has not been validated yet.');
    }

    /**
     * Rejects a Y-m-d date after `$last`.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function notAfter(string $last): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($last): void {
            if ((string) $value > $last) {
                $fail("The {$attribute} date must not be in the future (today is {$last}).");
            }
        };
    }
}
