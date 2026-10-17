<?php

namespace App\Casts;

use App\Data\Measurements;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps the hourly `data` JSON object to Measurements and back.
 *
 * @implements CastsAttributes<Measurements, Measurements>
 */
class MeasurementsCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Measurements
    {
        if ($value === null) {
            return null;
        }

        /** @var mixed $data */
        $data = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException(sprintf('The "%s" column must hold a JSON object.', $key));
        }

        return Measurements::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! $value instanceof Measurements) {
            throw new InvalidArgumentException(sprintf('The "%s" attribute expects %s, %s given.', $key, Measurements::class, get_debug_type($value)));
        }

        return $value->toJson();
    }
}
