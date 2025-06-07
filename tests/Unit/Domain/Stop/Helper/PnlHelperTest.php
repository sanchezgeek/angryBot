<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Stop\Helper;

use App\Bot\Domain\ValueObject\SymbolEnum;
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
        $volume = 0.05;

        ## SHORT
        $position = PositionFactory::short(SymbolEnum::BTCUSDT, 30000, 1, 100);
        self::assertEquals(-50.0, PnlHelper::getPnlInUsdt($position, 31000, $volume));
        self::assertEquals(-15.0, PnlHelper::getPnlInUsdt($position, 30300, $volume));
        self::assertEquals(-7.5, PnlHelper::getPnlInUsdt($position, 30150, $volume));
        self::assertEquals(0, PnlHelper::getPnlInUsdt($position, 30000, $volume));
        self::assertEquals(7.5, PnlHelper::getPnlInUsdt($position, 29850, $volume));
        self::assertEquals(15.0, PnlHelper::getPnlInUsdt($position, 29700, $volume));
        self::assertEquals(50.0, PnlHelper::getPnlInUsdt($position, 29000, $volume));## SHORT

        $position = PositionFactory::long(SymbolEnum::BTCUSDT, 30000, 1, 100);
        self::assertEquals(50.0, PnlHelper::getPnlInUsdt($position, 31000, $volume));
        self::assertEquals(15.0, PnlHelper::getPnlInUsdt($position, 30300, $volume));
        self::assertEquals(7.5, PnlHelper::getPnlInUsdt($position, 30150, $volume));
        self::assertEquals(0, PnlHelper::getPnlInUsdt($position, 30000, $volume));
        self::assertEquals(-7.5, PnlHelper::getPnlInUsdt($position, 29850, $volume));
        self::assertEquals(-15.0, PnlHelper::getPnlInUsdt($position, 29700, $volume));
        self::assertEquals(-50.0, PnlHelper::getPnlInUsdt($position, 29000, $volume));
    }

    public function testGetPnlInPercents(): void
    {
        ## SHORT
        $position = PositionFactory::short(SymbolEnum::BTCUSDT, 30000, 1, 100);
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

        ## LONG
        $position = PositionFactory::long(SymbolEnum::BTCUSDT, 30000, 1, 100);
        self::assertEquals(120, PnlHelper::getPnlInPercents($position, 30360));
        self::assertEquals(100, PnlHelper::getPnlInPercents($position, 30300));
        self::assertEquals(50, PnlHelper::getPnlInPercents($position, 30150));
        self::assertEquals(20, PnlHelper::getPnlInPercents($position, 30060));
        self::assertEquals(0, PnlHelper::getPnlInPercents($position, 30000));
        self::assertEquals(-20, PnlHelper::getPnlInPercents($position, 29940));
        self::assertEquals(-40, PnlHelper::getPnlInPercents($position, 29880));
        self::assertEquals(-50, PnlHelper::getPnlInPercents($position, 29850));
        self::assertEquals(-100, PnlHelper::getPnlInPercents($position, 29700));
        self::assertEquals(-120, PnlHelper::getPnlInPercents($position, 29640));
        self::assertEquals(-150, PnlHelper::getPnlInPercents($position, 29550));
    }

    public function testGetTargetPriceByPnlPercentFromPositionEntry(): void
    {
        $symbol = SymbolEnum::BTCUSDT;

        ## SHORT
        $position = PositionFactory::short($symbol, 30000, 1, 100);
        self::assertEquals($symbol->makePrice(30360), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -120));
        self::assertEquals($symbol->makePrice(30300), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -100));
        self::assertEquals($symbol->makePrice(30150), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -50));
        self::assertEquals($symbol->makePrice(30060), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -20));
        self::assertEquals($symbol->makePrice(30000), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 0));
        self::assertEquals($symbol->makePrice(29940), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 20));
        self::assertEquals($symbol->makePrice(29880), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 40));
        self::assertEquals($symbol->makePrice(29850), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 50));
        self::assertEquals($symbol->makePrice(29700), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 100));
        self::assertEquals($symbol->makePrice(29640), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 120));
        self::assertEquals($symbol->makePrice(29550), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 150));

        ## LONG
        $position = PositionFactory::long($symbol, 30000, 1, 100);
        self::assertEquals($symbol->makePrice(30360), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 120));
        self::assertEquals($symbol->makePrice(30300), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 100));
        self::assertEquals($symbol->makePrice(30150), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 50));
        self::assertEquals($symbol->makePrice(30060), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 20));
        self::assertEquals($symbol->makePrice(30000), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, 0));
        self::assertEquals($symbol->makePrice(29940), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -20));
        self::assertEquals($symbol->makePrice(29880), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -40));
        self::assertEquals($symbol->makePrice(29850), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -50));
        self::assertEquals($symbol->makePrice(29700), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -100));
        self::assertEquals($symbol->makePrice(29640), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -120));
        self::assertEquals($symbol->makePrice(29550), PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, -150));
    }

    public function testGetTargetPriceByPnlPercent(): void
    {
        ## SHORT
        $symbol = SymbolEnum::BTCUSDT;
        $position = PositionFactory::short($symbol, 100500, 1, 100);
        $fromPrice = $symbol->makePrice(30000);

        self::assertEquals($symbol->makePrice(30360), PnlHelper::targetPriceByPnlPercent($fromPrice, -120, $position->side));
        self::assertEquals($symbol->makePrice(30300), PnlHelper::targetPriceByPnlPercent($fromPrice, -100, $position->side));
        self::assertEquals($symbol->makePrice(30060), PnlHelper::targetPriceByPnlPercent($fromPrice, -20, $position->side));
        self::assertEquals($symbol->makePrice(30000), PnlHelper::targetPriceByPnlPercent($fromPrice, 0, $position->side));
        self::assertEquals($symbol->makePrice(29940), PnlHelper::targetPriceByPnlPercent($fromPrice, 20, $position->side));
        self::assertEquals($symbol->makePrice(29700), PnlHelper::targetPriceByPnlPercent($fromPrice, 100, $position->side));
        self::assertEquals($symbol->makePrice(29640), PnlHelper::targetPriceByPnlPercent($fromPrice, 120, $position->side));

        ## LONG
        $position = PositionFactory::long($symbol, 100500, 1, 100);

        self::assertEquals($symbol->makePrice(30360), PnlHelper::targetPriceByPnlPercent($fromPrice, 120, $position->side));
        self::assertEquals($symbol->makePrice(30300), PnlHelper::targetPriceByPnlPercent($fromPrice, 100, $position->side));
        self::assertEquals($symbol->makePrice(30060), PnlHelper::targetPriceByPnlPercent($fromPrice, 20, $position->side));
        self::assertEquals($symbol->makePrice(30000), PnlHelper::targetPriceByPnlPercent($fromPrice, 0, $position->side));
        self::assertEquals($symbol->makePrice(29940), PnlHelper::targetPriceByPnlPercent($fromPrice, -20, $position->side));
        self::assertEquals($symbol->makePrice(29700), PnlHelper::targetPriceByPnlPercent($fromPrice, -100, $position->side));
        self::assertEquals($symbol->makePrice(29640), PnlHelper::targetPriceByPnlPercent($fromPrice, -120, $position->side));
    }
}
