<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TestDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tests must never run against the development database.
     */
    public function test_tests_use_the_dedicated_mysql_test_database(): void
    {
        $connection = DB::connection();

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('air_quality_test', $connection->getDatabaseName());
    }

    public function test_migrations_are_applied_to_the_test_database(): void
    {
        $this->assertTrue(Schema::hasTable('sessions'));
    }
}
