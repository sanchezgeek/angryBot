<?php

declare(strict_types=1);

namespace App\Liquidation\Domain\Assert;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;

/**
 * @see \App\Tests\Unit\Modules\Liquidation\Domain\LiquidationIsSafeAssertionTest
 * @deprecated?
 */
final class LiquidationIsSafeAssertion
{
    public static function assert(
        Side $positionSide,
        SymbolPrice $liquidationPrice,
        SymbolPrice $withPrice,
        float $safeDistance,
    ): bool {
        if ($positionSide->isShort()) {
            return $liquidationPrice->sub($withPrice)->greaterOrEquals($safeDistance);
        }

        return $withPrice->sub($liquidationPrice)->greaterOrEquals($safeDistance);
    }
}
