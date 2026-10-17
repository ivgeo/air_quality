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
            /** First day of the period (Y-m-d, Europe/Sofia), inclusive. */
            'from' => ['bail', 'required', 'date_format:Y-m-d', $notInFuture, 'before_or_equal:to'],
            /** Last day of the period (Y-m-d, Europe/Sofia), inclusive; on or after `from`. */
            'to' => ['bail', 'required', 'date_format:Y-m-d', $notInFuture],
            /** Metric to rank by; `pm25` when omitted. */
            'metric' => [
                'bail',
                'sometimes',
                'string',
                Rule::enum(Metric::class),
                Rule::exists('quality_metric', 'slug')->where('is_active', 1),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.before_or_equal' => 'The from date must be on or before the to date.',
            'metric.exists' => 'The selected metric is not available.',
        ];
    }

    /**
     * Once the input is well formed, check the period against the days that have stored data.
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

                $this->storedPeriods = $ranking->storedPeriods($this->qualityMetric());
                $stored = $this->storedPeriods->overall;

                if ($stored === null) {
                    $validator->errors()->add('metric', "No {$this->metric()->label()} data has been collected yet.");

                    return;
                }

                $period = $this->period();
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

    public function qualityMetric(): QualityMetric
    {
        return $this->qualityMetric ??= QualityMetric::query()->where('slug', $this->metric()->value)->firstOrFail();
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
