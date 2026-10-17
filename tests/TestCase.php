<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const TEST_DATABASE = 'air_quality_test';

    /**
     * Tests never reach a real provider: any request without a matching Http::fake() fails.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Refuse to run before any trait (e.g. RefreshDatabase) touches a database other than the
     * test one, e.g. when a cached config overrides the values from phpunit.xml.
     */
    protected function setUpTraits()
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
