<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime state a provider discovers and keeps itself (e.g. OpenAQ's stations per city), as
 * opposed to its config, which says how to talk to it. A provider without state keeps `{}`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('data_provider', function (Blueprint $table): void {
            $table->json('state')->default(new Expression('(JSON_OBJECT())'))->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_provider', function (Blueprint $table): void {
            $table->dropColumn('state');
        });
    }
};
