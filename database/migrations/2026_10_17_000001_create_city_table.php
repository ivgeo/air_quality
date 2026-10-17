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
        Schema::create('city', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 64);
            $table->string('slug', 32);
            $table->decimal('latitude', 8, 5);
            $table->decimal('longitude', 8, 5);
            $table->unsignedInteger('status')->default(0);
            // Derived from the ACTIVE bit (1) of the status bitmask; never written by the application.
            $table->unsignedTinyInteger('is_active')->virtualAs('(status | 1) = status')->nullable(false);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->unique('slug', 'unq_city_slug');
            $table->unique('name', 'unq_city_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('city');
    }
};
