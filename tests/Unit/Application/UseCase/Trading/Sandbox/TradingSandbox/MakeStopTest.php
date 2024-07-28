<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox\TradingSandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder as PB;
use App\Tests\Factory\TickerFactory;

use function array_map;

class MakeStopTest extends AbstractTestOfTradingSandbox
{
    /**
     * @dataProvider stopTestDataProvider
     */
    public function testMakeStop(
        Ticker $ticker, float $initialFree, array $positions, SandboxStopOrder $sandboxStopOrder,
        float $expectedFree, float $expectedAvailable, array $positionsAfterMakeStop
    ): void {
        $symbol = $ticker->symbol;
        $initialState = new SandboxState($ticker, new CoinAmount($symbol->associatedCoin(), $initialFree), ...$positions);
        $this->tradingSandbox->setState($initialState);

        // Act
        $newState = $this->tradingSandbox->processOrders($sandboxStopOrder);

        // Assert
        self::assertEquals($expectedFree, $newState->getFreeBalance()->value());
        self::assertEquals($expectedAvailable, $newState->getAvailableBalance()->value());

        $actualPositions = array_map(fn(Side $side) => $newState->getPosition($side), array_map(static fn(Position $p) => $p->side, $positionsAfterMakeStop));
        self::assertEquals($positionsAfterMakeStop, $actualPositions);
    }

    public function stopTestDataProvider(): iterable
    {
        $ticker = TickerFactory::withEqualPrices(Symbol::BTCUSDT, 68000);
        $initialFree = 98.0352;
        $shortInitial = PB::short()->entry(67533.430)->size(0.188)->liq(75361.600)->build();
        $longInitial = PB::long()->entry(59426.560)->size(0.084)->build();

        # SHORT
        $sandboxStopOrder = new SandboxStopOrder(Symbol::BTCUSDT, Side::Sell, 68150, 0.001);

        $expectedFree = 98.1001; $expectedAvailable = 34.5934;
        $shortAfterMakeStop = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size - 0.001)->liq(75434.95)->opposite($longInitial)->build();

        yield 'short with hedge' => [
            $ticker, $initialFree, [$shortInitial, $longInitial], $sandboxStopOrder,
            $expectedFree, $expectedAvailable, [$longInitial, $shortAfterMakeStop]
        ];

        # LONG
        $sandboxStopOrder = new SandboxStopOrder(Symbol::BTCUSDT, Side::Buy, 68150, 0.001);
        $expectedFree = 107.4401; $expectedAvailable = 42.7003;
        $longAfterMakeStop = PB::long()->entry($longInitial->entryPrice)->size($longInitial->size - 0.001)->liq($longInitial->liquidationPrice)->build();
        $shortAfterMakeStop = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size)->liq(75302.62)->opposite($longAfterMakeStop)->build();

        yield 'long with hedge' => [
            $ticker, $initialFree, [$shortInitial, $longInitial], $sandboxStopOrder,
            $expectedFree, $expectedAvailable, [$longAfterMakeStop, $shortAfterMakeStop]
        ];
    }
}