<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\FurtherPositionLiquidationCheck\BuyAndCheckFurtherPositionLiquidation;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\FurtherPositionLiquidationCheck\FurtherPositionLiquidationAfterBuyIsTooClose;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyCheckFailureEnum;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Helper\OutputHelper;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Helper\Trading\PositionPreset;
use App\Tests\Mixin\RateLimiterAwareTest;
use App\Tests\Mixin\Sandbox\SandboxUnitTester;
use App\Trading\Application\Check\Contract\AbstractTradingCheckResult;
use App\Trading\Application\Check\Dto\TradingCheckContext;
use App\Trading\Application\Check\Dto\TradingCheckResult;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BuyAndCheckFurtherPositionLiquidationTest extends TestCase
{
    use SandboxUnitTester;
    use RateLimiterAwareTest;

    private TradingParametersProviderInterface|MockObject $parameters;

    private BuyAndCheckFurtherPositionLiquidation $check;

    protected function setUp(): void
    {
        $this->initializeSandboxTester();
        $this->parameters = $this->createMock(TradingParametersProviderInterface::class);

        $this->check = new BuyAndCheckFurtherPositionLiquidation(
            $this->parameters,
            self::makeRateLimiterFactory(),
            $this->createMock(PositionServiceInterface::class),
            $this->tradingSandboxFactory,
            $this->sandboxStateFactory,
        );
    }

    public function testCheckWrapExceptionThrownBySandbox(): void
    {
        $symbol = Symbol::ETHUSDT;
        $side = Side::Buy;
        $ticker = TickerFactory::withEqualPrices($symbol, 1050);
        $orderDto = self::simpleBuyDto($symbol, $side);
        $thrownException = new RuntimeException('some error');
        $context = TradingCheckContext::withTicker($ticker);

        $sandboxOrder = SandboxBuyOrder::fromMarketBuyEntryDto($orderDto, $ticker->lastPrice);
        $sandbox = $this->makeSandboxThatWillThrowException($sandboxOrder, $thrownException);
        $this->mockFactoryToReturnSandbox($symbol, $sandbox);

        // Assert
        $message = sprintf('[%s] Got "%s" error while processing %s order in sandbox (id = %d)', OutputHelper::shortClassName(BuyAndCheckFurtherPositionLiquidation::class), $thrownException->getMessage(), SandboxBuyOrder::class, $orderDto->sourceBuyOrder->getId());
        self::expectExceptionObject(new UnexpectedSandboxExecutionException($message, 0, $thrownException));

        // Assert
        $this->check->check($orderDto, $context);
    }

    /**
     * @dataProvider cases
     */
    public function testBuyOrderCanBeExecuted(
        Ticker $ticker,
        MarketBuyEntryDto $orderDto,
        float $safePriceDistance,
        float $newLiquidation,
        AbstractTradingCheckResult $expectedResult,
    ): void {
        $symbol = $ticker->symbol;
        $positionSide = $orderDto->positionSide;

        $position = PositionBuilder::bySide($positionSide)->symbol($symbol)->build();

        # initial context
        $context = TradingCheckContext::withTicker($ticker);
        $initialSandboxState = self::sampleSandboxState($ticker, $position);
        $context->currentSandboxState = $initialSandboxState;

        # sandbox return state with new position.liquidationPrice
        $newPositionState = PositionClone::clean($position)->withLiquidation($newLiquidation)->create();
        $sandbox = $this->createMock(TradingSandboxInterface::class);
        $newSandboxState = self::sampleSandboxState($ticker, $newPositionState);
        $sandbox->method('getCurrentState')->willReturn($newSandboxState);
        $this->tradingSandboxFactory->method('empty')->with($symbol)->willReturn($sandbox);

        $this->parameters->method('safeLiquidationPriceDelta')->with($symbol, $position->side, $ticker->markPrice->value())->willReturn($safePriceDistance);

        $result = $this->check->check($orderDto, $context);

        self::assertEquals($expectedResult, $result);
    }

    public function cases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 65000);
        $safeDistance = 5000;

        ### SHORT
        $side = Side::Sell;
        $order = self::simpleBuyDto($symbol, $side);

        // safe
        $positionAfterSandbox = PositionPreset::safeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success(self::info($ticker, $positionAfterSandbox, $order, $safeDistance, $liq))];

        // also safe (without liquidation)
        $positionAfterSandbox = PositionPreset::withoutLiquidation($side);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success(self::info($ticker, $positionAfterSandbox, $order, $safeDistance, $liq))];

        // not safe
        $positionAfterSandbox = PositionPreset::NOTSafeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::liquidationTooCloseResult($ticker, $positionAfterSandbox, $order, $safeDistance, $liq)];

        // forced
        $order = self::forceBuyDto($symbol, $side);
        $positionAfterSandbox = PositionPreset::NOTSafeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success('force flag is set')];

        ### LONG
        $side = Side::Buy;
        $order = self::simpleBuyDto($symbol, $side);

        // safe
        $positionAfterSandbox = PositionPreset::safeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success(self::info($ticker, $positionAfterSandbox, $order, $safeDistance, $liq))];

        // also safe (without liquidation)
        $positionAfterSandbox = PositionPreset::withoutLiquidation($side);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success(self::info($ticker, $positionAfterSandbox, $order, $safeDistance, $liq))];

        // not safe
        $positionAfterSandbox = PositionPreset::NOTSafeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::liquidationTooCloseResult($ticker, $positionAfterSandbox, $order, $safeDistance, $liq)];

        // forced
        $order = self::forceBuyDto($symbol, $side);
        $positionAfterSandbox = PositionPreset::NOTSafeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success('force flag is set')];
    }

    private static function success(string $info): TradingCheckResult
    {
        return TradingCheckResult::succeed(OutputHelper::shortClassName(BuyAndCheckFurtherPositionLiquidation::class), $info);
    }

    private static function failed(string $info): TradingCheckResult
    {
        return TradingCheckResult::failed(OutputHelper::shortClassName(BuyAndCheckFurtherPositionLiquidation::class), BuyCheckFailureEnum::FurtherLiquidationIsTooClose, $info);
    }

    private static function liquidationTooCloseResult(Ticker $ticker, Position $position, MarketBuyEntryDto $orderDto, float $safePriceDistance, float $liquidationPrice): FurtherPositionLiquidationAfterBuyIsTooClose
    {
        $info = self::info($ticker, $position, $orderDto, $safePriceDistance, $liquidationPrice);

        return FurtherPositionLiquidationAfterBuyIsTooClose::create(OutputHelper::shortClassName(BuyAndCheckFurtherPositionLiquidation::class), $ticker->markPrice, $ticker->symbol->makePrice($liquidationPrice), $safePriceDistance, $info);
    }

    private static function info(Ticker $ticker, Position $position, MarketBuyEntryDto $orderDto, float $safePriceDistance, float $liquidationPrice): string
    {
        // @todo | liquidation | null
        if ($liquidationPrice === 0.00) {
            return sprintf(
                '%s | id=%d, qty=%s, price=%s | result position has no liquidation',
                $position, $orderDto->sourceBuyOrder->getId(), $orderDto->volume, $ticker->lastPrice
            );
        }

        $liquidationPrice = $ticker->symbol->makePrice($liquidationPrice);

        return sprintf(
            '%s | id=%d, qty=%s, price=%s | safeDistance=%s, liquidation=%s, delta=%s',
            $position, $orderDto->sourceBuyOrder->getId(), $orderDto->volume, $ticker->lastPrice, $safePriceDistance, $liquidationPrice, $liquidationPrice->deltaWith($ticker->markPrice)
        );
    }

    private static function simpleBuyDto(Symbol $symbol, Side $side): MarketBuyEntryDto
    {
        $buyOrder = new BuyOrder(1, 100500, 0.005, $symbol, $side);

        return MarketBuyEntryDto::fromBuyOrder($buyOrder);
    }

    private static function forceBuyDto(Symbol $symbol, Side $side): MarketBuyEntryDto
    {
        return new MarketBuyEntryDto($symbol, $side, 0.001, true);
    }
}
