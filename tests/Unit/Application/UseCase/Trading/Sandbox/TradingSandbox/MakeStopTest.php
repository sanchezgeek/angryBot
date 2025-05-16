<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox\TradingSandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder as PB;
use App\Tests\Factory\TickerFactory;
use App\Tests\Helper\ContractBalanceTestHelper;
use App\Tests\Helper\Tests\TestCaseDescriptionHelper;

/**
 * @group sandbox
 */
class MakeStopTest extends AbstractTestOfTradingSandbox
{
    /**
     * @dataProvider makeStopTestDataProvider
     */
    public function testMakeStop(
        SandboxState $initialState,
        SandboxStopOrder $sandboxStopOrder,
        SandboxState $expectedStateAfterMakeStop,
        float $expectedAvailable,
    ): void {
        $this->tradingSandbox->setState($initialState);

        // Act
        $this->tradingSandbox->processOrders($sandboxStopOrder);
        $resultState = $this->tradingSandbox->getCurrentState();

        // Assert
        self::assertSandboxStateEqualsToExpected($expectedStateAfterMakeStop, $resultState);
        self::assertEquals($expectedAvailable, $resultState->getAvailableBalance()->value());
    }

    public function makeStopTestDataProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 68000);
        $initialFree = 98.0352;
        $longInitial = PB::long()->entry(59426.560)->size(0.084)->build();
        $shortInitial = PB::short()->entry(67533.430)->size(0.188)->liq(75361.600)->build($longInitial);
        $positionsBefore = [$shortInitial, $longInitial];
        $contractBalance = ContractBalanceTestHelper::contractBalanceBasedOnFree($initialFree, $positionsBefore, $ticker);
        $initialState = new SandboxState($ticker, $contractBalance, $contractBalance->free, ...$positionsBefore);

        # SHORT
        $sandboxStopOrder = new SandboxStopOrder($symbol, Side::Sell, 68150, 0.001);
        $longInitialCloned = PositionClone::clean($longInitial)->create();
        $shortAfterMake = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size - 0.001)->liq(75434.53)->build($longInitialCloned);
        $positionsAfter = [$longInitialCloned, $shortAfterMake];

        $expectedFree = 98.0564643; $expectedAvailable = 34.5498;
        $contractBalance = ContractBalanceTestHelper::contractBalanceBasedOnFree($expectedFree, $positionsAfter, $ticker);
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxStopOrder->price), $contractBalance, $contractBalance->free, ...$positionsAfter);

        yield TestCaseDescriptionHelper::sandboxTestCaseCaption($initialState, $sandboxStopOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxStopOrder, $expectedStateAfterMake, $expectedAvailable
        ];

        # LONG
        $sandboxStopOrder = new SandboxStopOrder($symbol, Side::Buy, 68150, 0.001);
        $longAfterMakeStop = PB::long()->entry($longInitial->entryPrice)->size($longInitial->size - 0.001)->liq($longInitial->liquidationPrice)->build();
        $shortAfterMake = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size)->liq(75301.48)->build($longAfterMakeStop);
        $positionsAfter = [$longAfterMakeStop, $shortAfterMake];

        $expectedFree = 107.3205056; $expectedAvailable = 42.5807;
        $contractBalance = ContractBalanceTestHelper::contractBalanceBasedOnFree($expectedFree, $positionsAfter, $ticker);
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxStopOrder->price), $contractBalance, $contractBalance->free, ...$positionsAfter);

        yield TestCaseDescriptionHelper::sandboxTestCaseCaption($initialState, $sandboxStopOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxStopOrder, $expectedStateAfterMake, $expectedAvailable
        ];
    }
}
