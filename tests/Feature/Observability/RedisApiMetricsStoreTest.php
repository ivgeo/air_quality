<?php

namespace Tests\Feature\Observability;

use App\Observability\RedisApiMetricsStore;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Runs against the real Redis container, on database 15 (phpunit.xml), which is flushed before
 * every test; skipped when Redis does not answer.
 */
class RedisApiMetricsStoreTest extends TestCase
{
    private const int TEST_DATABASE = 15;

    private RedisApiMetricsStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        if ((int) config('database.redis.metrics.database') !== self::TEST_DATABASE) {
            throw new RuntimeException('The metrics Redis connection must use database 15 in tests; check phpunit.xml.');
        }

        try {
            Redis::connection('metrics')->flushdb();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: '.$e->getMessage());
        }

        $this->store = new RedisApiMetricsStore($this->app->make(RedisFactory::class), 'metrics');
    }

    public function test_requests_are_counted_per_status_class_with_their_latency(): void
    {
        $this->store->record(200, 10_000);
        $this->store->record(404, 2_000);
        $this->store->record(500, 30_000);

        $snapshot = $this->store->snapshot();

        $this->assertSame(3, $snapshot->requests);
        $this->assertSame(2, $snapshot->errors());
        $this->assertSame(['2xx' => 1, '3xx' => 0, '4xx' => 1, '5xx' => 1], $snapshot->byStatus);
        $this->assertSame(42_000, $snapshot->durationSumUs);
        $this->assertSame(14.0, $snapshot->averageLatencyMs());
        $this->assertSame(30.0, $snapshot->maxLatencyMs());
    }

    public function test_the_maximum_only_grows(): void
    {
        $this->store->record(200, 5_000);
        $this->store->record(200, 3_000);
        $this->assertSame(5_000, $this->store->snapshot()->durationMaxUs);

        $this->store->record(200, 8_000);
        $this->assertSame(8_000, $this->store->snapshot()->durationMaxUs);
    }

    public function test_an_empty_store_gives_zeros(): void
    {
        $snapshot = $this->store->snapshot();

        $this->assertSame(0, $snapshot->requests);
        $this->assertSame(0, $snapshot->errors());
        $this->assertSame(['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0], $snapshot->byStatus);
        $this->assertNull($snapshot->averageLatencyMs());
        $this->assertNull($snapshot->maxLatencyMs());
    }

    public function test_ping_answers_true(): void
    {
        $this->assertTrue($this->store->ping());
    }

    public function test_an_unreachable_redis_fails_ping_and_record(): void
    {
        config(['database.redis.metrics_missing' => [...config('database.redis.metrics'), 'host' => '127.0.0.1', 'port' => 1]]);
        $store = new RedisApiMetricsStore($this->app->make(RedisFactory::class), 'metrics_missing');

        $this->assertFalse($store->ping());

        $this->expectException(Throwable::class);
        $store->record(200, 1_000);
    }
}
