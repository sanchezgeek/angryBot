<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Bot\Application\Settings\Enum\PriceRangeLeadingToUseMarkPriceOptions;
use App\Bot\Domain\Position;
use App\Settings\Application\Helper\SettingsHelper;

final readonly class PushStopSettingsWrapper
{
    public static function rangeToUseWhileChooseMarkPrice(Position $position): PriceRangeLeadingToUseMarkPriceOptions
    {
        return SettingsHelper::withAlternatives(PushStopSettings::WhichRangeToUse_While_ChooseMarkPrice_AsTriggerPrice, $position->symbol, $position->side);
    }
}
