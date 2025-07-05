<?php

declare(strict_types=1);

namespace App\Helper;

use BackedEnum;

enum StringHelper
{
    public static function toString(BackedEnum|string|null $val): ?string
    {
        if (is_string($val)) {
            return $val;
        }

        return $val?->value;
    }
}
