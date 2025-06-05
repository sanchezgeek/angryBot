<?php

declare(strict_types=1);

namespace App\Trading\Domain\Symbol\Helper;

use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @todo | symbol | some wrappers after ref and check run ability
 */
final class SymbolHelper
{
    /**
     * @return string[]
     */
    public static function symbolsToRawValues(SymbolInterface ...$symbols): array
    {
        return array_map(static fn (SymbolInterface $symbol) => $symbol->name(), $symbols);
    }

    public static function stopDefaultTriggerDelta(SymbolInterface $symbol): float
    {
        $pricePrecision = $symbol->pricePrecision();

        return round(pow(0.1, $pricePrecision - 1), $pricePrecision);
    }

    public static function minimalPriceMove(SymbolInterface $symbol): float
    {
        $pricePrecision = $symbol->pricePrecision();

        return round(pow(0.1, $pricePrecision), $pricePrecision);
    }

    public static function contractSizePrecision(SymbolInterface $symbol): ?int
    {
        $minOrderQty = $symbol->minOrderQty();

        $string = (string)$minOrderQty;

        if (!str_contains($string, '.')) {
            return 0;
        }

        $parts = explode('.', $string);

        return strlen($parts[1]);
    }

    public static function roundVolume(SymbolInterface $symbol, float $volume): float
    {
        $value = round($volume, $symbol->contractSizePrecision());
        $minOrderQty = $symbol->minOrderQty();

        if ($value < $minOrderQty) {
            $value = $minOrderQty;
        }

        return $value;
    }

    public static function roundVolumeUp(SymbolInterface $symbol, float $volume): float
    {
        $precision = $symbol->contractSizePrecision();
        $minOrderQty = $symbol->minOrderQty();

        $fig = 10 ** $precision;
        $value = (ceil($volume * $fig) / $fig);

        if ($value < $minOrderQty) {
            $value = $minOrderQty;
        }

        return $value;
    }

    public static function roundVolumeDown(SymbolInterface $symbol, float $volume): float
    {
        $precision = $symbol->contractSizePrecision();
        $minOrderQty = $symbol->minOrderQty();

        $value = floor($volume*pow(10,$precision))/pow(10,$precision);
        if ($value < $minOrderQty) {
            $value = $minOrderQty;
        }

        return $value;
    }
}
