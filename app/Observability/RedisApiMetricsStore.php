<?php

namespace App\Observability;

use App\Observability\Contracts\ApiMetricsStore;
use App\Observability\Data\ApiMetricsSnapshot;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Keeps the API counters in one Redis hash. A request is recorded by a single Lua script, so the
 * counters stay consistent under concurrent requests and cost one round trip.
 */
class RedisApiMetricsStore implements ApiMetricsStore
{
    private const string KEY = 'api_metrics';

    /**
     * KEYS[1] = hash; ARGV = status class field, duration in µs, then the field names.
     */
    private const string RECORD_SCRIPT = <<<'LUA'
        redis.call('HINCRBY', KEYS[1], ARGV[3], 1)
        redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
        redis.call('HINCRBY', KEYS[1], ARGV[4], ARGV[2])
        local max = tonumber(redis.call('HGET', KEYS[1], ARGV[5]) or '0')
        if tonumber(ARGV[2]) > max then
            redis.call('HSET', KEYS[1], ARGV[5], ARGV[2])
        end
        return 1
        LUA;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connection,
    ) {}

    public function record(int $status, int $durationUs): void
    {
        $this->connection()->eval(
            self::RECORD_SCRIPT,
            1,
            self::KEY,
            ApiMetricsSnapshot::statusClass($status),
            max(0, $durationUs),
            ApiMetricsSnapshot::REQUESTS,
            ApiMetricsSnapshot::DURATION_SUM_US,
            ApiMetricsSnapshot::DURATION_MAX_US,
        );
    }

    public function snapshot(): ApiMetricsSnapshot
    {
        /** @var array<string, string> $counters */
        $counters = $this->connection()->hgetall(self::KEY) ?: [];

        return ApiMetricsSnapshot::fromCounters($counters);
    }

    public function ping(): bool
    {
        try {
            return (bool) $this->connection()->ping();
        } catch (Throwable) {
            return false;
        }
    }

    private function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }
}
