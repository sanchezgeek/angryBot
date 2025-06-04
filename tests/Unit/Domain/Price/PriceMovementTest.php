<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Price;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\PriceMovement;
use App\Helper\FloatHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Price\PriceMovement
 */
class PriceMovementTest extends TestCase
{
    public function testLossMovementDirection(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        # 1
        $priceMovement = PriceMovement::fromToTarget($symbol->makePrice(50000), $symbol->makePrice(51000.2));
        self::assertEquals(1000.2, PriceHelper::round($priceMovement->absDelta()));

        self::assertTrue($priceMovement->isLossFor(Side::Sell));
        self::assertTrue($priceMovement->isProfitFor(Side::Buy));

        self::assertFalse($priceMovement->isLossFor(Side::Buy));
        self::assertFalse($priceMovement->isProfitFor(Side::Sell));

        self::assertEquals('-1000.2', $priceMovement->pnlToString(Side::Sell));
        self::assertEquals('+1000.2', $priceMovement->pnlToString(Side::Buy));

        self::assertEquals(-1000.2, $priceMovement->deltaForPositionProfit(Side::Sell));
        self::assertEquals(1000.2, $priceMovement->deltaForPositionLoss(Side::Sell));
        self::assertEquals(-200.04, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Sell)->value()));
        self::assertEquals(200.04, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Sell)->value()));
        self::assertEquals(-250.05, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Sell, $symbol->makePrice(40000))->value()));
        self::assertEquals(250.05, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Sell, $symbol->makePrice(40000))->value()));

        self::assertEquals(1000.2, $priceMovement->deltaForPositionProfit(Side::Buy));
        self::assertEquals(-1000.2, $priceMovement->deltaForPositionLoss(Side::Buy));
        self::assertEquals(200.04, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Buy)->value()));
        self::assertEquals(250.05, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Buy, $symbol->makePrice(40000))->value()));

        # 2
        $priceMovement = PriceMovement::fromToTarget($symbol->makePrice(60000), $symbol->makePrice(49999.99));
        self::assertEquals(10000.01, PriceHelper::round($priceMovement->absDelta()));

        self::assertTrue($priceMovement->isLossFor(Side::Buy));
        self::assertTrue($priceMovement->isProfitFor(Side::Sell));

        self::assertFalse($priceMovement->isLossFor(Side::Sell));
        self::assertFalse($priceMovement->isProfitFor(Side::Buy));

        self::assertEquals('+10000.01', $priceMovement->pnlToString(Side::Sell));
        self::assertEquals('-10000.01', $priceMovement->pnlToString(Side::Buy));

        self::assertEquals(-10000.01, $priceMovement->deltaForPositionProfit(Side::Buy));
        self::assertEquals(10000.01, $priceMovement->deltaForPositionLoss(Side::Buy));
        self::assertEquals(-1666.668, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Buy)->value()));
        self::assertEquals(1666.668, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Buy)->value()));
        self::assertEquals(-2000.002, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Buy, $symbol->makePrice(50000))->value()));
        self::assertEquals(2000.002, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Buy, $symbol->makePrice(50000))->value()));

        self::assertEquals(10000.01, $priceMovement->deltaForPositionProfit(Side::Sell));
        self::assertEquals(-10000.01, $priceMovement->deltaForPositionLoss(Side::Sell));
        self::assertEquals(1666.668, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Sell)->value()));
        self::assertEquals(-1666.668, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Sell)->value()));
        self::assertEquals(2000.002, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Sell, $symbol->makePrice(50000))->value()));
        self::assertEquals(-2000.002, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Sell, $symbol->makePrice(50000))->value()));

        # none
        $priceMovement = PriceMovement::fromToTarget($symbol->makePrice(60000), $symbol->makePrice(60000));
        self::assertEquals(0, $priceMovement->absDelta());

        self::assertFalse($priceMovement->isLossFor(Side::Buy));
        self::assertFalse($priceMovement->isProfitFor(Side::Sell));

        self::assertFalse($priceMovement->isLossFor(Side::Sell));
        self::assertFalse($priceMovement->isProfitFor(Side::Buy));

        self::assertEquals('0', $priceMovement->pnlToString(Side::Buy));
        self::assertEquals('0', $priceMovement->pnlToString(Side::Sell));

        self::assertEquals(0, $priceMovement->deltaForPositionProfit(Side::Sell));
        self::assertEquals(0, $priceMovement->deltaForPositionLoss(Side::Sell));
        self::assertEquals(0, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Sell)->value()));
        self::assertEquals(0, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Sell)->value()));
        self::assertEquals(0, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Sell, $symbol->makePrice(50000))->value()));
        self::assertEquals(0, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Sell, $symbol->makePrice(50000))->value()));

        self::assertEquals(0, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Buy)->value()));
        self::assertEquals(0, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Buy)->value()));
        self::assertEquals(0, FloatHelper::round($priceMovement->percentDeltaForPositionProfit(Side::Buy, $symbol->makePrice(50000))->value()));
        self::assertEquals(0, FloatHelper::round($priceMovement->percentDeltaForPositionLoss(Side::Buy, $symbol->makePrice(50000))->value()));
    }
}
