<?php

declare(strict_types=1);

namespace App\Liquidation\Domain\Assert;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;

final class LiquidationIsSafeAssertion
{
    public static function assert(Side $positionSide, Price $liquidationPrice, Price $withPrice, float $safeDistance): bool
    {
        if ($positionSide->isShort()) {
            return $liquidationPrice->sub($withPrice)->greaterOrEquals($safeDistance);
        }

        return $withPrice->sub($liquidationPrice)->greaterOrEquals($safeDistance);
    }
}
