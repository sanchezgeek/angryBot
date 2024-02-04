<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Order\Parameter\TriggerBy;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

use function uuid_create;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler
 */
final class PushStopsCornerCasesTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;

    private const SYMBOL = Symbol::BTCUSDT;
    private const WITHOUT_OPPOSITE_CONTEXT = Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT;
    private const OPPOSITE_BUY_DISTANCE = 38;
    private const ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP = 15;
    private const ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP = 7;
    private const POSITION_LIQUIDATION_WARNING_DELTA = PushStopsHandler::LIQUIDATION_WARNING_DELTA;
    private const POSITION_LIQUIDATION_CRITICAL_DELTA = PushStopsHandler::LIQUIDATION_CRITICAL_DELTA;

    protected MessageBusInterface $messageBus;
    protected EventDispatcherInterface $eventDispatcher;
    protected HedgeService $hedgeService;
    protected StopService $stopService;
    protected StopRepository $stopRepository;

    protected OrderServiceInterface|MockObject $orderServiceMock;
    protected PositionServiceInterface|MockObject $positionServiceMock;
    protected ExchangeServiceInterface|MockObject $exchangeServiceMock;
    protected ExchangeAccountServiceInterface|MockObject $exchangeAccountServiceMock;
    protected LoggerInterface $loggerMock;
    protected ClockInterface $clockMock;

    private PushStopsHandler $handler;

    protected function setUp(): void
    {
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $this->stopService = self::getContainer()->get(StopService::class);
        $this->stopRepository = self::getContainer()->get(StopRepository::class);

        $this->orderServiceMock = $this->createMock(OrderServiceInterface::class);
        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->exchangeAccountServiceMock = $this->createMock(ExchangeAccountServiceInterface::class);
        $this->positionServiceMock = $this->createMock(PositionServiceInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->clockMock = $this->createMock(ClockInterface::class);

        $this->handler = new PushStopsHandler(
            $this->stopRepository,
            $this->exchangeAccountServiceMock,
            $this->orderServiceMock,
            $this->messageBus,
            $this->exchangeServiceMock,
            $this->positionServiceMock,
            $this->loggerMock,
            $this->clockMock
        );

        self::truncateStops();
    }

    /**
     * @dataProvider closeByMarketWhenApiReturnedBadRequestErrorTestDataProvider
     *
     * @todo | Maybe move to \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsTest::pushStopsTestCases ?
     */
    public function testCloseByMarketWhenApiReturnedBadRequestError(Ticker $ticker, Position $position, Stop $stop, TriggerBy $expectedTriggerBy): void
    {
        $this->haveTicker($ticker);
        $this->havePosition($position);
        $this->applyDbFixtures(new StopFixture($stop));

        $this->positionServiceMock
            ->expects(self::once())
            ->method('addConditionalStop')
            ->with($position, $stop->getPrice(), $stop->getVolume(), $expectedTriggerBy)
            ->willThrowException(
                new TickerOverConditionalOrderTriggerPrice('Already over trigger price')
            );

        $this->orderServiceMock
            ->expects(self::once())
            ->method('closeByMarket')
            ->with($position, $stop->getVolume())
            ->willReturn($exchangeOrderId = uuid_create());

        // Act
        ($this->handler)(new PushStops($position->symbol, $position->side));

        // Assert
        self::seeStopsInDb(
            (clone $stop)->setExchangeOrderId($exchangeOrderId)
        );
    }

    public function closeByMarketWhenApiReturnedBadRequestErrorTestDataProvider(): iterable
    {
        yield '[BTCUSDT SHORT] liquidation not in critical range' => [
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29050, 29060, 29070),
            'position' => PositionFactory::short(self::SYMBOL, 29000, 1, 100, $ticker->markPrice->value() + self::POSITION_LIQUIDATION_WARNING_DELTA + 1),
            'stop' => StopBuilder::short(5, 29051, 0.011)->withTD(10)->build(),
            'expectedTriggerBy' => TriggerBy::IndexPrice,
        ];

        yield '[BTCUSDT SHORT] liquidation in critical range' => [
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29050, 29060, 29070),
            'position' => PositionFactory::short(self::SYMBOL, 29000, 1, 100, $ticker->markPrice->value() + self::POSITION_LIQUIDATION_WARNING_DELTA),
            'stop' => StopBuilder::short(5, 29061, 0.011)->withTD(10)->build(),
            'expectedTriggerBy' => TriggerBy::MarkPrice,
        ];
    }

    /**
     * @dataProvider closeByMarketWhenCurrentPriceOverStopAndLiquidationPriceInCriticalRangeTestDataProvider
     *
     * @todo | Maybe move to \App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsTest::pushStopsTestCases ?
     */
    public function testCloseByMarketWhenCurrentPriceOverStopAndLiquidationPriceInCriticalRange(Ticker $ticker, Position $position, Stop $stop): void
    {
        $this->haveTicker($ticker);
        $this->havePosition($position);
        $this->applyDbFixtures(new StopFixture($stop));

        $this->positionServiceMock->expects(self::never())->method('addConditionalStop');
        $this->orderServiceMock
            ->expects(self::once())
            ->method('closeByMarket')
            ->with($position, $stop->getVolume())
            ->willReturn($exchangeOrderId = uuid_create());

        // Act
        ($this->handler)(new PushStops($position->symbol, $position->side));

        // Assert
        self::seeStopsInDb(
            (clone $stop)->setExchangeOrderId($exchangeOrderId)
        );
    }

    public function closeByMarketWhenCurrentPriceOverStopAndLiquidationPriceInCriticalRangeTestDataProvider(): iterable
    {
        yield 'BTCUSDT SHORT' => [
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29050, 29060, 29070),
            'position' => PositionFactory::short(self::SYMBOL, 29000, 1, 100, $ticker->markPrice->value() + self::POSITION_LIQUIDATION_CRITICAL_DELTA),
            'stop' => StopBuilder::short(5, 29060, 0.011)->withTD(10)->build(),
        ];
    }

    private function haveTicker(Ticker $ticker): void
    {
        $this->exchangeServiceMock->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }

    private function havePosition(Position $position): void
    {
        $this->positionServiceMock->method('getPosition')->with($position->symbol, $position->side)->willReturn($position);
    }
}
