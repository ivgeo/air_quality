<?php

namespace App\Providers;

use App\Support\LocalCalendar;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LocalCalendar::class, fn (): LocalCalendar => new LocalCalendar((string) config('air_quality.timezone')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
