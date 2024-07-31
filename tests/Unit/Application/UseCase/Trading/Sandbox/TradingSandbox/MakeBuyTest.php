<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox\TradingSandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder as PB;
use App\Tests\Factory\TickerFactory;

class MakeBuyTest extends AbstractTestOfTradingSandbox
{
    /**
     * @dataProvider buyTestDataProvider
     */
    public function testMakeBuy(
        Ticker $ticker, float $initialFree, array $positions, SandboxBuyOrder $sandboxBuyOrder,
        float $expectedFree, float $expectedAvailable, array $positionsAfterMakeBuy
    ): void {
        $symbol = $ticker->symbol;
        $initialState = new SandboxState($ticker, new CoinAmount($symbol->associatedCoin(), $initialFree), ...$positions);
        $this->tradingSandbox->setState($initialState);

        // Act
        $newState = $this->tradingSandbox->processOrders($sandboxBuyOrder);

        // Assert
        self::assertEquals($expectedFree, $newState->getFreeBalance()->value());
        self::assertEquals($expectedAvailable, $newState->getAvailableBalance()->value());
        self::assertSandboxPositionsIsEqualsTo($positionsAfterMakeBuy, $newState);
    }

    public function buyTestDataProvider(): iterable
    {
        $ticker = TickerFactory::withEqualPrices(Symbol::BTCUSDT, 68000);
        $initialFree = 178.9803;
        $shortInitial = PB::short()->entry(67533.430)->size(0.187)->liq(75173)->build();
        $longInitial = PB::long()->entry(59426.560)->size(0.077)->build();

        # SHORT
        $sandboxBuyOrder = new SandboxBuyOrder(Symbol::BTCUSDT, Side::Sell, 68150, 0.001);
        $expectedFree = 178.2235; $expectedAvailable = 110.1483;
        $shortAfterMake = PB::short()->entry(67536.70962765957)->size($shortInitial->size + 0.001)->liq(75105.97)->opposite($longInitial)->build();
        yield 'short with hedge' => [
            $ticker, $initialFree, [$shortInitial, $longInitial], $sandboxBuyOrder,
            $expectedFree, $expectedAvailable, [$longInitial, $shortAfterMake]
        ];

        # LONG
        $sandboxBuyOrder = new SandboxBuyOrder(Symbol::BTCUSDT, Side::Buy, 68150, 0.001);
        $expectedFree = 178.2242; $expectedAvailable = 111.0181;
        $longAfterMake = PB::long()->entry(59538.39897435897)->size($longInitial->size + 0.001)->build();
        $shortAfterMake = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size)->liq(75227.40)->opposite($longAfterMake)->build();
        yield 'long with hedge' => [
            $ticker, $initialFree, [$shortInitial, $longInitial], $sandboxBuyOrder,
            $expectedFree, $expectedAvailable, [$longAfterMake, $shortAfterMake]
        ];
    }
}