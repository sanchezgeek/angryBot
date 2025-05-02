<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\MarketBuy;

use App\Application\UseCase\Trading\MarketBuy\Checks\MarketBuyCheckService;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Application\UseCase\Trading\Sandbox\SandboxStateInterface;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation\FurtherMainPositionLiquidationCheckParametersInterface;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Helper\ContractBalanceTestHelper;
use App\Tests\Mixin\RateLimiterAwareTest;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\PHPUnit\Assertions;
use App\Tests\PHPUnit\TestLogger;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * @group market-buy
 */
class MarketBuyHandlerTest extends KernelTestCase
{
    use ByBitV5ApiRequestsMocker;
    use SettingsAwareTest;
    use RateLimiterAwareTest;

    private const SAFE_PRICE_DISTANCE = 2000;

    private SandboxStateFactoryInterface|MockObject $sandboxStateFactory;
    private TradingSandboxFactoryInterface|MockObject $executionSandboxFactory;
    private FurtherMainPositionLiquidationCheckParametersInterface|MockObject $mainPositionLiquidationParametersMock;
    private LoggerInterface $logger;

    private MarketBuyHandler $marketBuyHandler;

    protected function setUp(): void
    {
        $this->sandboxStateFactory = $this->createMock(SandboxStateFactoryInterface::class);
        $this->executionSandboxFactory = $this->createMock(TradingSandboxFactoryInterface::class);
        $this->logger = new TestLogger();

        $this->mainPositionLiquidationParametersMock = $this->createMock(FurtherMainPositionLiquidationCheckParametersInterface::class);
        $this->mainPositionLiquidationParametersMock->method('mainPositionSafeLiquidationPriceDelta')->willReturn((float)self::SAFE_PRICE_DISTANCE);

        $marketBuyCheckService = new MarketBuyCheckService(
            self::getContainer()->get(PositionServiceInterface::class),
            $this->executionSandboxFactory,
            $this->logger,
            $this->mainPositionLiquidationParametersMock
        );

        $this->marketBuyHandler = new MarketBuyHandler(
            $marketBuyCheckService,
            self::getContainer()->get(OrderServiceInterface::class),
            self::getContainer()->get(ExchangeServiceInterface::class),
            $this->sandboxStateFactory,
            self::makeRateLimiterFactory()
        );
    }

    /**
     * @dataProvider positionsWithTooNearLiquidationTestCases
     */
    public function testFail(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, SandboxState $sandboxStateAfterMakeBuy): void
    {
        $this->haveTicker($ticker);
        $this->mockSandboxWillBeCalledAndReturnNewState($ticker, $buyEntryDto, $sandboxStateAfterMakeBuy);

        // Act
        try {
            $this->marketBuyHandler->handle($buyEntryDto);
        } catch (Throwable $e) {
            // Arrange
            self::assertTrue(Assertions::exceptionEquals(new BuyIsNotSafeException('liquidationPrice is too near'), $e));
            return;
        }

        self::assertFalse(true);
    }

    /**
     * @dataProvider positionsWithTooNearLiquidationTestCases
     */
    public function testFailWhenSandboxThrewExceptionAndCurrentLiquidationIsNotInSafeRange(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, SandboxState $sandboxState): void
    {
        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, $sandboxState->getPosition($buyEntryDto->positionSide));

        $thrownException = new RuntimeException('some error');
        $this->mockSandboxWillBeCalledAndThrowException($ticker, $buyEntryDto, $thrownException);

        // Act
        try {
            $this->marketBuyHandler->handle($buyEntryDto);
        } catch (Throwable $e) {
            // Arrange
            self::assertTrue(Assertions::exceptionEquals(new BuyIsNotSafeException('liquidationPrice is too near'), $e));
            self::assertTrue(Assertions::errorLogged($this->logger, 'some error', 'critical'));
            return;
        }

        self::assertFalse(true);
    }

    public function positionsWithTooNearLiquidationTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 65000);
        $free = 10;

        $long = self::positionWithStateNOTSafeForMakeBuy($ticker, Side::Buy);
        yield 'LONG' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Buy),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, ContractBalanceTestHelper::contractBalanceBasedOnFree($free, [$long], $ticker), $symbol->associatedCoinAmount($free), $long),
        ];

        $short = self::positionWithStateNOTSafeForMakeBuy($ticker, Side::Sell);
        yield 'SHORT' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Sell),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, ContractBalanceTestHelper::contractBalanceBasedOnFree($free, [$short], $ticker), $symbol->associatedCoinAmount($free), $short),
        ];
    }

    /**
     * @dataProvider notSafeButForceOrderSuccessTestCases
     */
    public function testSuccessWhenMakeBuyForForcedOrder(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, SandboxState $sandboxStateAfterMakeBuy): void
    {
        $this->haveTicker($ticker);
        $this->mockSandboxWontBeCalled();

        // Assert
        $this->expectsToMakeApiCalls(...self::successMarketBuyApiCallExpectations($buyEntryDto->symbol, [$buyEntryDto]));

        // Act
        $this->marketBuyHandler->handle($buyEntryDto);
    }

    /**
     * @dataProvider safeBuySuccessTestCases
     */
    public function testSuccessWhenMakeBuyForSimpleOrder(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, SandboxState $sandboxStateAfterMakeBuy): void
    {
        $this->haveTicker($ticker);
        $this->mockSandboxWillBeCalledAndReturnNewState($ticker, $buyEntryDto, $sandboxStateAfterMakeBuy);

        // Assert
        $this->expectsToMakeApiCalls(...self::successMarketBuyApiCallExpectations($buyEntryDto->symbol, [$buyEntryDto]));

        // Act
        $this->marketBuyHandler->handle($buyEntryDto);
    }

    /**
     * @dataProvider safeBuySuccessTestCases
     */
    public function testSuccessWhenSandboxThrewException(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, SandboxState $sandboxState): void
    {
        # ... but initial state is safe for buy
        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, $sandboxState->getPosition($buyEntryDto->positionSide));

        $thrownException = new RuntimeException('some error');
        $this->mockSandboxWillBeCalledAndThrowException($ticker, $buyEntryDto, $thrownException);

        // Assert
        $this->expectsToMakeApiCalls(...self::successMarketBuyApiCallExpectations($buyEntryDto->symbol, [$buyEntryDto]));

        // Act
        $this->marketBuyHandler->handle($buyEntryDto);

        // Assert
        self::assertTrue(Assertions::errorLogged($this->logger, 'some error', 'critical'));
    }

    public function safeBuySuccessTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 65000);
        $free = 10;

        # LONG
        $long = self::positionWithStateSafeForMakeBuy($ticker, Side::Buy);
        yield 'LONG, buy is safe' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Buy),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, ContractBalanceTestHelper::contractBalanceBasedOnFree($free, [$long], $ticker), $symbol->associatedCoinAmount($free), $long),
        ];

        # SHORT
        $short = self::positionWithStateSafeForMakeBuy($ticker, Side::Sell);
        yield 'SHORT, buy is safe' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Sell),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, ContractBalanceTestHelper::contractBalanceBasedOnFree($free, [$short], $ticker), $symbol->associatedCoinAmount($free), $short),
        ];

        ### SHORT without liquidation
        $short = PositionBuilder::short()->entry(65000)->liq(0)->build();
        yield 'SHORT without liquidation' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Sell),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, ContractBalanceTestHelper::contractBalanceBasedOnFree($free, [$short], $ticker), $symbol->associatedCoinAmount($free), $short),
        ];

        $ticker = TickerFactory::withEqualPrices($symbol, 1500);

        ### LONG without liquidation
        $long = PositionBuilder::long()->entry(1500)->liq(0)->build();
        yield 'LONG without liquidation' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Buy),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, ContractBalanceTestHelper::contractBalanceBasedOnFree($free, [$long], $ticker), $symbol->associatedCoinAmount($free), $long),
        ];
    }

    public function notSafeButForceOrderSuccessTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 65000);
        $free = 10;

        # LONG
        $long = self::positionWithStateNOTSafeForMakeBuy($ticker, Side::Buy);
        yield 'LONG, buy is NOT safe .. but `force` => check skipped' => [
            '$ticker' => $ticker, '$buyDto' => self::forceBuyDto($symbol, Side::Buy),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, ContractBalanceTestHelper::contractBalanceBasedOnFree($free, [$long], $ticker), $symbol->associatedCoinAmount($free), $long),
        ];

        # SHORT
        $short = self::positionWithStateNOTSafeForMakeBuy($ticker, Side::Sell);
        yield 'SHORT, buy is NOT save .. but `force` => check skipped' => [
            '$ticker' => $ticker, '$buyDto' => self::forceBuyDto($symbol, Side::Sell),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, ContractBalanceTestHelper::contractBalanceBasedOnFree($free, [$short], $ticker), $symbol->associatedCoinAmount($free), $short),
        ];
    }

//    public function allSuccessTestCases(): iterable
//    {
//        foreach ([...$this->safeBuySuccessTestCases(), ...$this->notSafeButForceOrderSuccessTestCases()] as $key => $case) {
//            yield $key => $case;
//        }
//    }

    private static function simpleBuyDto(Symbol $symbol, Side $side): MarketBuyEntryDto
    {
        return new MarketBuyEntryDto($symbol, $side, 0.005, false);
    }

    private static function forceBuyDto(Symbol $symbol, Side $side): MarketBuyEntryDto
    {
        return new MarketBuyEntryDto($symbol, $side, 0.001, true);
    }

    private static function positionWithStateSafeForMakeBuy(Ticker $ticker, Side $side): Position
    {
        $liquidation = $side->isShort() ? $ticker->lastPrice->add(self::SAFE_PRICE_DISTANCE) : $ticker->lastPrice->sub(self::SAFE_PRICE_DISTANCE);

        return PositionBuilder::bySide($side)->entry($ticker->lastPrice)->liq($liquidation->value())->build();
    }

    private static function positionWithStateNOTSafeForMakeBuy(Ticker $ticker, Side $side): Position
    {
        $notSafeDistance = self::SAFE_PRICE_DISTANCE - 1;
        $liquidation = $side->isShort() ? $ticker->lastPrice->add($notSafeDistance) : $ticker->lastPrice->sub($notSafeDistance);

        return PositionBuilder::bySide($side)->entry($ticker->lastPrice)->liq($liquidation->value())->build();
    }

    private function mockSandboxWillBeCalledAndReturnNewState(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, SandboxState $stateAfterExec): void
    {
        $sandboxMock = $this->mockEmptySandbox($ticker->symbol);
        $sandboxMock->expects(self::once())->method('processOrders')->with(SandboxBuyOrder::fromMarketBuyEntryDto($buyEntryDto, $ticker->lastPrice));
        $sandboxMock->method('getCurrentState')->willReturn($stateAfterExec);
    }

    private function mockSandboxWillBeCalledAndThrowException(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, \Throwable $exception): void
    {
        $sandboxMock = $this->mockEmptySandbox($ticker->symbol);
        $sandboxMock->expects(self::once())->method('processOrders')
            ->with(SandboxBuyOrder::fromMarketBuyEntryDto($buyEntryDto, $ticker->lastPrice))
            ->willThrowException($exception);
    }

    private function mockEmptySandbox(Symbol $symbol): TradingSandboxInterface|MockObject
    {
        $initialState = $this->createMock(SandboxStateInterface::class);
        $initialState->method('getSymbol')->willReturn($symbol);

        $this->sandboxStateFactory->expects(self::once())->method('byCurrentTradingAccountState')->with($symbol)->willReturn($initialState);

        $sandboxMock = $this->createMock(TradingSandboxInterface::class);
        // @todo | use stub instead?
        $sandboxMock->expects(self::once())->method('setState')->with($initialState);

        $this->executionSandboxFactory->expects(self::once())->method('empty')->with($symbol)->willReturn($sandboxMock);

        return $sandboxMock;
    }

    private function mockSandboxWontBeCalled(): void
    {
        $this->executionSandboxFactory->expects(self::never())->method(self::anything());
    }
}
