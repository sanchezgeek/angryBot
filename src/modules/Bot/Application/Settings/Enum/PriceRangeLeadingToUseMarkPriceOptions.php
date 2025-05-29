<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings\Enum;

enum PriceRangeLeadingToUseMarkPriceOptions: string
{
    case WarningRange = 'warning';
    case CriticalRange = 'critical';
}
