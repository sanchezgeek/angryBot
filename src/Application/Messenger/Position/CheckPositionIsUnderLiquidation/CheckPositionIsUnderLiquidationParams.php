<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;

use App\Bot\Domain\ValueObject\Symbol;

final class CheckPositionIsUnderLiquidationParams
{
    public const ACCEPTABLE_STOPPED_PART_DEFAULT = 4;
    public const ACCEPTABLE_STOPPED_PART_DIVIDER = 2.3;
    public const ACTUAL_STOPS_RANGE_FROM_ADDITIONAL_STOP = 8; // @todo Must be different for BTC and others

    /** @var Symbol[] */
    private const SKIP_LIQUIDATION_CHECK_ON_SYMBOLS = [
//        Symbol::LAIUSDT,
    ];

    /** @var int|float */
    private const ACCEPTABLE_STOPPED_PART = [
//        Symbol::BTCUSDT->value => 5,
    ];

    /** @var Symbol[] */
    private const SYMBOLS_WITHOUT_OPPOSITE_ORDERS = [
        // @todo should be some sort of automated decisions
//        Symbol::ARCUSDT,
    ];

    public static function isSymbolWithoutOppositeBuyOrders(Symbol $symbol): bool
    {
        return in_array($symbol, self::SYMBOLS_WITHOUT_OPPOSITE_ORDERS);
    }

    public static function isSymbolIgnored(Symbol $symbol): bool
    {
        return in_array($symbol, self::SKIP_LIQUIDATION_CHECK_ON_SYMBOLS);
    }

    public static function getAcceptableStoppedPart(Symbol $symbol): float|int|null
    {
        return self::ACCEPTABLE_STOPPED_PART[$symbol->value] ?? null;
    }
}
