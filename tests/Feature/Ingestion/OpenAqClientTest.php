<?php

namespace Tests\Feature\Ingestion;

use App\Ingestion\Exceptions\ProviderException;
use App\Ingestion\Exceptions\ProviderNotConfiguredException;
use App\Ingestion\Providers\OpenAq\OpenAqClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HTTP to OpenAQ: the key header, the shared rate limiter, 429 handling and the time budget.
 * Sleep is faked in sync with Carbon, so waiting moves the clock the limiter and budget read.
 */
class OpenAqClientTest extends TestCase
{
    private const string KEY = 'test-secret-key';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-18 12:00:00');
        Sleep::fake(syncWithCarbon: true);
    }

    public function test_every_request_sends_the_key_as_a_header_and_never_in_the_url(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);

        $body = $this->client()->get('/v3/locations', ['iso' => 'BG']);

        $this->assertSame(['results' => []], $body);
        Http::assertSent(fn (Request $request): bool => $request->header('X-API-Key') === [self::KEY]
            && str_starts_with($request->url(), 'https://api.openaq.org/v3/locations?iso=BG')
            && ! str_contains($request->url(), self::KEY));
    }

    #[DataProvider('missingKeys')]
    public function test_a_missing_key_is_a_configuration_error(?string $key): void
    {
        $this->expectException(ProviderNotConfiguredException::class);
        $this->expectExceptionMessage('api_key');

        $this->client(['api_key' => $key]);
    }

    public static function missingKeys(): array
    {
        return ['null' => [null], 'empty' => ['']];
    }

    public function test_a_full_minute_window_waits_until_it_resets(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);
        $client = $this->client(['rate_limit' => ['per_minute' => 2, 'per_hour' => 100]]);

        foreach (range(1, 3) as $i) {
            $client->get('/v3/locations', []);
        }

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === 60.0);
        $this->assertSame(['requests' => 3, 'throttle_waits' => 1, 'waited_ms' => 60000], $client->stats());
    }

    public function test_the_limiter_is_shared_by_every_client_of_the_key(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);
        $config = ['rate_limit' => ['per_minute' => 1, 'per_hour' => 100]];

        $this->client($config)->get('/v3/locations', []);
        $this->client($config)->get('/v3/locations', []);

        Sleep::assertSleptTimes(1);
    }

    public function test_a_wait_beyond_the_time_budget_fails_without_a_request(): void
    {
        Http::fake(['*' => Http::response(['results' => []])]);
        $client = $this->client(['rate_limit' => ['per_minute' => 50, 'per_hour' => 2]]);
        $client->get('/v3/locations', []);
        $client->get('/v3/locations', []);

        try {
            $client->get('/v3/locations', []);
            $this->fail('No ProviderException was thrown.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('time budget', $e->getMessage());
        }

        Http::assertSentCount(2);
        Sleep::assertNeverSlept();
    }

    public function test_a_429_waits_for_the_reset_header_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'Too many requests'], 429, ['x-ratelimit-reset' => '7'])
            ->push(['results' => [1]]);

        $this->assertSame(['results' => [1]], $this->client()->get('/v3/locations', []));

        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    }

    public function test_the_429_wait_is_capped(): void
    {
        Http::fakeSequence()
            ->push([], 429, ['x-ratelimit-reset' => '500'])
            ->push(['results' => []]);

        $this->client(['time_budget_seconds' => 1000])->get('/v3/locations', []);

        Sleep::assertSequence([Sleep::for(65)->seconds()]);
    }

    public function test_a_429_without_the_reset_header_waits_a_minute(): void
    {
        Http::fakeSequence()
            ->push([], 429)
            ->push(['results' => []]);

        $this->client()->get('/v3/locations', []);

        Sleep::assertSequence([Sleep::for(60)->seconds()]);
    }

    public function test_a_429_wait_beyond_the_time_budget_fails_at_once(): void
    {
        Http::fake(['*' => Http::response([], 429, ['x-ratelimit-reset' => '40'])]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('time budget');

        try {
            $this->client(['time_budget_seconds' => 30])->get('/v3/locations', []);
        } finally {
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    #[DataProvider('transientStatuses')]
    public function test_exhausted_retries_report_the_status(int $status): void
    {
        Http::fake(['*' => Http::response([], $status, ['x-ratelimit-reset' => '1'])]);

        try {
            $this->client()->get('/v3/locations', []);
            $this->fail('No ProviderException was thrown.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public static function transientStatuses(): array
    {
        return ['rate limited' => [429], 'server error' => [503]];
    }

    #[DataProvider('clientErrors')]
    public function test_a_client_error_is_not_retried(int $status): void
    {
        Http::fake(['*' => Http::response(['detail' => 'bad'], $status)]);

        try {
            $this->client()->get('/v3/sensors/1/hours', ['limit' => 5000]);
            $this->fail('No ProviderException was thrown.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString("HTTP {$status}", $e->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public static function clientErrors(): array
    {
        return ['unauthorized' => [401], 'unprocessable' => [422]];
    }

    public function test_a_timeout_is_retried_and_then_reported(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);

        try {
            $this->client()->get('/v3/locations', []);
            $this->fail('No ProviderException was thrown.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_a_body_that_is_not_a_json_object_is_rejected(): void
    {
        Http::fake(['*' => Http::response('<html>maintenance</html>')]);

        $this->expectException(ProviderException::class);

        $this->client()->get('/v3/locations', []);
    }

    public function test_the_key_never_reaches_the_logs(): void
    {
        /** @var list<string> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        Http::fakeSequence()
            ->push([], 429, ['x-ratelimit-reset' => '1'])
            ->push(['results' => []])
            ->push(['results' => []]);
        $client = $this->client(['rate_limit' => ['per_minute' => 1, 'per_hour' => 100]]);

        $client->get('/v3/locations', []);
        $client->get('/v3/locations', []);

        $this->assertNotEmpty($logged);
        $this->assertStringContainsString('OpenAQ rate limited (429)', implode("\n", $logged));
        $this->assertStringContainsString('OpenAQ throttled', implode("\n", $logged));
        $this->assertStringNotContainsString(self::KEY, implode("\n", $logged));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(array $overrides = []): OpenAqClient
    {
        return new OpenAqClient([...config('air_quality.providers.openaq'), 'api_key' => self::KEY, ...$overrides]);
    }
}
