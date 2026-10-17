<?php

namespace App\Data;

use App\Enums\Status;
use InvalidArgumentException;

/**
 * Immutable view of a `status` bitmask column.
 */
final readonly class StatusFlags
{
    public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new InvalidArgumentException(sprintf('Status must not be negative, %d given.', $value));
        }
    }

    public static function of(Status ...$flags): self
    {
        $value = 0;

        foreach ($flags as $flag) {
            $value |= $flag->value;
        }

        return new self($value);
    }

    public function has(Status $flag): bool
    {
        return ($this->value & $flag->value) === $flag->value;
    }

    public function with(Status $flag): self
    {
        return new self($this->value | $flag->value);
    }

    public function without(Status $flag): self
    {
        return new self($this->value & ~$flag->value);
    }

    public function isActive(): bool
    {
        return $this->has(Status::Active);
    }
}
