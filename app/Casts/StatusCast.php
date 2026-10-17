<?php

namespace App\Casts;

use App\Data\StatusFlags;
use App\Enums\Status;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps a `status` bitmask column to StatusFlags; accepts StatusFlags or a single Status flag.
 *
 * @implements CastsAttributes<StatusFlags, StatusFlags|Status>
 */
class StatusCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?StatusFlags
    {
        return $value === null ? null : new StatusFlags((int) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return match (true) {
            $value instanceof StatusFlags => $value->value,
            $value instanceof Status => StatusFlags::of($value)->value,
            default => throw new InvalidArgumentException(sprintf(
                'The "%s" attribute expects %s or %s, %s given.', $key, StatusFlags::class, Status::class, get_debug_type($value),
            )),
        };
    }
}
