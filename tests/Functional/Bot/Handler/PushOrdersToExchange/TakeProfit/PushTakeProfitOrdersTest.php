<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\TakeProfit;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
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
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\StopChecksChain;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\RateLimiterAwareTest;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

use function uuid_create;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler
 */
final class PushTakeProfitOrdersTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;
    use RateLimiterAwareTest;
    use SettingsAwareTest;

    private const SYMBOL = Symbol::BTCUSDT;

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
        $this->stopService = self::getContainer()->get(StopService::class);
        $this->stopRepository = self::getContainer()->get(StopRepository::class);

        $this->orderServiceMock = $this->createMock(OrderServiceInterface::class);
        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->positionServiceMock = $this->createMock(PositionServiceInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->clockMock = $this->createMock(ClockInterface::class);

        $this->handler = new PushStopsHandler(
            $this->stopRepository,
            $this->orderServiceMock,
            $this->messageBus,
            self::getContainer()->get(StopChecksChain::class),
            $this->exchangeServiceMock,
            $this->positionServiceMock,
            $this->loggerMock,
            $this->clockMock
        );

        self::truncateStops();
    }

    /**
     * @dataProvider pushTakeProfitOrdersTestCases
     *
     * @param Stop[] $stopsExpectedAfterHandle
     */
    public function testPushRelevantTakeProfitOrders(
        Position $position,
        Ticker $ticker,
        array $stopsFixtures,
        array $expectedCloseByMarketMethodCalls,
        array $stopsExpectedAfterHandle,
        array $mockedExchangeOrderIds
    ): void {
        $this->haveTicker($ticker);
        $this->positionServiceMock->method('getPosition')->with(self::SYMBOL, $position->side)->willReturn($position);
        $this->applyDbFixtures(...$stopsFixtures);

        $closeByMarketMethodCalls = [];
        $this->orderServiceMock->method('closeByMarket')
            ->willReturnCallback(
                function($position, $qty) use (&$mockedExchangeOrderIds, &$closeByMarketMethodCalls) {
                    if (!$nextExchangeOrderId = array_shift($mockedExchangeOrderIds)) {
                        throw new RuntimeException('Next exchange order id not found in provided stack');
                    }
                    $closeByMarketMethodCalls[] = [$position, $qty];

                    return $nextExchangeOrderId;
                }
            )
        ;

        ($this->handler)(new PushStops($position->symbol, $position->side));

        self::assertSame($expectedCloseByMarketMethodCalls, $closeByMarketMethodCalls);
        self::assertEmpty($mockedExchangeOrderIds);

        self::seeStopsInDb(...$stopsExpectedAfterHandle);
    }

    public function pushTakeProfitOrdersTestCases(): iterable
    {
        ### BTCUSDT SHORT

        $mockedExchangeOrderIds = [];
        yield [
            '$position' => $position = PositionFactory::short(self::SYMBOL, 29000),
            '$ticker' => TickerFactory::create(self::SYMBOL, 29070, 29060, 29050),
            '$stopFixtures' => [
                new StopFixture(StopBuilder::short(10, 29055, 0.1)->build()->setIsTakeProfitOrder()),  // must be pushed
                new StopFixture(StopBuilder::short(20, 29049, 0.2)->build()->setIsTakeProfitOrder()),  // must not be pushed
            ],
            'expectedCloseByMarketMethodCalls' => [
                [$position, 0.1],
            ],
            'stopsExpectedAfterHandle' => [
                ### pushed ###
                StopBuilder::short(10, 29055, 0.1)->build()->setIsTakeProfitOrder()->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create()),
                ### not pushed ###
                StopBuilder::short(20, 29049, 0.2)->build()->setIsTakeProfitOrder(),
            ],
            '$mockedExchangeOrderIds' => $mockedExchangeOrderIds
        ];

        $mockedExchangeOrderIds = [];
        yield [
            '$position' => $position = PositionFactory::short(self::SYMBOL, 29000),
            '$ticker' => TickerFactory::create(self::SYMBOL, 29020, 29010, 29000),
            '$stopFixtures' => [
                new StopFixture(StopBuilder::short(10, 29099, 0.1)->build()->setIsTakeProfitOrder()),  // must be pushed
            ],
            'expectedCloseByMarketMethodCalls' => [
                [$position, 0.1],
            ],
            'stopsExpectedAfterHandle' => [
                ### pushed ###
                StopBuilder::short(10, 29099, 0.1)->build()->setIsTakeProfitOrder()->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create()),
            ],
            '$mockedExchangeOrderIds' => $mockedExchangeOrderIds
        ];
    }

    protected function haveTicker(Ticker $ticker): void
    {
        $this->exchangeServiceMock->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }
}
