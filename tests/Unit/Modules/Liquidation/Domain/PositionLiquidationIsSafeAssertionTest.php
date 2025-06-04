<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Liquidation\Domain;

use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Liquidation\Domain\Assert\PositionLiquidationIsSafeAssertion;
use App\Liquidation\Domain\Assert\SafePriceAssertionStrategyEnum as Strategy;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Liquidation\Domain\Assert\PositionLiquidationIsSafeAssertion
 */
final class PositionLiquidationIsSafeAssertionTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testResult(Position $position, Ticker $ticker, float $safeDistance, Strategy $strategy, bool $expectedResult): void
    {
        self::assertEquals(
            $expectedResult,
            PositionLiquidationIsSafeAssertion::assert($position, $ticker, $safeDistance, $strategy)
        );
    }

    public function cases(): iterable
    {
        $s = SymbolEnum::BTCUSDT;

        ### SHORT
        $safeDistance = 5000;

        # liquidation === entry
        $short = PositionBuilder::short()->symbol($s)->entry(100000)->liq(100000)->build();
        yield [$short, TickerFactory::withEqualPrices($s, 99999), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 99999), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 99999), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Moderate, false];

        # liquidation placed AFTER ENTRY 1
        $short = PositionBuilder::short()->symbol($s)->entry(100000)->liq(106000)->build();

        yield [$short, TickerFactory::withEqualPrices($s, 101001), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Aggressive, true];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Aggressive, true];

        yield [$short, TickerFactory::withEqualPrices($s, 101001), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Conservative, true];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Conservative, true];

        yield [$short, TickerFactory::withEqualPrices($s, 101001), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Moderate, true];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Moderate, true];

        # liquidation placed AFTER ENTRY 2
        $short = PositionBuilder::short()->symbol($s)->entry(100000)->liq(103000)->build();

        yield [$short, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 98001), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 98000), $safeDistance, Strategy::Aggressive, true];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Aggressive, true];

        yield [$short, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Conservative, false];

        yield [$short, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 96001), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 96000), $safeDistance, Strategy::Moderate, true];

        # liquidation placed RIGHT after entry
        $short = PositionBuilder::short()->symbol($s)->entry(100000)->liq(100001)->build();

        yield [$short, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 95002), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 95001), $safeDistance, Strategy::Aggressive, true];

        yield [$short, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90001), $safeDistance, Strategy::Conservative, false];

        yield [$short, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 95002), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90002.1), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90002), $safeDistance, Strategy::Moderate, true];

        # liquidation placed RIGHT before entry
        $short = PositionBuilder::short()->symbol($s)->entry(100000)->liq(99999)->build();

        yield [$short, TickerFactory::withEqualPrices($s, 99999), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 99998), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 99900), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 95000), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 94999), $safeDistance, Strategy::Aggressive, true];

        yield [$short, TickerFactory::withEqualPrices($s, 99999), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 99900), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 95000), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 94999), $safeDistance, Strategy::Conservative, true];

        yield [$short, TickerFactory::withEqualPrices($s, 99999), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 99900), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 95000), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 94999), $safeDistance, Strategy::Moderate, true];

        # liquidation placed BEFORE entry
        $short = PositionBuilder::short()->symbol($s)->entry(100000)->liq(95000)->build();

        yield [$short, TickerFactory::withEqualPrices($s, 94999), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90001), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Aggressive, true];

        yield [$short, TickerFactory::withEqualPrices($s, 94999), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90001), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Conservative, true];

        yield [$short, TickerFactory::withEqualPrices($s, 94999), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90001), $safeDistance, Strategy::Moderate, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Moderate, true];

        # safe distance greater than ticker or entry
        $safeDistance = 200000;

        $short = PositionBuilder::short()->symbol($s)->entry(100000)->liq(100001)->build();
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Moderate, false];
        $short = PositionBuilder::short()->symbol($s)->entry(100000)->liq(99999)->build();
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Aggressive, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Conservative, false];
        yield [$short, TickerFactory::withEqualPrices($s, 90000), $safeDistance, Strategy::Moderate, false];


        ### LONG
        $safeDistance = 5000;

        # liquidation === entry
        $long = PositionBuilder::long()->symbol($s)->entry(100000)->liq(100000)->build();
        yield [$long, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Moderate, false];

        # liquidation === entry
        $long = PositionBuilder::long()->symbol($s)->entry(100000)->liq(100000)->build();
        yield [$long, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Moderate, false];

        # liquidation placed AFTER ENTRY 1
        $long = PositionBuilder::long()->symbol($s)->entry(100000)->liq(94000)->build();

        yield [$long, TickerFactory::withEqualPrices($s, 98999), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 99000), $safeDistance, Strategy::Aggressive, true];
        yield [$long, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Aggressive, true];

        yield [$long, TickerFactory::withEqualPrices($s, 98999), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 99000), $safeDistance, Strategy::Conservative, true];
        yield [$long, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Conservative, true];

        yield [$long, TickerFactory::withEqualPrices($s, 98999), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 99000), $safeDistance, Strategy::Moderate, true];
        yield [$long, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Moderate, true];

        # liquidation placed AFTER ENTRY 2
        $long = PositionBuilder::long()->symbol($s)->entry(100000)->liq(97000)->build();

        yield [$long, TickerFactory::withEqualPrices($s, 99999), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 101999), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 102000), $safeDistance, Strategy::Aggressive, true];
        yield [$long, TickerFactory::withEqualPrices($s, 110000), $safeDistance, Strategy::Aggressive, true];

        yield [$long, TickerFactory::withEqualPrices($s, 99999), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 102000), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 110000), $safeDistance, Strategy::Conservative, false];

        yield [$long, TickerFactory::withEqualPrices($s, 99999), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 103999), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 104000), $safeDistance, Strategy::Moderate, true];

        # liquidation placed RIGHT after entry
        $long = PositionBuilder::long()->symbol($s)->entry(100000)->liq(99999)->build();

        yield [$long, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 104998), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 104999), $safeDistance, Strategy::Aggressive, true];

        yield [$long, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 109999), $safeDistance, Strategy::Conservative, false];

        yield [$long, TickerFactory::withEqualPrices($s, 100000), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 104998), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 109997.9), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 109998), $safeDistance, Strategy::Moderate, true];

        # liquidation placed RIGHT before entry
        $long = PositionBuilder::long()->symbol($s)->entry(100000)->liq(100001)->build();

        yield [$long, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100002), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100100), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 105000), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 105001), $safeDistance, Strategy::Aggressive, true];

        yield [$long, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100100), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 105000), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 105001), $safeDistance, Strategy::Conservative, true];

        yield [$long, TickerFactory::withEqualPrices($s, 100001), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 100100), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 105000), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 105001), $safeDistance, Strategy::Moderate, true];

        # liquidation placed BEFORE entry
        $long = PositionBuilder::long()->symbol($s)->entry(100000)->liq(105000)->build();

        yield [$long, TickerFactory::withEqualPrices($s, 105001), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 109999), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 110000), $safeDistance, Strategy::Aggressive, true];

        yield [$long, TickerFactory::withEqualPrices($s, 105001), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 109999), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 110000), $safeDistance, Strategy::Conservative, true];

        yield [$long, TickerFactory::withEqualPrices($s, 105001), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 109999), $safeDistance, Strategy::Moderate, false];
        yield [$long, TickerFactory::withEqualPrices($s, 110000), $safeDistance, Strategy::Moderate, true];

        # safe distance greater than ticker or entry
        $safeDistance = 200000;

        $long = PositionBuilder::long()->symbol($s)->entry(100000)->liq(99999)->build();
        yield [$long, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Moderate, false];
        $long = PositionBuilder::long()->symbol($s)->entry(100000)->liq(101000)->build();
        yield [$long, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Aggressive, false];
        yield [$long, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Conservative, false];
        yield [$long, TickerFactory::withEqualPrices($s, 101000), $safeDistance, Strategy::Moderate, false];
    }
}
