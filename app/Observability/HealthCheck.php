<?php

namespace App\Observability;

use App\Enums\IngestionOutcome;
use App\Models\DataProvider;
use App\Models\IngestionRun;
use App\Observability\Contracts\ApiMetricsStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether the application can serve its stored data and whether that data is kept up to date.
 * Reads local state only (database, ingestion journal, metrics store), never a provider.
 *
 * - down: the database is unreachable;
 * - degraded: an active provider has no successful run within the freshness threshold (the
 *   stored data is still served);
 * - ok: otherwise. The metrics store is reported but never changes the status.
 */
class HealthCheck
{
    public const string OK = 'ok';

    public const string DEGRADED = 'degraded';

    public const string DOWN = 'down';

    public const string STALE = 'stale';

    public const string UNAVAILABLE = 'unavailable';

    public function __construct(private readonly ApiMetricsStore $metrics) {}

    /**
     * @return array{status: string, checked_at: string, checks: array{database: array{status: string}, freshness: array{threshold_hours: int, providers: list<array{provider: string, name: string, status: string, last_success_at: string|null}>}|null, metrics_store: array{status: string}}}
     */
    public function run(): array
    {
        $now = CarbonImmutable::now();
        $thresholdHours = (int) config('air_quality.health.freshness_hours');
        /** @var list<array{provider: string, name: string, status: string, last_success_at: string|null}>|null $providers */
        $providers = null;

        try {
            DB::select('SELECT 1');
            $providers = $this->freshness($now->subHours($thresholdHours));
        } catch (Throwable $e) {
            Log::error('Health check: database unavailable', ['error' => $e->getMessage()]);
        }

        return [
            'status' => match (true) {
                $providers === null => self::DOWN,
                in_array(self::STALE, array_column($providers, 'status'), true) => self::DEGRADED,
                default => self::OK,
            },
            'checked_at' => $now->toIso8601String(),
            'checks' => [
                'database' => ['status' => $providers === null ? self::DOWN : self::OK],
                'freshness' => $providers === null ? null : ['threshold_hours' => $thresholdHours, 'providers' => $providers],
                'metrics_store' => ['status' => $this->metrics->ping() ? self::OK : self::UNAVAILABLE],
            ],
        ];
    }

    /**
     * The latest successful run of each active provider, for any city.
     *
     * @return list<array{provider: string, name: string, status: string, last_success_at: string|null}>
     */
    private function freshness(CarbonImmutable $freshSince): array
    {
        return DataProvider::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'slug', 'name'])
            ->map(function (DataProvider $provider) use ($freshSince): array {
                /** @var string|null $finishedAt */
                $finishedAt = IngestionRun::query()
                    ->where('data_provider_id', $provider->id)
                    ->where('outcome', IngestionOutcome::Success)
                    ->max('finished_at');
                $lastSuccess = $finishedAt === null ? null : CarbonImmutable::parse($finishedAt);

                return [
                    'provider' => $provider->slug,
                    'name' => $provider->name,
                    'status' => $lastSuccess?->greaterThanOrEqualTo($freshSince) ? self::OK : self::STALE,
                    'last_success_at' => $lastSuccess?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
