<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('air_quality_daily', function (Blueprint $table): void {
            $table->increments('id');
            // Calendar day in Europe/Sofia.
            $table->date('date');
            $table->unsignedInteger('city_id');
            $table->unsignedInteger('metric_id');
            // Sum and count instead of an average, so any period can be aggregated exactly.
            $table->decimal('metric_sum', 15, 5);
            $table->unsignedInteger('metric_count');
            $table->dateTime('created_at')->useCurrent();

            // Indexes come before the foreign keys so MySQL does not add its own single-column
            // indexes for city_id and metric_id (each leads one of these indexes).
            $table->unique(['city_id', 'date', 'metric_id'], 'unq_city_date_metric');
            // Covering index for the ranking query: range on (metric_id, date), the rest read from the index.
            $table->index(['metric_id', 'date', 'city_id', 'metric_sum', 'metric_count'], 'idx_air_quality_daily_metric_date_city');

            $table->foreign('city_id', 'fk_air_quality_daily_city')->references('id')->on('city');
            $table->foreign('metric_id', 'fk_air_quality_daily_quality_metric')->references('id')->on('quality_metric');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('air_quality_daily');
    }
};
