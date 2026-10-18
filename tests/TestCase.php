<?php

namespace Tests;

use App\Observability\Contracts\ApiMetricsStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Fakes\InMemoryApiMetricsStore;

abstract class TestCase extends BaseTestCase
{
    private const string TEST_DATABASE = 'air_quality_test';

    /**
     * Tests never reach a real provider: any request without a matching Http::fake() fails. The
     * API metrics go to an in-memory store, so no test needs Redis (except the store's own test).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->app->instance(ApiMetricsStore::class, new InMemoryApiMetricsStore);
    }

    protected function metricsStore(): InMemoryApiMetricsStore
    {
        /** @var InMemoryApiMetricsStore $store */
        $store = $this->app->make(ApiMetricsStore::class);

        return $store;
    }

    /**
     * Refuse to run before any trait (e.g. RefreshDatabase) touches a database other than the
     * test one, e.g. when a cached config overrides the values from phpunit.xml.
     */
    protected function setUpTraits(): array
    {
        $database = DB::connection()->getDatabaseName();

        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException(sprintf(
                'Tests must run against the "%s" database, but "%s" is configured. Run `make artisan ARGS="config:clear"` and check phpunit.xml.',
                self::TEST_DATABASE,
                $database,
            ));
        }

        return parent::setUpTraits();
    }
}
