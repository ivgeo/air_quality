<?php

namespace App\Http\Controllers;

use App\Observability\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * GET /health: 200 for ok and degraded (the stored data is still served), 503 when down.
 * Registered outside the web middleware group, so a dead database (sessions) cannot turn it into a 500.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $report = $health->run();

        return response()->json($report, $report['status'] === HealthCheck::DOWN ? 503 : 200);
    }
}
