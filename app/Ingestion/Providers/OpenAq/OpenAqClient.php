<?php

namespace App\Ingestion\Providers\OpenAq;

use App\Ingestion\Exceptions\ProviderException;
use App\Ingestion\Exceptions\ProviderNotConfiguredException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * HTTP to the OpenAQ v3 API for one ingestion run.
 *
 * The free tier allows 60 requests a minute and 2000 an hour per key, so every request first
 * takes a slot from a limiter shared by all workers (the default cache store) and waits for a
 * full window rather than relying on 429s. A 429 still waits the x-ratelimit-reset seconds. No
 * wait or request starts past the run's time budget, so the run fails cleanly before the job
 * timeout kills it. The key travels in a header, never in a URL, a message or a log line.
 */
class OpenAqClient
{
    /**
     * Limiter key => [rate_limit setting, window in seconds]; the key is per API key in effect,
     * since one key is configured.
     */
    private const array WINDOWS = [
        'openaq:minute' => ['per_minute', 60],
        'openaq:hour' => ['per_hour', 3600],
    ];

    /**
     * The wait after a 429 without a usable x-ratelimit-reset header (a minute window).
     */
    private const int DEFAULT_RESET_SECONDS = 60;

    private const int SERVER_ERROR_SLEEP_SECONDS = 1;

    private readonly string $apiKey;

    private readonly CarbonImmutable $deadline;

    private int $requests = 0;

    private int $throttleWaits = 0;

    private int $waitedMs = 0;

    /**
     * @param  array{url: string, api_key: ?string, timeout: int, connect_timeout: int, retries: int, max_wait_seconds: int, rate_limit: array{per_minute: int, per_hour: int}, time_budget_seconds: int}  $config
     *
     * @throws ProviderNotConfiguredException without an API key
     */
    public function __construct(private readonly array $config)
    {
        if (! is_string($config['api_key'] ?? null) || $config['api_key'] === '') {
            throw ProviderNotConfiguredException::missingSetting('openaq', 'api_key', 'OPENAQ_API_KEY');
        }

        $this->apiKey = $config['api_key'];
        $this->deadline = CarbonImmutable::now()->addSeconds($config['time_budget_seconds']);
    }

    /**
     * GET a v3 endpoint and return the decoded JSON object.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws ProviderException
     */
    public function get(string $path, array $query): array
    {
        for ($attempt = 0; ; $attempt++) {
            $this->takeSlot();
            $this->requests++;

            try {
                $response = $this->request($path, $query);
            } catch (ConnectionException $e) {
                if ($attempt >= $this->config['retries']) {
                    throw new ProviderException('Could not reach OpenAQ: '.$e->getMessage(), previous: $e);
                }

                $this->wait(self::SERVER_ERROR_SLEEP_SECONDS, 'connection error');

                continue;
            }

            if ($response->successful()) {
                return $this->decode($response);
            }

            $transient = $response->serverError() || $response->tooManyRequests();
            $reset = $response->tooManyRequests() ? $this->resetSeconds($response) : null;

            if ($reset !== null) {
                Log::warning('OpenAQ rate limited (429)', ['path' => $path, 'attempt' => $attempt + 1, 'reset_seconds' => $reset]);
            }

            if (! $transient || $attempt >= $this->config['retries']) {
                throw new ProviderException(sprintf(
                    'OpenAQ responded with HTTP %d%s',
                    $response->status(),
                    $reset !== null ? " after {$this->requests} requests (x-ratelimit-reset {$reset} s)." : '.',
                ));
            }

            $this->wait($reset ?? self::SERVER_ERROR_SLEEP_SECONDS, "HTTP {$response->status()}");
        }
    }

    /**
     * What this run cost, for the run's summary log line.
     *
     * @return array{requests: int, throttle_waits: int, waited_ms: int}
     */
    public function stats(): array
    {
        return ['requests' => $this->requests, 'throttle_waits' => $this->throttleWaits, 'waited_ms' => $this->waitedMs];
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     */
    private function request(string $path, array $query): Response
    {
        return Http::baseUrl($this->config['url'])
            ->acceptJson()
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->timeout($this->config['timeout'])
            ->connectTimeout($this->config['connect_timeout'])
            ->get($path, $query);
    }

    /**
     * Wait until every window has room, then count the request in each. Checking and counting are
     * not atomic together; the margin below the provider's limits covers concurrent workers.
     */
    private function takeSlot(): void
    {
        foreach (self::WINDOWS as $key => [$setting]) {
            $limit = $this->config['rate_limit'][$setting];

            while (RateLimiter::tooManyAttempts($key, $limit)) {
                $seconds = max(1, RateLimiter::availableIn($key));
                Log::info('OpenAQ throttled', ['window' => $setting, 'limit' => $limit, 'wait_seconds' => $seconds]);
                $this->wait($seconds, "the {$setting} rate limit");
                $this->throttleWaits++;
            }
        }

        if (CarbonImmutable::now()->greaterThanOrEqualTo($this->deadline)) {
            throw new ProviderException(sprintf('OpenAQ time budget of %d s is used up after %d requests.', $this->config['time_budget_seconds'], $this->requests));
        }

        foreach (self::WINDOWS as $key => [, $window]) {
            RateLimiter::hit($key, $window);
        }
    }

    /**
     * @throws ProviderException when the wait would end past the time budget
     */
    private function wait(int $seconds, string $reason): void
    {
        if (CarbonImmutable::now()->addSeconds($seconds)->greaterThan($this->deadline)) {
            throw new ProviderException(sprintf(
                'OpenAQ time budget of %d s would be exceeded waiting %d s for %s, after %d requests.',
                $this->config['time_budget_seconds'],
                $seconds,
                $reason,
                $this->requests,
            ));
        }

        Sleep::for($seconds)->seconds();
        $this->waitedMs += $seconds * 1000;
    }

    private function resetSeconds(Response $response): int
    {
        $reset = $response->header('x-ratelimit-reset');

        if (! is_numeric($reset) || (int) $reset < 0) {
            return self::DEFAULT_RESET_SECONDS;
        }

        return min(max(1, (int) $reset), $this->config['max_wait_seconds']);
    }

    /**
     * @return array<mixed>
     */
    private function decode(Response $response): array
    {
        /** @var mixed $body untrusted decoded JSON, checked by the caller */
        $body = $response->json();

        if (! is_array($body)) {
            throw new ProviderException('OpenAQ returned a body that is not a JSON object.');
        }

        return $body;
    }
}
