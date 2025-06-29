<?php

declare(strict_types=1);

namespace App\Domain\Trading\Enum;

enum PredefinedStopLengthSelector: string
{
    case VeryShort = 'very-short';
    case Short = 'short';
    case ModerateShort = 'moderate-short';
    case Standard = 'standard';
    case ModerateLong = 'moderate-long';
    case Long = 'long';
    case VeryLong = 'very-long';
}
