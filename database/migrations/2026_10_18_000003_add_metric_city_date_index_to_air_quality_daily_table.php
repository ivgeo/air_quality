<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first and last stored day per city of a metric (CityRanking::storedPeriods(), run by every
 * ranking request): MIN/MAX(date) per (metric_id, city_id) by a loose index scan, two index
 * entries per city instead of the metric's whole history.
 *
 * Covering, because MySQL also picks it for long ranking periods (grouped by city without a
 * temporary table): without the sums every entry was a row lookup, and the 5-year ranking of 100
 * cities took 128 ms instead of 54 ms; with them it takes 29 ms.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('air_quality_daily', function (Blueprint $table): void {
            $table->index(['metric_id', 'city_id', 'date', 'metric_sum', 'metric_count'], 'idx_air_quality_daily_metric_city_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('air_quality_daily', function (Blueprint $table): void {
            $table->dropIndex('idx_air_quality_daily_metric_city_date');
        });
    }
};
