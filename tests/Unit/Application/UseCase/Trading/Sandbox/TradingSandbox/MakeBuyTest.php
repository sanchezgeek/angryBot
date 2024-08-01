<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox\TradingSandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder as PB;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\Helper\TestCaseDescriptionHelper;

/**
 * @group sandbox
 */
class MakeBuyTest extends AbstractTestOfTradingSandbox
{
    use TestCaseDescriptionHelper;

    /**
     * @dataProvider buyTestDataProvider
     */
    public function testMakeBuy(
        SandboxState $initialState,
        SandboxBuyOrder $sandboxBuyOrder,
        SandboxState $expectedStateAfterMakeBuy,
        float $expectedAvailable,
    ): void {
        $this->tradingSandbox->setState($initialState);

        // Act
        $resultState = $this->tradingSandbox->processOrders($sandboxBuyOrder);

        // Assert
        self::assertSandboxStateEqualsToExpected($expectedStateAfterMakeBuy, $resultState);
        self::assertEquals($expectedAvailable, $resultState->getAvailableBalance()->value());
    }

    public function buyTestDataProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 68000);
        $initialFree = 178.9803;
        $shortInitial = PB::short()->entry(67533.430)->size(0.187)->liq(75173)->build();
        $longInitial = PB::long()->entry(59426.560)->size(0.077)->build();
        $positionsBefore = [$shortInitial, $longInitial];
        $initialState = new SandboxState($ticker, new CoinAmount($symbol->associatedCoin(), $initialFree), ...$positionsBefore);

        # SHORT
        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Sell, 68150, 0.001);
        $longInitialCloned = PositionClone::clean($longInitial)->create();
        $shortAfterMake = PB::short()->entry(67536.70962765957)->size($shortInitial->size + 0.001)->liq(75105.97)->opposite($longInitialCloned)->build();
        $positionsAfter = [$longInitialCloned, $shortAfterMake];

        $expectedFree = 178.2235; $expectedAvailable = 110.1483;
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), new CoinAmount($symbol->associatedCoin(), $expectedFree), ...$positionsAfter);

        // short with hedge
        yield self::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];

        # LONG
        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Buy, 68150, 0.001);
        $longAfterMake = PB::long()->entry(59538.39897435897)->size($longInitial->size + 0.001)->build();
        $shortAfterMake = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size)->liq(75227.40)->opposite($longAfterMake)->build();
        $positionsAfter = [$longAfterMake, $shortAfterMake];

        $expectedFree = 178.2242; $expectedAvailable = 111.0181;
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), new CoinAmount($symbol->associatedCoin(), $expectedFree), ...$positionsAfter);

        // long with hedge
        yield self::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];
    }
}