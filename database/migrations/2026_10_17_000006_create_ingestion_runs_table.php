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
        Schema::create('ingestion_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('data_provider_id');
            $table->unsignedInteger('city_id');
            // App\Enums\IngestionType value: scheduled or backfill.
            $table->string('type', 16);
            // Requested period, in UTC.
            $table->dateTime('period_from');
            $table->dateTime('period_to');
            // App\Enums\IngestionOutcome value; not called "status" to avoid confusion with the bitmask columns.
            $table->string('outcome', 16);
            $table->unsignedInteger('rows_written')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->text('error')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Indexes come before the foreign keys so MySQL does not add its own single-column
            // index for data_provider_id (the composite index already starts with it).
            // Serves "last successful run per provider and city".
            $table->index(['data_provider_id', 'city_id', 'outcome', 'finished_at'], 'idx_ingestion_runs_provider_city_outcome_finished');
            $table->index('city_id', 'idx_ingestion_runs_city');

            $table->foreign('data_provider_id', 'fk_ingestion_runs_data_provider')->references('id')->on('data_provider');
            $table->foreign('city_id', 'fk_ingestion_runs_city')->references('id')->on('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
    }
};
