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
        $shortInitial = PB::short()->entry(67533.430)->size(0.188)->liq(75361.600)->opposite($longInitial)->build();
        $positionsBefore = [$shortInitial, $longInitial];
        $initialState = new SandboxState($ticker, ContractBalanceTestHelper::contractBalanceBasedOnFree($initialFree, $positionsBefore, $ticker), ...$positionsBefore);

        # SHORT
        $sandboxStopOrder = new SandboxStopOrder($symbol, Side::Sell, 68150, 0.001);
        $longInitialCloned = PositionClone::clean($longInitial)->create();
        $shortAfterMake = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size - 0.001)->liq(75434.95)->opposite($longInitialCloned)->build();
        $positionsAfter = [$longInitialCloned, $shortAfterMake];

        $expectedFree = 98.10013; $expectedAvailable = 34.5934;
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxStopOrder->price), ContractBalanceTestHelper::contractBalanceBasedOnFree($expectedFree, $positionsAfter, $ticker), ...$positionsAfter);

        yield TestCaseDescriptionHelper::sandboxTestCaseCaption($initialState, $sandboxStopOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxStopOrder, $expectedStateAfterMake, $expectedAvailable
        ];

        # LONG
        $sandboxStopOrder = new SandboxStopOrder($symbol, Side::Buy, 68150, 0.001);
        $longAfterMakeStop = PB::long()->entry($longInitial->entryPrice)->size($longInitial->size - 0.001)->liq($longInitial->liquidationPrice)->build();
        $shortAfterMake = PB::short()->entry($shortInitial->entryPrice)->size($shortInitial->size)->liq(75302.62)->opposite($longAfterMakeStop)->build();
        $positionsAfter = [$longAfterMakeStop, $shortAfterMake];

        $expectedFree = 107.44014; $expectedAvailable = 42.7003;
        $expectedStateAfterMake = new SandboxState(TickerFactory::withEqualPrices($symbol, $sandboxStopOrder->price), ContractBalanceTestHelper::contractBalanceBasedOnFree($expectedFree, $positionsAfter, $ticker), ...$positionsAfter);

        yield TestCaseDescriptionHelper::sandboxTestCaseCaption($initialState, $sandboxStopOrder, $expectedStateAfterMake) => [
            $initialState, $sandboxStopOrder, $expectedStateAfterMake, $expectedAvailable
        ];
    }
}
