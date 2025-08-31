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
    public static function exact(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null, bool $required = true): mixed
    {
        $settings = self::getSettingsService();
        $accessor = SettingAccessor::exact($setting, $symbol, $side);

        return $required ? $settings->required($accessor) : $settings->optional($accessor);
    }

    public static function withAlternativesAllowed(AppSettingInterface $setting, ?SymbolInterface $symbol = null, ?Side $side = null, bool $required = true): mixed
    {
        $settings = self::getSettingsService();
        $accessor = SettingAccessor::withAlternativesAllowed($setting, $symbol, $side);

        return $required ? $settings->required($accessor) : $settings->optional($accessor);
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
