<?php

declare(strict_types=1);

namespace App\Settings\Application\Helper;

use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\DependencyInjection\GetServiceHelper;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Domain\Symbol\SymbolInterface;

final class SettingsHelper
{
    public static function exactlyRoot(AppSettingInterface $setting, bool $required = false): mixed
    {
        $settings = self::getSettingsService();

        return $required
            ? $settings->required(SettingAccessor::exact($setting))
            : $settings->optional(SettingAccessor::exact($setting))
        ;
    }

    public static function getForSideOrSymbol(AppSettingInterface $setting, SymbolInterface $symbol, Side $side): mixed
    {
        $settings = self::getSettingsService();

        if ($valueForSymbolAndSide = $settings->optional(SettingAccessor::exact($setting, $symbol, $side))) {
            return $valueForSymbolAndSide;
        }

        if ($valueForSymbol = $settings->optional(SettingAccessor::exact($setting, $symbol))) {
            return $valueForSymbol;
        }

        return null;
    }

    private static function getSettingsService(): AppSettingsProviderInterface
    {
        return GetServiceHelper::getService(AppSettingsProviderInterface::class);
    }
}
