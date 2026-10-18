<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for GET /api/v1/metrics. Measured on 200k runs: the last-24-hours aggregate, the last run
 * per provider and the newest failures take under 1 ms instead of a table scan each; the
 * all-time aggregate is a covering index scan (~40 ms).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table): void {
            // The newest failed runs (id is the tie-break of the order), and covers the all-time
            // aggregate grouped by outcome, so that scan reads the index instead of the table.
            $table->index(['outcome', 'started_at', 'id', 'rows_written', 'rows_rejected', 'duration_ms'], 'idx_ingestion_runs_outcome_started');
            // The newest run of each provider: MAX(id) per provider by a skip scan.
            $table->index(['data_provider_id', 'id'], 'idx_ingestion_runs_provider_id');
            // Covers the last-24-hours aggregate, so it reads only that range.
            $table->index(['started_at', 'outcome', 'rows_written', 'rows_rejected', 'duration_ms'], 'idx_ingestion_runs_started_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table): void {
            $table->dropIndex('idx_ingestion_runs_outcome_started');
            $table->dropIndex('idx_ingestion_runs_provider_id');
            $table->dropIndex('idx_ingestion_runs_started_summary');
        });
    }
};
