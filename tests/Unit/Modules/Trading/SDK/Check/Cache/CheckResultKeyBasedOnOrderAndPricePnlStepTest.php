<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Trading\SDK\Check\Cache;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Trading\SDK\Check\Cache\CheckResultKeyBasedOnOrderAndPricePnlStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers CheckResultKeyBasedOnOrderAndPricePnlStep
 */
final class CheckResultKeyBasedOnOrderAndPricePnlStepTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testGeneratedKey(
        float $qty,
        float $price,
        float $pnlStep,
        Symbol $symbol,
        Side $side,
        string $expectedResult,
    ): void {
        $generator = new CheckResultKeyBasedOnOrderAndPricePnlStep($price, $qty, $symbol, $side, $pnlStep);
        $result = $generator->generate();

        self::assertEquals($expectedResult, $result);
    }

    public function cases(): iterable
    {
        $step = 10;
        yield [0.1, 91017.5, $step, Symbol::BTCUSDT, Side::Buy, 'CRKBOPPS_BTCUSDT_buy_pct_10_step_91.35_priceLevel_996_orderQty_0.1'];
        yield [0.2, 96103.9, $step, Symbol::BTCUSDT, Side::Sell, 'CRKBOPPS_BTCUSDT_sell_pct_10_step_96.50_priceLevel_995_orderQty_0.2'];
        yield [0.1, 96113.9, $step, Symbol::BTCUSDT, Side::Buy, 'CRKBOPPS_BTCUSDT_buy_pct_10_step_96.50_priceLevel_995_orderQty_0.1'];
        yield [0.1, 96210.5, $step, Symbol::BTCUSDT, Side::Buy, 'CRKBOPPS_BTCUSDT_buy_pct_10_step_96.50_priceLevel_997_orderQty_0.1'];
        yield [0.1, 96251, $step, Symbol::BTCUSDT, Side::Buy, 'CRKBOPPS_BTCUSDT_buy_pct_10_step_96.50_priceLevel_997_orderQty_0.1'];
        yield [0.1, 96306.9, $step, Symbol::BTCUSDT, Side::Buy, 'CRKBOPPS_BTCUSDT_buy_pct_10_step_96.50_priceLevel_997_orderQty_0.1'];
        yield [0.1, 96307, $step, Symbol::BTCUSDT, Side::Buy, 'CRKBOPPS_BTCUSDT_buy_pct_10_step_96.50_priceLevel_998_orderQty_0.1'];

        yield [1, 1829.09, $step, Symbol::ETHUSDT, Side::Buy, 'CRKBOPPS_ETHUSDT_buy_pct_10_step_1.83_priceLevel_999_orderQty_1'];
        yield [1, 1799.09, $step, Symbol::ETHUSDT, Side::Buy, 'CRKBOPPS_ETHUSDT_buy_pct_10_step_1.80_priceLevel_999_orderQty_1'];
        yield [1, 1699.09, $step, Symbol::ETHUSDT, Side::Buy, 'CRKBOPPS_ETHUSDT_buy_pct_10_step_1.70_priceLevel_999_orderQty_1'];

        yield [1, 1.0856, $step, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_10_step_0.0011_priceLevel_986_orderQty_1'];
        yield [1, 1.0857, $step, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_10_step_0.0011_priceLevel_987_orderQty_1'];
        yield [1, 1.0868, $step, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_10_step_0.0011_priceLevel_987_orderQty_1'];
        yield [1, 1.0871, $step, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_10_step_0.0011_priceLevel_988_orderQty_1'];
        yield [1, 1.0878, $step, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_10_step_0.0011_priceLevel_988_orderQty_1'];
        yield [1, 1.0889, $step, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_10_step_0.0011_priceLevel_989_orderQty_1'];
        yield [1, 1.1867, $step, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_10_step_0.0012_priceLevel_988_orderQty_1'];
        yield [1, 1, 10, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_10_step_0.0010_priceLevel_1000_orderQty_1'];
        yield [1, 0.9871, 10, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_10_step_0.0010_priceLevel_987_orderQty_1'];

        yield [1, 1.1867, 20, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_20_step_0.0024_priceLevel_494_orderQty_1'];
        yield [1, 0.9871, 50, Symbol::FARTCOINUSDT, Side::Buy, 'CRKBOPPS_FARTCOINUSDT_buy_pct_50_step_0.0049_priceLevel_201_orderQty_1'];

        yield [1, 0.05471, $step, Symbol::ARCUSDT, Side::Buy, 'CRKBOPPS_ARCUSDT_buy_pct_10_step_0.00005_priceLevel_1094_orderQty_1'];
        yield [1, 0.05475, $step, Symbol::ARCUSDT, Side::Buy, 'CRKBOPPS_ARCUSDT_buy_pct_10_step_0.00006_priceLevel_912_orderQty_1'];
        yield [1, 0.0548, $step, Symbol::ARCUSDT, Side::Buy, 'CRKBOPPS_ARCUSDT_buy_pct_10_step_0.00006_priceLevel_913_orderQty_1'];
        yield [1, 0.05578, $step, Symbol::ARCUSDT, Side::Buy, 'CRKBOPPS_ARCUSDT_buy_pct_10_step_0.00006_priceLevel_929_orderQty_1'];


        $step = 20;
        yield [1, 0.05577, $step, Symbol::ARCUSDT, Side::Buy, 'CRKBOPPS_ARCUSDT_buy_pct_20_step_0.00011_priceLevel_507_orderQty_1'];
        yield [1, 0.05587, $step, Symbol::ARCUSDT, Side::Buy, 'CRKBOPPS_ARCUSDT_buy_pct_20_step_0.00011_priceLevel_507_orderQty_1'];
        yield [1, 145, $step, Symbol::SOLUSDT, Side::Buy, 'CRKBOPPS_SOLUSDT_buy_pct_20_step_0.291_priceLevel_498_orderQty_1'];
        yield [1, 2.63, $step, Symbol::RAYDIUMUSDT, Side::Buy, 'CRKBOPPS_RAYDIUMUSDT_buy_pct_20_step_0.0053_priceLevel_496_orderQty_1'];

        $step = 50;
        yield [1, 0.002209, $step, Symbol::FOXYUSDT, Side::Buy, 'CRKBOPPS_FOXYUSDT_buy_pct_50_step_0.000011_priceLevel_200_orderQty_1'];
        yield [1, 0.002209, $step, Symbol::FOXYUSDT, Side::Buy, 'CRKBOPPS_FOXYUSDT_buy_pct_50_step_0.000011_priceLevel_200_orderQty_1'];
    }
}
