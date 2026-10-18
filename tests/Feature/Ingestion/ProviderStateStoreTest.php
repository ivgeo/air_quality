<?php

namespace Tests\Feature\Ingestion;

use App\Ingestion\Data\NoProviderState;
use App\Ingestion\DatabaseProviderStateStore;
use App\Models\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Fakes\FakeAirQualityProvider;
use Tests\Fakes\FakeProviderState;
use Tests\TestCase;

/**
 * The `data_provider.state` cast (state class chosen by the row's slug) and the per-city writes.
 */
class ProviderStateStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['air_quality.providers.fake' => ['class' => FakeAirQualityProvider::class]]);
    }

    public function test_a_provider_without_state_reads_the_empty_state(): void
    {
        $provider = DataProvider::factory()->create(['slug' => 'open-meteo']);

        $this->assertInstanceOf(NoProviderState::class, $provider->refresh()->state);
    }

    public function test_the_state_class_is_chosen_by_the_providers_slug(): void
    {
        $provider = DataProvider::factory()->create(['slug' => 'fake']);
        $this->setRawState('fake', '{"cities": {"sofia": {"seen": 1}}}');

        $state = $provider->refresh()->state;

        $this->assertInstanceOf(FakeProviderState::class, $state);
        $this->assertSame(['cities' => ['sofia' => ['seen' => 1]]], $state->toArray());
    }

    public function test_an_unknown_slug_reads_the_empty_state(): void
    {
        $provider = DataProvider::factory()->create(['slug' => 'not-configured']);
        $this->setRawState('not-configured', '{"cities": {"sofia": {}}}');

        $this->assertInstanceOf(NoProviderState::class, $provider->refresh()->state);
    }

    public function test_a_state_that_is_not_a_json_object_reads_as_empty(): void
    {
        $provider = DataProvider::factory()->create(['slug' => 'fake']);
        $this->setRawState('fake', '"edited by hand"');

        $this->assertSame([], $provider->refresh()->state->toArray());
    }

    public function test_the_model_never_writes_the_state(): void
    {
        $provider = DataProvider::factory()->create(['slug' => 'fake']);

        $this->expectException(LogicException::class);

        $provider->state = new FakeProviderState([]);
    }

    public function test_put_city_creates_the_cities_object_and_keeps_every_city(): void
    {
        DataProvider::factory()->create(['slug' => 'fake']);
        $store = new DatabaseProviderStateStore('fake');

        $store->putCity('sofia', ['seen' => 1]);
        $store->putCity('varna', ['seen' => 2]);

        $this->assertSame(['cities' => ['sofia' => ['seen' => 1], 'varna' => ['seen' => 2]]], $store->get()->toArray());
    }

    public function test_put_city_replaces_only_that_city_and_keeps_other_keys(): void
    {
        DataProvider::factory()->create(['slug' => 'fake']);
        $this->setRawState('fake', '{"other": true, "cities": {"sofia": {"seen": 1, "old": 1}, "ruse": {"seen": 3}}}');
        $store = new DatabaseProviderStateStore('fake');

        $store->putCity('sofia', ['seen' => 2, 'list' => [1, 2]]);

        // MySQL keeps JSON object keys in its own order, so the keys are compared in any order.
        $this->assertEquals(
            ['other' => true, 'cities' => ['ruse' => ['seen' => 3], 'sofia' => ['seen' => 2, 'list' => [1, 2]]]],
            $store->get()->toArray(),
        );
    }

    public function test_put_city_leaves_other_providers_alone(): void
    {
        DataProvider::factory()->create(['slug' => 'fake']);
        DataProvider::factory()->create(['slug' => 'open-meteo']);

        (new DatabaseProviderStateStore('fake'))->putCity('sofia', ['seen' => 1]);

        $this->assertSame('{}', DB::table('data_provider')->where('slug', 'open-meteo')->value('state'));
    }

    private function setRawState(string $slug, string $json): void
    {
        DB::table('data_provider')->where('slug', $slug)->update(['state' => $json]);
    }
}
