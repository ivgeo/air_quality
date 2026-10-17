<?php

namespace App\Enums;

/**
 * Result of an ingestion run; stored in `ingestion_runs.outcome`.
 */
enum IngestionOutcome: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
