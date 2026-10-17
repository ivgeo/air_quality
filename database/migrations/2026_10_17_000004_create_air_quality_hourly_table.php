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
        Schema::create('air_quality_hourly', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('city_id');
            $table->unsignedInteger('data_provider_id');
            // Start of the measured hour, in UTC.
            $table->dateTime('measure_at');
            // Measured metrics keyed by App\Enums\Metric value, e.g. {"pm25": 12.3, "pm10": 20.1}.
            $table->json('data');
            $table->dateTime('created_at')->useCurrent();

            // Indexes come before the foreign keys so MySQL does not add its own single-column
            // index for city_id (the unique key already starts with it).
            $table->unique(['city_id', 'data_provider_id', 'measure_at'], 'unq_city_provider_measure');
            $table->index('data_provider_id', 'idx_air_quality_hourly_data_provider');

            $table->foreign('city_id', 'fk_air_quality_hourly_city')->references('id')->on('city');
            $table->foreign('data_provider_id', 'fk_air_quality_hourly_data_provider')->references('id')->on('data_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('air_quality_hourly');
    }
};
