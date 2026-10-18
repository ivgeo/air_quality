<?php

namespace App\Ingestion\Contracts;

/**
 * What a provider discovered and keeps itself (`data_provider.state`), e.g. stations per city.
 *
 * fromArray() never throws: a missing or malformed part (the column can be edited by hand) is
 * read as not discovered yet, so the provider simply discovers it again.
 */
interface ProviderState
{
    /**
     * @param  array<mixed>  $data  the decoded `data_provider.state` JSON
     */
    public static function fromArray(array $data): static;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
