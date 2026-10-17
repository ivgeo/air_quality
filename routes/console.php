<?php

use Illuminate\Support\Facades\Schedule;

// Re-fetch the most recent days every hour; the jobs themselves never overlap per provider and city.
Schedule::command('air-quality:ingest')->hourly()->withoutOverlapping();
