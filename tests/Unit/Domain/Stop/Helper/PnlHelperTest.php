<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Stop\Helper;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use App\Tests\Factory\PositionFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Stop\Helper\PnlHelper
 */
final class PnlHelperTest extends TestCase
{
    public function testPnl(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 1, 100);

        self::assertEquals(100.0, PnlHelper::getPnlInPercents($position, 29700));
        self::assertEquals(50.0, PnlHelper::getPnlInPercents($position, 29850));
        self::assertEquals(-50.0, PnlHelper::getPnlInPercents($position, 30150));
        self::assertEquals(-100.0, PnlHelper::getPnlInPercents($position, 30300));
    }

    public function testGetPnlInPercents(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 1, 100);

        self::assertEquals(-120, PnlHelper::getPnlInPercents($position, 30360));
        self::assertEquals(-100, PnlHelper::getPnlInPercents($position, 30300));
        self::assertEquals(-50, PnlHelper::getPnlInPercents($position, 30150));
        self::assertEquals(-20, PnlHelper::getPnlInPercents($position, 30060));
        self::assertEquals(0, PnlHelper::getPnlInPercents($position, 30000));
        self::assertEquals(20, PnlHelper::getPnlInPercents($position, 29940));
        self::assertEquals(40, PnlHelper::getPnlInPercents($position, 29880));
        self::assertEquals(50, PnlHelper::getPnlInPercents($position, 29850));
        self::assertEquals(100, PnlHelper::getPnlInPercents($position, 29700));
        self::assertEquals(120, PnlHelper::getPnlInPercents($position, 29640));
        self::assertEquals(150, PnlHelper::getPnlInPercents($position, 29550));
    }

    public function testGetPriceByPercent(): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 1, 100);

        self::assertEquals(Price::float(30360), PnlHelper::getTargetPriceByPnlPercent($position, -120));
        self::assertEquals(Price::float(30300), PnlHelper::getTargetPriceByPnlPercent($position, -100));
        self::assertEquals(Price::float(30150), PnlHelper::getTargetPriceByPnlPercent($position, -50));
        self::assertEquals(Price::float(30060), PnlHelper::getTargetPriceByPnlPercent($position, -20));
        self::assertEquals(Price::float(30000), PnlHelper::getTargetPriceByPnlPercent($position, 0));
        self::assertEquals(Price::float(29940), PnlHelper::getTargetPriceByPnlPercent($position, 20));
        self::assertEquals(Price::float(29880), PnlHelper::getTargetPriceByPnlPercent($position, 40));
        self::assertEquals(Price::float(29850), PnlHelper::getTargetPriceByPnlPercent($position, 50));
        self::assertEquals(Price::float(29700), PnlHelper::getTargetPriceByPnlPercent($position, 100));
        self::assertEquals(Price::float(29640), PnlHelper::getTargetPriceByPnlPercent($position, 120));
        self::assertEquals(Price::float(29550), PnlHelper::getTargetPriceByPnlPercent($position, 150));
    }
}
