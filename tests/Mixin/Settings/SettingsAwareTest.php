<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Settings;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Application\Service\AppSettingsProvider;
use App\Settings\Application\Service\Dto\SettingValueAccessor;
use App\Stop\Application\Settings\SafePriceDistance;

trait SettingsAwareTest
{
    protected static function getContainerSettingsProvider(): AppSettingsProvider
    {
        return self::getContainer()->get(AppSettingsProvider::class);
    }

    protected static function getSettingValue(SettingKeyAware $setting): mixed
    {
        return self::getContainerSettingsProvider()->get($setting);
    }

    protected function overrideSetting(SettingKeyAware|SettingValueAccessor $setting, mixed $value): void
    {
        self::getContainerSettingsProvider()->set($setting, $value);
    }

    protected function setMinimalSafePriceDistance(Symbol $symbol, Side $positionSide, float $pricePercent = 0.1): void
    {
        # @todo | buyIsSafe | for now to prevent MarketBuyHandler "buyIsSafe" checks
        $this->overrideSetting(SettingValueAccessor::bySide(SafePriceDistance::SafePriceDistance_Percent, $symbol, $positionSide), $pricePercent);
    }
}
