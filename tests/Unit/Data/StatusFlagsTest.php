<?php

namespace Tests\Unit\Data;

use App\Data\StatusFlags;
use App\Enums\Status;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatusFlagsTest extends TestCase
{
    public function test_of_combines_the_given_flags(): void
    {
        $this->assertSame(1, StatusFlags::of(Status::Active)->value);
        $this->assertSame(0, StatusFlags::of()->value);
    }

    #[DataProvider('activeBits')]
    public function test_active_is_read_from_the_lowest_bit(int $value, bool $active): void
    {
        $flags = new StatusFlags($value);

        $this->assertSame($active, $flags->has(Status::Active));
        $this->assertSame($active, $flags->isActive());
    }

    public static function activeBits(): array
    {
        return [
            'none' => [0, false],
            'active' => [1, true],
            'active with a future flag' => [3, true],
            'only a future flag' => [2, false],
        ];
    }

    public function test_with_sets_the_flag_without_touching_other_bits(): void
    {
        $original = new StatusFlags(2);
        $changed = $original->with(Status::Active);

        $this->assertSame(2, $original->value);
        $this->assertSame(3, $changed->value);
    }

    public function test_with_an_already_set_flag_keeps_the_value(): void
    {
        $this->assertSame(3, (new StatusFlags(3))->with(Status::Active)->value);
    }

    public function test_without_clears_the_flag_without_touching_other_bits(): void
    {
        $original = new StatusFlags(3);
        $changed = $original->without(Status::Active);

        $this->assertSame(3, $original->value);
        $this->assertSame(2, $changed->value);
        $this->assertSame(2, $changed->without(Status::Active)->value);
    }

    public function test_negative_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StatusFlags(-1);
    }
}
