<?php

declare(strict_types=1);

namespace App\Liquidation\Domain\Assert;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;

final class LiquidationIsSafeAssertion
{
    public static function assert(Side $positionSide, Price $liquidationPrice, Price $tickerPrice, float $safeDistance): bool
    {
        if ($positionSide->isShort()) {
            return $liquidationPrice->sub($tickerPrice)->greaterOrEquals($safeDistance);
        }

        return $tickerPrice->sub($liquidationPrice)->greaterOrEquals($safeDistance);
    }
}
