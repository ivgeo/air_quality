<?php

namespace App\Http\Middleware;

use App\Observability\Contracts\ApiMetricsStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Logs every API request and counts it in the metrics store (except the untracked routes).
 *
 * The work happens in terminate(), after the response has been sent (fastcgi_finish_request), so
 * a slow or unavailable store never delays or breaks a response.
 */
class RecordApiMetrics
{
    private const string DURATION_US = 'api_metrics.duration_us';

    public function __construct(private readonly ApiMetricsStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        /** @var Response $response */
        $response = $next($request);
        $request->attributes->set(self::DURATION_US, intdiv(hrtime(true) - $startedAt, 1000));

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $durationUs = $request->attributes->get(self::DURATION_US);

        if (! is_int($durationUs)) {
            return;
        }

        $route = $request->route()?->getName();
        $status = $response->getStatusCode();

        Log::info('API request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => $route,
            'status' => $status,
            'duration_ms' => round($durationUs / 1000, 2),
        ]);

        if (in_array($route, (array) config('air_quality.metrics.untracked_routes'), true)) {
            return;
        }

        try {
            $this->store->record($status, $durationUs);
        } catch (Throwable $e) {
            Log::warning('API metrics not recorded', ['route' => $route, 'error' => $e->getMessage()]);
        }
    }
}
