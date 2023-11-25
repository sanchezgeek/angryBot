<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Helper\PriceHelper;
use App\Helper\VolumeHelper;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Functional\Bot\Handler\PushOrdersToExchange\PushOrderHandlerTestAbstract;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\StopsTester;

use function array_map;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler
 */
final class PushStopsTest extends PushOrderHandlerTestAbstract
{
    private const WITHOUT_OPPOSITE_CONTEXT = Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT;
    private const OPPOSITE_BUY_DISTANCE = 38;
    private const ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP = 15;
    private const ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP = 7;

    use StopsTester;
    use BuyOrdersTester;

    private const SYMBOL = Symbol::BTCUSDT;

    private PushStopsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

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
            $this->positionServiceStub,
            $this->loggerMock,
            $this->clockMock
        );

        self::truncateStops();
        self::truncateBuyOrders();
    }

    /**
     * @dataProvider pushStopsTestCases
     *
     * @param Stop[] $stopsExpectedAfterHandle
     */
    public function testPushRelevantStopOrders(
        Position $position,
        Ticker $ticker,
        array $stopsFixtures,
        array $expectedStopAddMethodCalls,
        array $stopsExpectedAfterHandle,
        array $mockedExchangeOrderIds
    ): void {
        $this->haveTicker($ticker);
        $this->positionServiceStub->havePosition($position);
        $this->positionServiceStub->setMockedExchangeOrdersIds($mockedExchangeOrderIds);
        $this->applyDbFixtures(...$stopsFixtures);

        ($this->handler)(new PushStops($position->symbol, $position->side));

        self::assertSame($expectedStopAddMethodCalls, $this->positionServiceStub->getAddStopCallsStack());
        self::seeStopsInDb(...$stopsExpectedAfterHandle);
    }

    public function pushStopsTestCases(): iterable
    {
        $addPriceDelta = self::ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP;
        $addTriggerDelta = self::ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP;

        yield 'BTCUSDT SHORT' => [
            '$position' => $position = PositionFactory::short(self::SYMBOL, 29000),
            '$ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29050),
            '$stopFixtures' => [
                new StopFixture(StopBuilder::short(1, 29055, 0.011)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create())), // must not be pushed (not active)
                new StopFixture(StopBuilder::short(5, 29030, 0.011)->withTD(10)->build()), // must be pushed (before ticker)
                new StopFixture(StopBuilder::short(10, 29060, 0.1)->withTD(10)->build()), // must be pushed (by tD)
                new StopFixture(StopBuilder::short(15, 29061, 0.1)->withTD(10)->build()),
                new StopFixture(StopBuilder::short(20, 29155, 0.2)->withTD(100)->build()),
                new StopFixture(StopBuilder::short(30, 29055, 0.3)->withTD(5)->build()),  // must be pushed (by tD)
                new StopFixture(StopBuilder::short(40, 29049, 0.33)->withTD(5)->build()->setIsTakeProfitOrder()),  // must not be pushed (isTakeProfitOrder)
            ],
            'expectedStopAddMethodCalls' => [
                [$position, $ticker, $ticker->indexPrice + $addPriceDelta, 0.011],
                [$position, $ticker, 29055.0, 0.3],
                [$position, $ticker, 29060.0, 0.1],
            ],
            'stopsExpectedAfterHandle' => [
                ### pushed (in right order) ###
                // initial price is before ticker => set new price + push
                StopBuilder::short(5, $ticker->indexPrice + $addPriceDelta, 0.011)->withTD(10 + $addTriggerDelta)->build()
                    ->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create())
                    ->setOriginalPrice(29030),
                // simple push
                StopBuilder::short(30, 29055, 0.3)->withTD(5)->build()->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create()),
                StopBuilder::short(10, 29060, 0.1)->withTD(10)->build()->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create()),

                ### unchanged ###
                StopBuilder::short(1, 29055, 0.011)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId),
                StopBuilder::short(15, 29061, 0.1)->withTD(10)->build(),
                StopBuilder::short(20, 29155, 0.2)->withTD(100)->build(),
                StopBuilder::short(40, 29049, 0.33)->withTD(5)->build()->setIsTakeProfitOrder(),
            ],
            '$mockedExchangeOrderIds' => $mockedExchangeOrderIds
        ];

        $mockedExchangeOrderIds = [];
        yield 'BTCUSDT LONG' => [
            '$position' => $position = PositionFactory::long(self::SYMBOL, 29000),
            '$ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29050),
            '$stopFixtures' => [
                new StopFixture(StopBuilder::long(1, 29045, 0.011)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create())), // must not be pushed (not active)
                new StopFixture(StopBuilder::long(5, 29070, 0.011)->withTD(10)->build()), // must be pushed (before ticker)
                new StopFixture(StopBuilder::long(10, 29040, 0.1)->withTD(10)->build()), // must be pushed (by tD)
                new StopFixture(StopBuilder::long(15, 29039, 0.1)->withTD(10)->build()),
                new StopFixture(StopBuilder::long(20, 28949, 0.2)->withTD(100)->build()),
                new StopFixture(StopBuilder::long(30, 29045, 0.3)->withTD(5)->build()),  // must be pushed (by tD)
            ],
            'expectedStopAddMethodCalls' => [
                [$position, $ticker, $ticker->indexPrice - $addPriceDelta, 0.011],
                [$position, $ticker, 29045.0, 0.3],
                [$position, $ticker, 29040.0, 0.1],
            ],
            'stopsExpectedAfterHandle' => [
                ### pushed (in right order) ###
                // initial price is before ticker => set new price + push
                StopBuilder::long(5, $ticker->indexPrice - $addPriceDelta, 0.011)->withTD(10 + $addTriggerDelta)->build()
                    ->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create())
                    ->setOriginalPrice(29070),
                // simple push
                StopBuilder::long(30, 29045, 0.3)->withTD(5)->build()->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create()),
                StopBuilder::long(10, 29040, 0.1)->withTD(10)->build()->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create()),

                ### unchanged ###
                StopBuilder::long(1, 29045, 0.011)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId),
                StopBuilder::long(15, 29039, 0.1)->withTD(10)->build(),
                StopBuilder::long(20, 28949, 0.2)->withTD(100)->build(),
            ],
            '$mockedExchangeOrderIds' => $mockedExchangeOrderIds
        ];
    }

    /**
     * @dataProvider oppositeBuyOrderCreateTestCases
     *
     * @param BuyOrder[] $buyOrdersExpectedAfterHandle
     */
    public function testCreateOppositeBuyOrders(
        Position $position,
        Ticker $ticker,
        array $stops,
        array $mockedStopExchangeOrderIds,
        array $buyOrdersExpectedAfterHandle,
    ): void {
        $this->haveTicker($ticker);
        $this->positionServiceStub->havePosition($position);
        $this->positionServiceStub->setMockedExchangeOrdersIds($mockedStopExchangeOrderIds);
        $this->applyDbFixtures(...$stops);

        ($this->handler)(new PushStops($position->symbol, $position->side));

        self::seeBuyOrdersInDb(...self::cloneBuyOrders(...$buyOrdersExpectedAfterHandle));
    }

    private function oppositeBuyOrderCreateTestCases(): iterable
    {
        # BTCUSDT SHORT
        $position = PositionFactory::short(self::SYMBOL, 29000); $ticker = TickerFactory::create(self::SYMBOL, 29050);

        yield '[BTCUSDT SHORT] No opposite' => [
            '$position' => $position, '$ticker' => $ticker,
            '$stopFixtures' => [
                new StopFixture(StopBuilder::short(10, 29060, 0.001)->withTD(10)->build()->setIsWithoutOppositeOrder()),
                new StopFixture(StopBuilder::short(20, 29055, 0.005)->withTD(10)->build()->setIsWithoutOppositeOrder()),
                new StopFixture(StopBuilder::short(30, 29060, 0.005)->withTD(10)->build()->setIsWithoutOppositeOrder()),
            ],
            '$mockedExchangeOrderIds' => [],
            'buyOrdersExpectedAfterHandle' => [],
        ];

        yield '[BTCUSDT SHORT] Small order => One opposite' => [
            '$position' => $position, '$ticker' => $ticker,
            '$stopFixtures' => array_map(static fn(Stop $stop) => new StopFixture($stop), $stops = [
                StopBuilder::short(10, 29060, 0.001)->withTD(10)->build(),
                StopBuilder::short(20, 29055, 0.005)->withTD(10)->build(),
            ]),
            '$mockedStopExchangeOrderIds' => $mockedStopExchangeOrderIds = [uuid_create(), uuid_create()],
            'buyOrdersExpectedAfterHandle' => [
                ...$this->expectedOppositeOrders($stops[1], $mockedStopExchangeOrderIds[0]),
                ...$this->expectedOppositeOrders($stops[0], $mockedStopExchangeOrderIds[1])
            ],
        ];

        $mockedStopExchangeOrderIds = [uuid_create()];
        $stops = [StopBuilder::short(20, 29055, 0.006)->withTD(10)->build()];
        $oppositeOrders = $this->expectedOppositeOrders($stops[0], $mockedStopExchangeOrderIds[0]);
        yield \sprintf(
            '[BTCUSDT SHORT] Big order => Partial opposites: %s => %s',
            $this->ordersDesc(...$stops),
            $this->ordersDesc(...$oppositeOrders)
        ) => [
            '$position' => $position, '$ticker' => $ticker,
            '$stopFixtures' => array_map(static fn(Stop $stop) => new StopFixture($stop), $stops),
            '$mockedStopExchangeOrderIds' => $mockedStopExchangeOrderIds,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        # BTCUSDT LONG
        $position = PositionFactory::long(self::SYMBOL, 29000); $ticker = TickerFactory::create(self::SYMBOL, 29050);

        yield '[BTCUSDT LONG] No opposite' => [
            '$position' => $position, '$ticker' => $ticker,
            '$stopFixtures' => [
                new StopFixture(StopBuilder::long(10, 29060, 0.001)->withTD(10)->build()->setIsWithoutOppositeOrder()),
                new StopFixture(StopBuilder::long(20, 29055, 0.005)->withTD(10)->build()->setIsWithoutOppositeOrder()),
                new StopFixture(StopBuilder::long(30, 29060, 0.005)->withTD(10)->build()->setIsWithoutOppositeOrder()),
            ],
            '$mockedExchangeOrderIds' => [],
            'buyOrdersExpectedAfterHandle' => [],
        ];

        yield '[BTCUSDT LONG] Small order => One opposite' => [
            '$position' => $position, '$ticker' => $ticker,
            '$stopFixtures' => array_map(static fn(Stop $stop) => new StopFixture($stop), $stops = [
                StopBuilder::long(10, 29040, 0.001)->withTD(10)->build(),
                StopBuilder::long(20, 29045, 0.005)->withTD(10)->build(),
            ]),
            '$mockedStopExchangeOrderIds' => $mockedStopExchangeOrderIds = [uuid_create(), uuid_create()],
            'buyOrdersExpectedAfterHandle' => [
                ...$this->expectedOppositeOrders($stops[1], $mockedStopExchangeOrderIds[0]),
                ...$this->expectedOppositeOrders($stops[0], $mockedStopExchangeOrderIds[1])
            ],
        ];

        $mockedStopExchangeOrderIds = [uuid_create()];
        $stops = [StopBuilder::long(20, 29045, 0.02)->withTD(10)->build()];
        $oppositeOrders = $this->expectedOppositeOrders($stops[0], $mockedStopExchangeOrderIds[0]);
        yield \sprintf(
            '[BTCUSDT LONG] Big order => Partial opposites: %s => %s',
            $this->ordersDesc(...$stops),
            $this->ordersDesc(...$oppositeOrders)
        ) => [
            '$position' => $position, '$ticker' => $ticker,
            '$stopFixtures' => array_map(static fn(Stop $stop) => new StopFixture($stop), $stops),
            '$mockedStopExchangeOrderIds' => $mockedStopExchangeOrderIds,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];
    }

    /**
     * @return BuyOrder[]
     */
    private function expectedOppositeOrders(Stop $stop, string $pushedStopExchangeOrderId, int $fromId = 1): array
    {
        $tD = 1;

        $side = $stop->getPositionSide();
        $stopPrice = $stop->getPrice();
        $stopVolume = $stop->getVolume();

        $baseDistance = $side->isLong()
            ? PushStopsHandler::LONG_BUY_ORDER_OPPOSITE_PRICE_DISTANCE
            : PushStopsHandler::SHORT_BUY_ORDER_OPPOSITE_PRICE_DISTANCE
        ;
        $baseDistance = $side->isLong() ? $baseDistance : -$baseDistance;

        if ($stopVolume >= 0.006) {
            $orders = [
                new BuyOrder($fromId++, PriceHelper::round($stopPrice + $baseDistance), VolumeHelper::round($stopVolume / 3), $tD, $side),
                new BuyOrder($fromId++, PriceHelper::round($stopPrice + $baseDistance + $baseDistance / 3.8), VolumeHelper::round($stopVolume / 4.5),$tD, $side),
                new BuyOrder($fromId, PriceHelper::round($stopPrice + $baseDistance + $baseDistance / 2), VolumeHelper::round($stopVolume / 3.5),$tD, $side),
            ];
        } else {
            $orders = [new BuyOrder($fromId, $stopPrice + $baseDistance, $stopVolume, $tD, $side)];
        }

        foreach ($orders as $order) {
            $order->setOnlyAfterExchangeOrderExecutedContext($pushedStopExchangeOrderId);
        }

        return $orders;
    }

    /**
     * @return BuyOrder[]
     */
    private static function cloneBuyOrders(BuyOrder ...$buyOrders): array
    {
        $startId = 1;
        $orders = [];
        foreach ($buyOrders as $buyOrder) {
            $orders[] =
                new BuyOrder(
                    $startId++,
                    $buyOrder->getPrice(),
                    $buyOrder->getVolume(),
                    $buyOrder->getTriggerDelta(),
                    $buyOrder->getPositionSide(),
                    $buyOrder->getContext()
                );
        }

        return $orders;
    }
}
