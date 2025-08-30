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

    public static function getForSymbolOrSymbolAndSide(AppSettingInterface $setting, SymbolInterface $symbol, Side $side): mixed
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

    public static function exact(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): mixed
    {
        return self::getSettingsService()->optional(
            SettingAccessor::exact($setting, $symbol, $side)
        );
    }

    public static function withAlternativesAllowed(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null): mixed
    {
        return self::getSettingsService()->optional(
            SettingAccessor::withAlternativesAllowed($setting, $symbol, $side)
        );
    }

    private static function getSettingsService(): AppSettingsProviderInterface
    {
        return GetServiceHelper::getService(AppSettingsProviderInterface::class);
    }
}
