<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox\TradingSandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder as PB;
use App\Tests\Factory\TickerFactory;
use App\Tests\Helper\ContractBalanceTestHelper;
use App\Tests\Helper\Tests\TestCaseDescriptionHelper;

/**
 * @group sandbox
 */
class MakeBuyTest extends AbstractTestOfTradingSandbox
{
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
        $this->tradingSandbox->processOrders($sandboxBuyOrder);
        $resultState = $this->tradingSandbox->getCurrentState();

        // Assert
        self::assertSandboxStateEqualsToExpected($expectedStateAfterMakeBuy, $resultState);
        self::assertEquals($expectedAvailable, $resultState->getAvailableBalance()->value());
    }

    public function buyTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 68000);
        $initialFree = 178.9803;

                            ### WITH BOTH POSITIONS OPENED (hedge-mode) ###

        $shortInitial = PB::short()->entry(67533.430)->size(0.187)->liq(75173)->build();
        $longInitial = PB::long()->entry(59426.560)->size(0.077)->build();
        $positionsBefore = [$shortInitial, $longInitial];
        $contractBalanceBefore = ContractBalanceTestHelper::contractBalanceBasedOnFree($initialFree, $positionsBefore, $ticker);
        $initialState = new SandboxState($ticker, $contractBalanceBefore, $contractBalanceBefore->free, ...$positionsBefore);

        # SHORT
        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Sell, 68150, 0.001);
        $longInitialCloned = PositionClone::clean($longInitial)->create();
        $shortAfterMake = PB::short()->entry(67536.70962765957)->size($shortInitial->size + 0.001)->liq(75099.83)->build($longInitialCloned);
        $positionsAfter = [$longInitialCloned, $shortAfterMake];

        $expectedFree = 177.541960175; $expectedAvailable = 109.4668;
        $contractBalance = ContractBalanceTestHelper::recalculateContractBalance($contractBalanceBefore, $shortInitial, $sandboxBuyOrder, $expectedFree);
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), $contractBalance, $contractBalance->free, ...$positionsAfter);

        yield TestCaseDescriptionHelper::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];

        # LONG
        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Buy, 68150, 0.001);
        $longAfterMake = PB::long()->entry(59538.39897435897)->size($longInitial->size + 0.001)->build();
        $shortAfterMake = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size)->liq(75221.14)->build($longAfterMake);
        $positionsAfter = [$longAfterMake, $shortAfterMake];

        $expectedFree = 177.542709825; $expectedAvailable = 110.3366;
        $contractBalance = ContractBalanceTestHelper::recalculateContractBalance($contractBalanceBefore, $longInitial, $sandboxBuyOrder, $expectedFree);
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), $contractBalance, $contractBalance->free, ...$positionsAfter);

        yield TestCaseDescriptionHelper::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];
                            ### ONLY ONE POSITION OPENED + make buy on the other side ###

        # LONG
        $initialFree =  11956.6364;
        $shortInitial = PB::short()->entry(67506.640)->size(0.189)->liq(131106.800)->build();
        $positionsBefore = [$shortInitial];
        $contractBalanceBefore = ContractBalanceTestHelper::contractBalanceBasedOnFree($initialFree, $positionsBefore, $ticker);
        $initialState = new SandboxState($ticker, $contractBalanceBefore, $contractBalanceBefore->free, ...$positionsBefore);

        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Buy, 63400, 0.001);
        $longAfterMake = PB::long()->entry($sandboxBuyOrder->price)->size($sandboxBuyOrder->volume)->build();
        $shortAfterMake = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size)->liq(131458.03)->build($longAfterMake);
        $positionsAfter = [$longAfterMake, $shortAfterMake];

        $expectedFree = 11955.2990087; $expectedAvailable = 11955.299;
        $contractBalance = ContractBalanceTestHelper::recalculateContractBalance($contractBalanceBefore, $longAfterMake, $sandboxBuyOrder, $expectedFree);
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), $contractBalance, $contractBalance->free, ...$positionsAfter);

        yield TestCaseDescriptionHelper::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];

        # SHORT
        $longInitial = PB::long()->entry(59426.560)->size(0.077)->liq(50000)->build();

        ## opened short will become support
        $initialFree =  956.6364;
        $contractBalanceBefore = ContractBalanceTestHelper::contractBalanceBasedOnFree($initialFree, [$longInitial], $ticker);
        $initialState = new SandboxState($ticker, $contractBalanceBefore, $contractBalanceBefore->free, $longInitial);
        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Sell, 63400, 0.001);

        $shortAfterMake = PB::short()->entry($sandboxBuyOrder->price)->size($sandboxBuyOrder->volume)->withoutLiquidation()->build();
        $longAfterMake = PB::long()->entry($longInitial->entryPrice)->size($longInitial->size)->liq(46507.43)->build($shortAfterMake);
        $positionsAfter = [$longAfterMake, $shortAfterMake];

        $expectedFree = 955.2983112999999; $expectedAvailable = 955.2983;
        $contractBalance = ContractBalanceTestHelper::recalculateContractBalance($contractBalanceBefore, $shortAfterMake, $sandboxBuyOrder, $expectedFree);
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), $contractBalance, $contractBalance->free, ...$positionsAfter);

        yield TestCaseDescriptionHelper::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];

        ## opened short will become main position
        $initialFree =  319.229;
        $contractBalanceBefore = ContractBalanceTestHelper::contractBalanceBasedOnFree($initialFree, [$longInitial], $ticker);
        $initialState = new SandboxState($ticker, $contractBalanceBefore, $contractBalanceBefore->free, $longInitial);
        $sandboxBuyOrder = new SandboxBuyOrder($symbol, Side::Sell, 67533.430, 0.187);

        $shortAfterMake = PB::short()->entry(67533.430)->size(0.187)->liq(74024.93)->build();
        $longAfterMake = PositionClone::clean($longInitial)->withoutLiquidation()->create();
        $positionsAfter = [$longAfterMake, $shortAfterMake];

        $expectedFree = 52.69288711624502; $expectedAvailable = 52.6929;
        $contractBalance = ContractBalanceTestHelper::recalculateContractBalance($contractBalanceBefore, $shortAfterMake, $sandboxBuyOrder, $expectedFree);
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxBuyOrder->price), $contractBalance, $contractBalance->free, ...$positionsAfter);

        yield TestCaseDescriptionHelper::sandboxTestCaseCaption($initialState, $sandboxBuyOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxBuyOrder, $expectedStateAfterMake, $expectedAvailable
        ];
    }
}
