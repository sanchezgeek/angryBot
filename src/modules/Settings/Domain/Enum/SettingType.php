<?php

declare(strict_types=1);

namespace App\Settings\Domain\Enum;

enum SettingType
{
    case String;
    case Integer;
    case Float;
    case Boolean;
    case Percent;
}
