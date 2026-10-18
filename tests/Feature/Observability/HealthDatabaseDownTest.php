<?php

namespace Tests\Feature\Observability;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Points the connection at a database that does not exist, so every query fails at once
 * (MySQL error 1049) instead of waiting for a timeout. No RefreshDatabase: there is no database.
 */
class HealthDatabaseDownTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.mysql.database' => 'air_quality_missing']);
        DB::purge('mysql');
    }

    public function test_an_unreachable_database_is_down_with_503(): void
    {
        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('checks.database.status', 'down')
            ->assertJsonPath('checks.freshness', null)
            ->assertJsonPath('checks.metrics_store.status', 'ok');
    }

    public function test_the_metrics_page_loads_without_the_database(): void
    {
        $this->get('/metrics')
            ->assertOk()
            ->assertViewIs('metrics');
    }
}
