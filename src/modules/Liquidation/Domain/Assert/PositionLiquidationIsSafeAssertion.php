<?php

declare(strict_types=1);

namespace App\Liquidation\Domain\Assert;

use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Liquidation\Domain\Assert\Result\PositionLiquidationIsSafeAssertionResult;

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
    ): PositionLiquidationIsSafeAssertionResult {
        $liquidationPrice = $position->liquidationPrice();

        $markPrice = $ticker->markPrice;

        // @todo | liquidation | null
        if ($liquidationPrice->eq(0)) {
            return new PositionLiquidationIsSafeAssertionResult(true, $markPrice->value());
        }

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
            $result = $liquidationPrice->value() - $withPrice->value() >= $safeDistance;
        } else {
            $result = $withPrice->value() - $liquidationPrice->value() >= $safeDistance;
        }

        return new PositionLiquidationIsSafeAssertionResult($result, $withPrice->value());
    }
}
