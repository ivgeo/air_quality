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
        Schema::create('data_provider', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 32);
            $table->string('slug', 32);
            $table->unsignedInteger('status')->default(0);
            // Derived from the ACTIVE bit (1) of the status bitmask; never written by the application.
            $table->unsignedTinyInteger('is_active')->virtualAs('(status | 1) = status')->nullable(false);

            $table->unique('slug', 'unq_data_provider_slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_provider');
    }
};
