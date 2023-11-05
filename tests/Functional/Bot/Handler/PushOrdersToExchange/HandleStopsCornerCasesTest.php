<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
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
final class HandleStopsCornerCasesTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;

    private const SYMBOL = Symbol::BTCUSDT;
    private const WITHOUT_OPPOSITE_CONTEXT = Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT;
    private const OPPOSITE_BUY_DISTANCE = 38;
    private const ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP = 15;
    private const ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP = 7;

    protected MessageBusInterface $messageBus;
    protected EventDispatcherInterface $eventDispatcher;
    protected HedgeService $hedgeService;
    protected StopService $stopService;
    protected StopRepository $stopRepository;
    protected OrderServiceInterface|MockObject $orderServiceMock;
    protected PositionServiceInterface|MockObject $positionServiceMock;
    protected ExchangeServiceInterface|MockObject $exchangeServiceMock;
    protected LoggerInterface $loggerMock;
    protected ClockInterface $clockMock;

    private PushStopsHandler $handler;

    protected function setUp(): void
    {
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $this->hedgeService = self::getContainer()->get(HedgeService::class);
        $this->stopService = self::getContainer()->get(StopService::class);
        $this->stopRepository = self::getContainer()->get(StopRepository::class);
        $this->orderServiceMock = $this->createMock(OrderServiceInterface::class);
        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->positionServiceMock = $this->createMock(PositionServiceInterface::class);

        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->clockMock = $this->createMock(ClockInterface::class);


        /** @var CreateBuyOrderHandler $createBuyOrderHandler */
        $createBuyOrderHandler = self::getContainer()->get(CreateBuyOrderHandler::class);

        $this->handler = new PushStopsHandler(
            $this->hedgeService,
            $this->stopRepository,
            $createBuyOrderHandler,
            $this->stopService,
            $this->messageBus,
            $this->orderServiceMock,
            $this->exchangeServiceMock,
            $this->positionServiceMock,
            $this->loggerMock,
            $this->clockMock,
            0
        );

        self::truncateStops();
    }

    public function testCloseByMarketWhenApiReturnedBadRequestError(): void {
        $this->haveTicker(
            $ticker = TickerFactory::create(self::SYMBOL, 29050)
        );

        $position = PositionFactory::short(self::SYMBOL, 29000);
        $this->positionServiceMock->method('getPosition')->with(self::SYMBOL, $position->side)->willReturn($position);

        $this->applyDbFixtures(
            new StopFixture(StopBuilder::short(5, $originalPrice = 29030, $qty = 0.011)->withTD(10)->build()),
        );

        $expectedUpdatedPriceValue = $ticker->indexPrice + self::ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP;
        $expectedNewTriggerDelta = 10 + self::ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP;

        $this->positionServiceMock
            ->expects(self::once())
            ->method('addStop')
            ->with($position, $ticker, $expectedUpdatedPriceValue, $qty)
            ->willThrowException(
                new TickerOverConditionalOrderTriggerPrice('Already over trigger price')
            );

        $this->orderServiceMock
            ->expects(self::once())
            ->method('closeByMarket')
            ->with($position, $qty)
            ->willReturn(
                $exchangeOrderId = uuid_create()
            );

        // Act
        ($this->handler)(new PushStops($position->symbol, $position->side));

        // Assert
        self::seeStopsInDb(
            StopBuilder::short(5, $expectedUpdatedPriceValue, $qty)->withTD($expectedNewTriggerDelta)->build()->setOriginalPrice($originalPrice)->setExchangeOrderId($exchangeOrderId)
        );
    }

    protected function haveTicker(Ticker $ticker): void
    {
        $this->exchangeServiceMock->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }
}
