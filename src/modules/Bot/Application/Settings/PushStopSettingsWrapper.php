<?php

declare(strict_types=1);

namespace App\Bot\Application\Settings;

use App\Bot\Application\Settings\Enum\PriceRangeLeadingToUseMarkPriceOptions;
use App\Bot\Domain\Position;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;

final readonly class PushStopSettingsWrapper
{
    public function __construct(
        private AppSettingsProviderInterface $settings,
    ) {
    }

    public function rangeToUseWhileChooseMarkPriceAsTriggerPrice(Position $position): PriceRangeLeadingToUseMarkPriceOptions
    {
        return $this->settings->required(
            SettingAccessor::withAlternativesAllowed(PushStopSettings::WhichRangeToUse_While_ChooseMarkPrice_AsTriggerPrice, $position->symbol, $position->side)
        );
    }
}
