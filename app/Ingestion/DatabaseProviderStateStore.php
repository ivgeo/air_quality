<?php

namespace App\Ingestion;

use App\Ingestion\Contracts\ProviderState;
use App\Ingestion\Contracts\ProviderStateStore;
use App\Models\DataProvider;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a provider's state in its `data_provider.state` column.
 */
class DatabaseProviderStateStore implements ProviderStateStore
{
    public function __construct(public readonly string $slug) {}

    public function get(): ProviderState
    {
        /** @var DataProvider $provider */
        $provider = DataProvider::query()->where('slug', $this->slug)->sole();

        return $provider->state;
    }

    /**
     * One UPDATE, so it is atomic against other cities' writes. The inner JSON_SET makes sure
     * `$.cities` is an object first: JSON_SET does nothing for a path whose parent is missing or
     * not an object. A state edited by hand into something other than an object starts over.
     */
    public function putCity(string $city, array $value): void
    {
        DB::update(
            "UPDATE `data_provider`
                SET `state` = JSON_SET(
                    JSON_SET(
                        IF(JSON_TYPE(`state`) = 'OBJECT', `state`, JSON_OBJECT()),
                        '$.cities',
                        IF(JSON_TYPE(JSON_EXTRACT(`state`, '$.cities')) <=> 'OBJECT', JSON_EXTRACT(`state`, '$.cities'), JSON_OBJECT())
                    ),
                    ?, CAST(? AS JSON))
              WHERE `slug` = ?",
            ['$.cities.'.json_encode($city), json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $this->slug],
        );
    }
}
