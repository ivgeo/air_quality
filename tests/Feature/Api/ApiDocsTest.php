<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';
    }

    public function test_the_docs_ui_is_available_outside_the_local_environment(): void
    {
        $this->get('/docs/api')->assertOk();
    }

    public function test_the_spec_is_openapi_3_1_and_describes_the_ranking_endpoint(): void
    {
        $spec = $this->getJson('/docs/api.json')->assertOk()->json();

        $this->assertStringStartsWith('3.1', $spec['openapi']);
        $operation = $spec['paths']['/v1/ranking']['get'] ?? null;
        $this->assertIsArray($operation);

        /** @var list<string> $parameters */
        $parameters = array_column($operation['parameters'], 'name');
        $this->assertEqualsCanonicalizing(['from', 'to', 'metric'], $parameters);

        $this->assertArrayHasKey('422', $operation['responses']);
        $body = $operation['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['data', 'meta'], array_keys($body['properties']));
        $this->assertSame(['period', 'metric', 'stored'], array_keys($body['properties']['meta']['properties']));
        $this->assertSame(
            ['rank', 'city', 'average', 'measurements', 'days_with_data', 'stored'],
            array_keys($spec['components']['schemas']['RankingEntryResource']['properties']),
        );
    }
}
