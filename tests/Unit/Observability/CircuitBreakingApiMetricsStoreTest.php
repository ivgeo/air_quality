<?php

namespace Tests\Unit\Observability;

use App\Observability\CircuitBreakingApiMetricsStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Fakes\InMemoryApiMetricsStore;

class CircuitBreakingApiMetricsStoreTest extends TestCase
{
    private const int RETRY_AFTER = 30;

    private InMemoryApiMetricsStore $inner;

    private string $marker;

    private CircuitBreakingApiMetricsStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inner = new InMemoryApiMetricsStore;
        $this->marker = sys_get_temp_dir().'/api-metrics-unavailable-'.bin2hex(random_bytes(6));
        $this->store = new CircuitBreakingApiMetricsStore($this->inner, $this->marker, self::RETRY_AFTER);
    }

    protected function tearDown(): void
    {
        if (is_file($this->marker)) {
            unlink($this->marker);
        }

        parent::tearDown();
    }

    public function test_calls_pass_through_while_the_store_works(): void
    {
        $this->store->record(200, 1_000);

        $this->assertSame(1, $this->store->snapshot()->requests);
        $this->assertTrue($this->store->ping());
        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_a_failure_is_rethrown_and_later_calls_fail_without_reaching_the_store(): void
    {
        $this->inner->available = false;

        $this->assertThrows(fn () => $this->store->record(200, 1_000), 'unavailable');

        // The store is back, but the breaker keeps it alone for now.
        $this->inner->available = true;
        $this->assertThrows(fn () => $this->store->record(200, 1_000), 'not retried yet');
        $this->assertThrows(fn () => $this->store->snapshot(), 'not retried yet');
        $this->assertFalse($this->store->ping());
        $this->assertSame([], $this->inner->recorded);
    }

    public function test_a_failed_ping_opens_the_breaker(): void
    {
        $this->inner->available = false;

        $this->assertFalse($this->store->ping());

        $this->inner->available = true;
        $this->assertFalse($this->store->ping());
    }

    public function test_the_store_is_tried_again_after_the_retry_interval(): void
    {
        touch($this->marker, time() - self::RETRY_AFTER - 1);

        $this->store->record(200, 1_000);

        $this->assertCount(1, $this->inner->recorded);
        $this->assertTrue($this->store->ping());
    }

    private function assertThrows(callable $call, string $message): void
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail('Expected an exception.');
    }
}
