<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks;

use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\Helper\PositionClone;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation\FurtherMainPositionLiquidationCheck;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation\FurtherMainPositionLiquidationCheckParametersInterface;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopCheckResult;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Dto\StopChecksContext;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\RateLimiterAwareTest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers FurtherMainPositionLiquidationCheck
 */
final class FurtherMainPositionLiquidationCheckTest extends TestCase
{
    use RateLimiterAwareTest;

    private TradingSandboxFactoryInterface|MockObject $tradingSandboxFactory;
    private SandboxStateFactoryInterface|MockObject $sandboxStateFactory;
    private FurtherMainPositionLiquidationCheckParametersInterface|MockObject $parameters;

    private FurtherMainPositionLiquidationCheck $check;

    protected function setUp(): void
    {
        $this->tradingSandboxFactory = $this->createMock(TradingSandboxFactoryInterface::class);
        $this->sandboxStateFactory = $this->createMock(SandboxStateFactoryInterface::class);
        $this->parameters = $this->createMock(FurtherMainPositionLiquidationCheckParametersInterface::class);
        $positionService = $this->createMock(PositionServiceInterface::class);

        $this->check = new FurtherMainPositionLiquidationCheck(
            $this->parameters,
            self::makeRateLimiterFactory(),
            $positionService,
            $this->tradingSandboxFactory,
            $this->sandboxStateFactory,
        );
    }

    /**
     * @dataProvider cases
     */
    public function testStopCanBeExecuted(
        Position $stoppedSupportPosition,
        Stop $stop,
        Ticker $ticker,
        float $safePriceDistance,
        float $newMainPositionLiquidation,
        StopCheckResult $expectedResult,
    ): void {
        assert($stoppedSupportPosition->isSupportPosition(), new RuntimeException('Stopped position must be support'));
        $symbol = $stoppedSupportPosition->symbol;
        $mainPosition = $stoppedSupportPosition->oppositePosition;

        # initial context
        $context = StopChecksContext::create($ticker, $stoppedSupportPosition);
        $initialSandboxState = self::mockSandboxState($ticker, $stoppedSupportPosition, $mainPosition);
        $context->currentSandboxState = $initialSandboxState;

        # sandbox return state with new mainPosition.liquidationPrice
        $newMainPositionState = PositionClone::clean($mainPosition)->withLiquidation($newMainPositionLiquidation)->create();
        $sandbox = $this->createMock(TradingSandboxInterface::class);
        $newSupportPositionState = PositionClone::clean($stoppedSupportPosition)->withSize($stoppedSupportPosition->size - $stop->getVolume())->create();
        $newSandboxState = self::mockSandboxState($ticker, $newSupportPositionState, $newMainPositionState);
        $sandbox->expects(self::once())->method('getCurrentState')->willReturn($newSandboxState);
        $this->tradingSandboxFactory->expects(self::once())->method('empty')->with($symbol)->willReturn($sandbox);

        $this->parameters->method('mainPositionSafeLiquidationPriceDelta')->with($symbol, $mainPosition->side, $ticker->markPrice->value())->willReturn($safePriceDistance);

        $result = $this->check->check($stop, $context);

        self::assertEquals($expectedResult, $result);
    }

    private static function mockSandboxState(Ticker $ticker, Position ...$positions): SandboxState
    {
        return new SandboxState($ticker, new ContractBalance($ticker->symbol->associatedCoin(), 100500, 100500, 100500), $ticker->symbol->associatedCoinAmount(100500), ...$positions);
    }

    public function cases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $safePriceDistance = 5000;

        # SHORT
        $main = PositionBuilder::short()->entry(100000)->size(1)->liq(101000)->build();
        $support = PositionBuilder::long()->entry(80000)->size(0.5)->opposite($main)->build();
        $stop = StopBuilder::long(1, 100000, 0.001, $symbol)->build();
        $ticker = TickerFactory::withEqualPrices($symbol, $stop->getPrice());

        $newMainPositionLiquidation = 105000;
        yield [$support, $stop, $ticker, $safePriceDistance, $newMainPositionLiquidation, self::result(true, $ticker, $support, $stop, $safePriceDistance, $newMainPositionLiquidation)];
        $newMainPositionLiquidation = 104999;
        yield [$support, $stop, $ticker, $safePriceDistance, $newMainPositionLiquidation, self::result(false, $ticker, $support, $stop, $safePriceDistance, $newMainPositionLiquidation)];

        # LONG
        $main = PositionBuilder::long()->entry(80000)->size(1)->liq(79000)->build();
        $support = PositionBuilder::short()->entry(100000)->size(0.5)->opposite($main)->build();
        $stop = StopBuilder::short(1, 80000, 0.001)->build();
        $ticker = TickerFactory::withEqualPrices($symbol, $stop->getPrice());

        $newMainPositionLiquidation = 75000;
        yield [$support, $stop, $ticker, $safePriceDistance, $newMainPositionLiquidation, self::result(true, $ticker, $support, $stop, $safePriceDistance, $newMainPositionLiquidation)];
        $newMainPositionLiquidation = 75001;
        yield [$support, $stop, $ticker, $safePriceDistance, $newMainPositionLiquidation, self::result(false, $ticker, $support, $stop, $safePriceDistance, $newMainPositionLiquidation)];

        // @todo check 0
    }

    private static function result(bool $success, Ticker $ticker, Position $closingPosition, Stop $stop, float $safePriceDistance, float $mainPositionLiquidationPriceNew): StopCheckResult
    {
        $executionPrice = $stop->isCloseByMarketContextSet() ? $ticker->markPrice : $ticker->symbol->makePrice($stop->getPrice());

        $reason = sprintf(
            '%s | id=%d, qty=%s, price=%s | safeDistance=%s, liquidation=%s',
            $closingPosition,
            $stop->getId(),
            $stop->getVolume(),
            $executionPrice,
            $safePriceDistance,
            $mainPositionLiquidationPriceNew
        );

        return $success ? FurtherMainPositionLiquidationCheck::positiveResult($reason) : FurtherMainPositionLiquidationCheck::negativeResult($reason);
    }
}
