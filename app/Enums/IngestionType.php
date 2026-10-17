<?php

namespace App\Enums;

/**
 * Why an ingestion run was started; stored in `ingestion_runs.type`.
 */
enum IngestionType: string
{
    // Hourly refresh of the most recent days.
    case Scheduled = 'scheduled';
    // Operator-triggered fill of the history.
    case Backfill = 'backfill';
}
