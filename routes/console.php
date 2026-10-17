<?php

use Illuminate\Support\Facades\Schedule;

// Re-fetch the most recent days every hour; the jobs themselves never overlap per provider and city.
// The command only queues jobs, so its mutex expires after 10 minutes rather than the default 24 hours.
Schedule::command('air-quality:ingest')->hourly()->withoutOverlapping(10);
