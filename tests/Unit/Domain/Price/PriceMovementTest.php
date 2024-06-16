<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Price;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\Price;
use App\Domain\Price\PriceMovement;
use PHPUnit\Framework\TestCase;

class PriceMovementTest extends TestCase
{
    public function testLossMovementDirection(): void
    {
        # 1
        $priceMovement = PriceMovement::fromToTarget(Price::float(50000), Price::float(50000.2));
        self::assertEquals(0.2, PriceHelper::round($priceMovement->delta()));

        self::assertTrue($priceMovement->isLossFor(Side::Sell));
        self::assertTrue($priceMovement->isProfitFor(Side::Buy));

        self::assertFalse($priceMovement->isLossFor(Side::Buy));
        self::assertFalse($priceMovement->isProfitFor(Side::Sell));

        # 2
        $priceMovement = PriceMovement::fromToTarget(Price::float(60000), Price::float(50000));
        self::assertEquals(10000, $priceMovement->delta());

        self::assertTrue($priceMovement->isLossFor(Side::Buy));
        self::assertTrue($priceMovement->isProfitFor(Side::Sell));

        self::assertFalse($priceMovement->isLossFor(Side::Sell));
        self::assertFalse($priceMovement->isProfitFor(Side::Buy));

        # none
        $priceMovement = PriceMovement::fromToTarget(Price::float(60000), Price::float(60000));
        self::assertEquals(0, $priceMovement->delta());

        self::assertFalse($priceMovement->isLossFor(Side::Buy));
        self::assertFalse($priceMovement->isProfitFor(Side::Sell));

        self::assertFalse($priceMovement->isLossFor(Side::Sell));
        self::assertFalse($priceMovement->isProfitFor(Side::Buy));
    }
}