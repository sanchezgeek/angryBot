<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position;

use App\Bot\Domain\ValueObject\Symbol;

final class CheckPositionIsUnderLiquidationParams
{
    /** @var Symbol[] */
    private const SKIP_LIQUIDATION_CHECK_ON_SYMBOLS = [
//        Symbol::LAIUSDT,
    ];

    /** @var int */
    private const ADDITIONAL_STOP_LIQUIDATION_DISTANCE = [
//        Symbol::BTCUSDT->value => 40,
    ];

    /** @var int|float */
    private const ACCEPTABLE_STOPPED_PART = [
//        Symbol::BTCUSDT->value => 5,
    ];

    /** @var Symbol[] */
    private const SYMBOLS_WITHOUT_OPPOSITE_ORDERS = [
//        Symbol::BTCUSDT,
    ];

    public static function isSymbolWithoutOppositeBuyOrders(Symbol $symbol): bool
    {
        return in_array($symbol, self::SYMBOLS_WITHOUT_OPPOSITE_ORDERS);
    }

    public static function isSymbolIgnored(Symbol $symbol): bool
    {
        return in_array($symbol, self::SKIP_LIQUIDATION_CHECK_ON_SYMBOLS);
    }

    public static function getAdditionalStopDistanceWithLiquidation(Symbol $symbol): int|null
    {
        return self::ADDITIONAL_STOP_LIQUIDATION_DISTANCE[$symbol->value] ?? null;
    }

    public static function getAcceptableStoppedPart(Symbol $symbol): float|int|null
    {
        return self::ACCEPTABLE_STOPPED_PART[$symbol->value] ?? null;
    }
}
