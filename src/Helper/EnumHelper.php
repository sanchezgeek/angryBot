<?php

declare(strict_types=1);

namespace App\Helper;

use BackedEnum;

final class EnumHelper
{
    public static function toStringList(array $values, string $separator = ', '): string
    {
        return implode($separator, $values);
    }

    public static function enumToStringList(string $enumName, string $separator = ', '): string
    {
        return implode($separator, array_map(static fn (BackedEnum $case) => $case->value, $enumName::cases()));
    }
}
