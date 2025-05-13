<?php

declare(strict_types=1);

namespace App\Liquidation\Domain\Assert;

use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;

/**
 * @see \App\Tests\Unit\Modules\Liquidation\Domain\PositionLiquidationIsSafeAssertionTest
 */
final class PositionLiquidationIsSafeAssertion
{
    public static function assert(
        Position $position,
        Ticker $ticker,
        float $safeDistance,
        ?SafePriceAssertionStrategyEnum $strategy = SafePriceAssertionStrategyEnum::Conservative
    ): bool {
        $liquidationPrice = $position->liquidationPrice();

        // @todo | liquidation | null
        if ($liquidationPrice->eq(0)) {
            return true;
        }

        $markPrice = $ticker->markPrice;
        if ($position->isLiquidationPlacedBeforeEntry()) {
            $withPrice = $markPrice;
        } elseif ($position->isPositionInLoss($markPrice)) {
            $withPrice = $markPrice;
        } else {
            $withPrice = match($strategy) {
                SafePriceAssertionStrategyEnum::Aggressive => $markPrice,
                SafePriceAssertionStrategyEnum::Moderate => $ticker->symbol->makePrice(($markPrice->value() + $position->entryPrice) / 2),
                SafePriceAssertionStrategyEnum::Conservative => $position->entryPrice(),
            };
        }

        if ($position->isShort()) {
            return $liquidationPrice->value() - $withPrice->value() >= $safeDistance;
        }

        return $withPrice->value() - $liquidationPrice->value() >= $safeDistance;
    }
}
