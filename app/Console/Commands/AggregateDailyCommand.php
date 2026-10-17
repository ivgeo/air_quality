<?php

namespace App\Console\Commands;

use App\Aggregation\DailyAggregator;
use App\Models\AirQualityDaily;
use App\Models\AirQualityHourly;
use App\Models\City;
use App\Support\LocalCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

#[Signature('air-quality:aggregate
    {--from= : First local day, Y-m-d (default: the first stored day)}
    {--to= : Last local day, Y-m-d (default: the last stored day)}
    {--city= : City slug, inactive cities included (default: every city)}')]
#[Description('Recompute the daily sums from the stored hourly data, day by day; safe to repeat')]
class AggregateDailyCommand extends Command
{
    public function handle(DailyAggregator $aggregator, LocalCalendar $calendar): int
    {
        try {
            $from = $this->dateOption('from');
            $to = $this->dateOption('to');
            $cities = $this->cities();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($from !== null && $to !== null && $from > $to) {
            $this->components->error("--from ({$from}) must not be after --to ({$to}).");

            return self::FAILURE;
        }

        // Daily rows count too, so stale ones outside the hourly data are deleted.
        $stored = $this->storedRange($cities, $calendar);
        $from ??= $stored[0] ?? null;
        $to ??= $stored[1] ?? null;

        if ($from === null || $to === null || $from > $to) {
            $this->components->warn('Nothing to aggregate: no stored data in the requested period.');

            return self::SUCCESS;
        }

        $days = 0;
        $rows = 0;

        try {
            foreach ($cities as $city) {
                $result = $aggregator->aggregate($city, CarbonImmutable::parse($from), CarbonImmutable::parse($to));
                // The same period for every city.
                $days = $result->days;
                $rows += $result->rowsWritten;
            }
        } catch (Throwable $e) {
            Log::error('Daily aggregation failed', ['city' => $city->slug, 'from' => $from, 'to' => $to, 'error' => $e->getMessage()]);
            $this->components->error("Aggregation of {$city->slug} failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info(sprintf('Aggregated %d city(ies) over %d day(s) (%s to %s): %d daily row(s) written.', $cities->count(), $days, $from, $to, $rows));

        return self::SUCCESS;
    }

    /**
     * @return string|null the date as Y-m-d, or null when the option is not given
     *
     * @throws InvalidArgumentException
     */
    private function dateOption(string $name): ?string
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException(sprintf('--%s must be a date in the Y-m-d format, "%s" given.', $name, $value));
        }

        return $value;
    }

    /**
     * Statuses do not affect the aggregation, so inactive cities are included.
     *
     * @return Collection<int, City>
     *
     * @throws InvalidArgumentException
     */
    private function cities(): Collection
    {
        $slug = $this->option('city');
        /** @var Collection<int, City> $cities */
        $cities = City::query()->when($slug !== null, fn (Builder $query): Builder => $query->where('slug', $slug))->orderBy('id')->get();

        if ($slug !== null && $cities->isEmpty()) {
            throw new InvalidArgumentException(sprintf('Unknown city "%s".', $slug));
        }

        return $cities;
    }

    /**
     * The first and last local day with hourly or daily rows of the cities.
     *
     * @param  Collection<int, City>  $cities
     * @return array{string, string}|null Y-m-d dates, or null without any stored rows
     */
    private function storedRange(Collection $cities, LocalCalendar $calendar): ?array
    {
        $ids = $cities->modelKeys();
        /** @var object{first: string|null, last: string|null} $hourly */
        $hourly = AirQualityHourly::query()->whereIn('city_id', $ids)->toBase()->selectRaw('MIN(measure_at) AS first, MAX(measure_at) AS last')->first();
        /** @var object{first: string|null, last: string|null} $daily */
        $daily = AirQualityDaily::query()->whereIn('city_id', $ids)->toBase()->selectRaw('MIN(date) AS first, MAX(date) AS last')->first();
        /** @var list<string> $dates */
        $dates = [];

        foreach ([$hourly->first, $hourly->last] as $moment) {
            if ($moment !== null) {
                $dates[] = $calendar->dateOf(CarbonImmutable::parse($moment, 'UTC'))->toDateString();
            }
        }

        foreach ([$daily->first, $daily->last] as $date) {
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        return $dates === [] ? null : [min($dates), max($dates)];
    }
}
