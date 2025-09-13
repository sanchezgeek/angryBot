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
    public static function exactEnabled(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): bool
    {
        return self::exact($setting, $symbol, $side) === true;
    }

    public static function exactDisabled(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): bool
    {
        return self::exact($setting, $symbol, $side) === false;
    }

    public static function enabledWithAlternatives(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): bool
    {
        return self::withAlternatives($setting, $symbol, $side) === true;
    }

    public static function alternativesDisabled(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): bool
    {
        return self::withAlternatives($setting, $symbol, $side) === false;
    }

    public static function exact(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): mixed
    {
        return self::getSettingsService()->required(
            SettingAccessor::exact($setting, $symbol, $side)
        );
    }

    public static function exactOptional(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): mixed
    {
        return self::getSettingsService()->optional(
            SettingAccessor::exact($setting, $symbol, $side)
        );
    }

    public static function withAlternatives(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): mixed
    {
        return self::getSettingsService()->required(
            SettingAccessor::withAlternativesAllowed($setting, $symbol, $side)
        );
    }

    public static function withAlternativesOptional(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): mixed
    {
        return self::getSettingsService()->optional(
            SettingAccessor::withAlternativesAllowed($setting, $symbol, $side)
        );
    }

    public static function exactForSymbolAndSideOrSymbol(AppSettingInterface $setting, SymbolInterface $symbol, Side $side): mixed
    {
        $settings = self::getSettingsService();

        $valueForSymbolAndSide = $settings->optional(SettingAccessor::exact($setting, $symbol, $side));
        if ($valueForSymbolAndSide !== null) {
            return $valueForSymbolAndSide;
        }

        $valueForSymbol = $settings->optional(SettingAccessor::exact($setting, $symbol));
        if ($valueForSymbol !== null) {
            return $valueForSymbol;
        }

        return null;
    }

    private static function getSettingsService(): AppSettingsProviderInterface
    {
        return GetServiceHelper::getService(AppSettingsProviderInterface::class);
    }
}
