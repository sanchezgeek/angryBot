<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\DefaultValues;

enum DefaultValueProviderEnum
{
    case CurrentPrice;
    case CurrentPositionState;
    case CurrentTicker;

    case SettingsProvider;

    case LiquidationHandlerHandledMessage;
}
