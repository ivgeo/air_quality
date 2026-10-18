<?php

namespace App\Providers;

use App\Observability\CircuitBreakingApiMetricsStore;
use App\Observability\Contracts\ApiMetricsStore;
use App\Observability\RedisApiMetricsStore;
use App\Support\LocalCalendar;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LocalCalendar::class, fn (): LocalCalendar => new LocalCalendar((string) config('air_quality.timezone')));
        $this->app->singleton(ApiMetricsStore::class, fn (Application $app): ApiMetricsStore => new CircuitBreakingApiMetricsStore(
            new RedisApiMetricsStore($app->make(RedisFactory::class), (string) config('air_quality.metrics.redis_connection')),
            storage_path('framework/cache/api-metrics-unavailable'),
            (int) config('air_quality.metrics.retry_after_seconds'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The API docs (/docs/api) are public in every environment, so they can be reviewed anywhere.
        Gate::define('viewApiDocs', fn (mixed $user = null): bool => true);
    }
}
