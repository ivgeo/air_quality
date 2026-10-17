<?php

namespace App\Enums;

/**
 * Flags stored in the `status` bitmask column; every case is a distinct power of 2.
 */
enum Status: int
{
    case Active = 1;
}
