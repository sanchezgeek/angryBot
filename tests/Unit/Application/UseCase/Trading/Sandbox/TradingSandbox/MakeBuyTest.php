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

                            ### WITH BOTH POSITIONS OPENED (hedge-mode) ###

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

        yield self::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];

                            ### ONLY ONE POSITION OPENED + make buy on the other side ###

        # LONG
        $initialFree =  11956.6364;
        $shortInitial = PB::short()->entry(67506.640)->size(0.189)->liq(131106.800)->build();
        $positionsBefore = [$shortInitial];
        $initialState = new SandboxState($ticker, new CoinAmount($symbol->associatedCoin(), $initialFree), ...$positionsBefore);

        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Buy, 63400, 0.001);
        $longAfterMake = PB::long()->entry($sandboxBuyOrder->price)->size($sandboxBuyOrder->volume)->build();
        $shortAfterMake = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size)->liq(131461.41)->opposite($longAfterMake)->build();
        $positionsAfter = [$longAfterMake, $shortAfterMake];

        $expectedFree = 11955.932999999999; $expectedAvailable = 11955.933;
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), new CoinAmount($symbol->associatedCoin(), $expectedFree), ...$positionsAfter);

        yield self::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];

        # SHORT
        $longInitial = PB::long()->entry(59426.560)->size(0.077)->liq(50000)->build();

        ## opened short will become support
        $initialFree =  956.6364;
        $initialState = new SandboxState($ticker, new CoinAmount($symbol->associatedCoin(), $initialFree), $longInitial);
        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Sell, 63400, 0.001);

        $shortAfterMake = PB::short()->entry($sandboxBuyOrder->price)->size($sandboxBuyOrder->volume)->liq(0)->build();
        $longAfterMake = PB::long()->entry($longInitial->entryPrice)->size($longInitial->size)->liq(46499.09)->opposite($shortAfterMake)->build();
        $positionsAfter = [$longAfterMake, $shortAfterMake];

        $expectedFree = 955.9322999999999; $expectedAvailable = 955.9323;
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), new CoinAmount($symbol->associatedCoin(), $expectedFree), ...$positionsAfter);

        yield self::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];

        ## opened short will become main position
        $initialFree =  319.229;
        $initialState = new SandboxState($ticker, new CoinAmount($symbol->associatedCoin(), $initialFree), $longInitial);
        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Sell, 67533.430, 0.187);

        $shortAfterMake = PB::short()->entry(67533.430)->size(0.187)->liq(75173)->build();
        $longAfterMake = PositionClone::clean($longInitial)->withoutLiquidation()->create();
        $positionsAfter = [$longAfterMake, $shortAfterMake];

        $expectedFree = 178.98039999999997; $expectedAvailable = 178.9804;
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), new CoinAmount($symbol->associatedCoin(), $expectedFree), ...$positionsAfter);

        yield self::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];
    }
}