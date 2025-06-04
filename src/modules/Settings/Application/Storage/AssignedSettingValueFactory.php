<?php

declare(strict_types=1);

namespace App\Settings\Application\Storage;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use App\Settings\Domain\Entity\SettingValue;
use App\Settings\Domain\SettingValueCaster;
use App\Trading\Domain\Symbol\SymbolInterface;

final class AssignedSettingValueFactory
{
    /**
     * @return array{SymbolInterface, Side}
     */
    public static function parseSymbolAndSide(string $fullKey): array
    {
        $symbol = null;
        $side = null;

        if (str_contains($fullKey, 'symbol=') || str_contains($fullKey, 'side=')) {
            preg_match_all('/(?<=\[).*?(?=\])/', $fullKey, $matches);
            foreach ($matches[0] as $match) {
                if (str_contains($match, 'symbol=')) {
                    $symbol = str_replace('symbol=', '', $match);
                } elseif (str_contains($match, 'side=')) {
                    $side = Side::tryFrom(str_replace('side=', '', $match));
                }
            }
        }

        return [$symbol, $side];
    }

    public static function byFullKeyAndValue(AppSettingInterface $setting, string $fullKey, mixed $value, ?string $info = null): AssignedSettingValue
    {
        [$symbol, $side] = self::parseSymbolAndSide($fullKey);

        return new AssignedSettingValue($setting, $symbol ? SymbolEnum::tryFrom($symbol) : null, $side, $fullKey, self::castStoredValue($setting, $value), $info);
    }

    public static function fromEntity(AppSettingInterface $setting, SettingValue $settingValue, ?string $info = null): AssignedSettingValue
    {
        $fullKey = self::buildFullKey($setting, $settingValue->symbol, $settingValue->positionSide);

        return new AssignedSettingValue($setting, $settingValue->symbol, $settingValue->positionSide, $fullKey, self::castStoredValue($setting, $settingValue->value), $info);
    }

    public static function byAccessorAndValue(SettingAccessor $settingAccessor, mixed $value, ?string $info = null): AssignedSettingValue
    {
        $setting = $settingAccessor->setting;
        $fullKey = self::buildFullKey($setting, $settingAccessor->symbol, $settingAccessor->side);

        return new AssignedSettingValue($setting, $settingAccessor->symbol, $settingAccessor->side, $fullKey, self::castStoredValue($setting, $value), $info);
    }

    private static function castStoredValue(AppSettingInterface $setting, mixed $storedValue): mixed
    {
        return $storedValue === null ? null : SettingValueCaster::castToDeclaredType($setting, $storedValue);
    }

    public static function buildFullKey(AppSettingInterface $setting, ?SymbolInterface $symbol, ?Side $positionSide): string
    {
        $baseKey = $setting->getSettingKey();

        return match (true) {
            $positionSide !== null => sprintf('%s[symbol=%s][side=%s]', $baseKey, $symbol->name(), $positionSide->value),
            $symbol !== null => sprintf('%s[symbol=%s]', $baseKey, $symbol->name()),
            default => $baseKey,
        };
    }
}
