<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\MarketBuy;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MarketBuyHandlerTest extends KernelTestCase
{
    use ByBitV5ApiRequestsMocker;

    private const SAFE_PRICE_DISTANCE = 2000;

    private MarketBuyHandler $marketBuyHandler;
    private TradingSandboxFactoryInterface $executionSandboxFactory;

    private OrderCostCalculator $orderCostCalculator;
    private CalcPositionLiquidationPriceHandler $liquidationCalculator;

    protected function setUp(): void
    {
        $this->orderCostCalculator = self::getContainer()->get(OrderCostCalculator::class);
        $this->liquidationCalculator = self::getContainer()->get(CalcPositionLiquidationPriceHandler::class);

        $this->executionSandboxFactory = $this->createMock(TradingSandboxFactoryInterface::class);

        $this->marketBuyHandler = new MarketBuyHandler(
            self::getContainer()->get(OrderServiceInterface::class),
            $this->executionSandboxFactory,
            self::getContainer()->get(ExchangeServiceInterface::class),
            self::getContainer()->get(PositionServiceInterface::class),
            self::SAFE_PRICE_DISTANCE
        );
    }

    /**
     * @dataProvider failTestCases
     */
    public function testFail(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, SandboxState $sandboxStateAfterMakeBuy, $expectedException): void
    {
        $this->haveTicker($ticker);
        $this->mockSandboxMakeBuyCall($ticker, $buyEntryDto, $sandboxStateAfterMakeBuy);

        // Assert
        self::expectExceptionObject($expectedException);

        // Act
        $this->marketBuyHandler->handle($buyEntryDto);
    }

    /**
     * @dataProvider allSuccessTestCases
     */
    public function testSuccess(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, SandboxState $sandboxStateAfterMakeBuy): void
    {
        $this->haveTicker($ticker);
        if (!$buyEntryDto->force) {
            $this->mockSandboxMakeBuyCall($ticker, $buyEntryDto, $sandboxStateAfterMakeBuy);
        }

        // Assert
        $this->expectsToMakeApiCalls(...self::successMarketBuyApiCallExpectations($buyEntryDto->symbol, [$buyEntryDto]));

        // Act
        $this->marketBuyHandler->handle($buyEntryDto);
    }

    /**
     * @dataProvider safeSuccessTestCases
     */
    public function testSuccessWhenSandboxThrewException(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, SandboxState $sandboxState): void
    {
        # ... but initial state is safe for buy
        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, $sandboxState->getPosition($buyEntryDto->positionSide));
        $sandboxBuyOrder = SandboxBuyOrder::fromMarketBuyEntryDto($buyEntryDto, $ticker->lastPrice);

        $sandboxMock = $this->createMock(TradingSandboxInterface::class);
        $sandboxMock->expects(self::once())->method('processOrders')->with($sandboxBuyOrder)->willThrowException(
            $exception = new RuntimeException('some error')
        );
        $this->executionSandboxFactory->expects(self::once())->method('byCurrentState')->with($buyEntryDto->symbol)->willReturn($sandboxMock);

        // Assert
        $this->expectsToMakeApiCalls(...self::successMarketBuyApiCallExpectations($buyEntryDto->symbol, [$buyEntryDto]));

        // Act
        $this->marketBuyHandler->handle($buyEntryDto);
    }

    public function failTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 65000);

        yield 'LONG' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Buy),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, new CoinAmount($symbol->associatedCoin(), 10), self::positionWithStateNOTSafeForMakeBuy($ticker, Side::Buy)),
            'expectedException' => new BuyIsNotSafeException(),
        ];

        yield 'SHORT' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Sell),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, new CoinAmount($symbol->associatedCoin(), 10), self::positionWithStateNOTSafeForMakeBuy($ticker, Side::Sell)),
            'expectedException' => new BuyIsNotSafeException(),
        ];
    }

    public function safeSuccessTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 65000);
        $freeBalance = new CoinAmount($symbol->associatedCoin(), 10);

        # LONG
        yield 'LONG, buy is safe' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Buy),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, $freeBalance, self::positionWithStateSafeForMakeBuy($ticker, Side::Buy)),
        ];

        # SHORT
        yield 'SHORT, buy is safe' => [
            '$ticker' => $ticker, '$buyDto' => self::simpleBuyDto($symbol, Side::Sell),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, $freeBalance, self::positionWithStateSafeForMakeBuy($ticker, Side::Sell)),
        ];
    }

    public function notSafeSuccessTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 65000);
        $freeBalance = new CoinAmount($symbol->associatedCoin(), 10);

        # LONG
        yield 'LONG, buy is NOT save .. but `force` => check skipped even' => [
            '$ticker' => $ticker, '$buyDto' => self::forceBuyDto($symbol, Side::Buy),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, $freeBalance, self::positionWithStateNOTSafeForMakeBuy($ticker, Side::Buy)),
        ];

        # SHORT
        yield 'SHORT, buy is NOT save .. but `force` => check skipped even' => [
            '$ticker' => $ticker, '$buyDto' => self::forceBuyDto($symbol, Side::Sell),
            '$sandboxState[afterBuy]' => new SandboxState($ticker, $freeBalance, self::positionWithStateNOTSafeForMakeBuy($ticker, Side::Sell)),
        ];
    }

    public function allSuccessTestCases(): iterable
    {
        foreach ([...$this->safeSuccessTestCases(), ...$this->notSafeSuccessTestCases()] as $key => $case) {
            yield $key => $case;
        }
    }

    private static function simpleBuyDto(Symbol $symbol, Side $side): MarketBuyEntryDto
    {
        return new MarketBuyEntryDto($symbol, $side, 0.001, false);
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

    private function mockSandboxMakeBuyCall(Ticker $ticker, MarketBuyEntryDto $buyEntryDto, $stateAfterExec): void
    {
        $sandboxBuyOrder = SandboxBuyOrder::fromMarketBuyEntryDto($buyEntryDto, $ticker->lastPrice);

        $sandboxMock = $this->createMock(TradingSandboxInterface::class);
        $sandboxMock->expects(self::once())->method('processOrders')->with($sandboxBuyOrder)->willReturn($stateAfterExec);
        $this->executionSandboxFactory->expects(self::once())->method('byCurrentState')->with($buyEntryDto->symbol)->willReturn($sandboxMock);
    }
}