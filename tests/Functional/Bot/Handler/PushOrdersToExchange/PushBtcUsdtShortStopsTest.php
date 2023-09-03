<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantStopOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantStopsHandler;
use App\Bot\Application\Service\Orders\BuyOrderService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Helper\VolumeHelper;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\BuyOrderTest;
use App\Tests\Mixin\StopTest;

/**
 * @covers PushRelevantStopsHandler
 */
final class PushBtcUsdtShortStopsTest extends PushOrderHandlerTestAbstract
{
    private const WITHOUT_OPPOSITE_CONTEXT = Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT;
    private const OPPOSITE_BUY_DISTANCE = 38;
    private const ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP = 15;
    private const ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP = 7;

    use StopTest;
    use BuyOrderTest;

    private const SYMBOL = Symbol::BTCUSDT;

    private PushRelevantStopsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BuyOrderService $buyOrderService */
        $buyOrderService = self::getContainer()->get(BuyOrderService::class);

        $this->handler = new PushRelevantStopsHandler($this->hedgeService, $this->stopRepository, $buyOrderService, $this->stopService, $this->messageBus, $this->eventDispatcher, $this->exchangeServiceMock, $this->positionServiceStub, $this->loggerMock, $this->clockMock, 0);

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

        ($this->handler)(new PushRelevantStopOrders($position->symbol, $position->side));

        self::assertSame($expectedStopAddMethodCalls, $this->positionServiceStub->getAddStopCallsStack());
        self::seeStopsInDb(...$stopsExpectedAfterHandle);
    }

    public function pushStopsTestCases(): iterable
    {
        $addPriceDelta = self::ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP;
        $addTriggerDelta = self::ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP;

        yield [
            '$position' => $position = PositionFactory::short(self::SYMBOL, 29000),
            '$ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29050),
            '$stopFixtures' => [
                new StopFixture(StopBuilder::short(1, 29055, 0.011)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create())), // must not be pushed (not active)
                new StopFixture(StopBuilder::short(5, 29030, 0.011)->withTD(10)->build()), // must be pushed (before ticker)
                new StopFixture(StopBuilder::short(10, 29060, 0.1)->withTD(10)->build()), // must be pushed (by tD)
                new StopFixture(StopBuilder::short(15, 29061, 0.1)->withTD(10)->build()),
                new StopFixture(StopBuilder::short(20, 29155, 0.2)->withTD(100)->build()),
                new StopFixture(StopBuilder::short(30, 29055, 0.3)->withTD(5)->build()),  // must be pushed (by tD)
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
            ],
            '$mockedExchangeOrderIds' => $mockedExchangeOrderIds
        ];
    }

    /**
     * @dataProvider oppositeBuyOrderCreateTestCases
     */
    public function testCreateOppositeBuyOrders(
        Position $position,
        Ticker $ticker,
        array $stops,
        array $buyOrdersExpectedAfterHandle,
        array $mockedStopExchangeOrderIds
    ): void {
        $this->haveTicker($ticker);
        $this->positionServiceStub->havePosition($position);
        $this->positionServiceStub->setMockedExchangeOrdersIds($mockedStopExchangeOrderIds);
        $this->applyDbFixtures(...$stops);

        ($this->handler)(new PushRelevantStopOrders($position->symbol, $position->side));

        self::seeBuyOrdersInDb(...$buyOrdersExpectedAfterHandle);
    }

    private function oppositeBuyOrderCreateTestCases(): iterable
    {
        $position = PositionFactory::short(self::SYMBOL, 29000);
        $ticker = TickerFactory::create(self::SYMBOL, 29050);

        $distance = self::OPPOSITE_BUY_DISTANCE;

        yield 'No opposite' => [
            '$position' => $position, '$ticker' => $ticker,
            '$stopFixtures' => [
                new StopFixture(StopBuilder::short(10, 29060, 0.001)->withContext([self::WITHOUT_OPPOSITE_CONTEXT => true])->withTD(10)->build()),
                new StopFixture(StopBuilder::short(20, 29055, 0.005)->withContext([self::WITHOUT_OPPOSITE_CONTEXT => true])->withTD(10)->build()),
                new StopFixture(StopBuilder::short(30, 29060, 0.005)->withContext([self::WITHOUT_OPPOSITE_CONTEXT => true])->withTD(10)->build()),
            ],
            'buyOrdersExpectedAfterHandle' => [],
            '$mockedExchangeOrderIds' => []
        ];

        $mockedStopExchangeOrderIds = [];
        yield 'Small order => One opposite' => [
            '$position' => $position, '$ticker' => $ticker,
            '$stopFixtures' => [
                new StopFixture(StopBuilder::short(10, $secondStopPrice = 29060, 0.001)->withTD(10)->build()),
                new StopFixture(StopBuilder::short(20, $firstStopPrice = 29055, 0.005)->withTD(10)->build()),
            ],
            'buyOrdersExpectedAfterHandle' => [
                BuyOrderBuilder::short(1, $firstStopPrice - $distance, 0.005)->build()->setOnlyAfterExchangeOrderExecutedContext($mockedStopExchangeOrderIds[] = uuid_create()),
                BuyOrderBuilder::short(2, $secondStopPrice - $distance, 0.001)->build()->setOnlyAfterExchangeOrderExecutedContext($mockedStopExchangeOrderIds[] = uuid_create()),
            ],
            '$mockedStopExchangeOrderIds' => $mockedStopExchangeOrderIds
        ];

        $mockedStopExchangeOrderIds = [uuid_create()];
        $stops = [StopBuilder::short(20, $stopPrice = 29055, $stopVolume = 0.006)->withTD(10)->build()];
        $oppositeBuyOrders = [
            BuyOrderBuilder::short(1, $stopPrice - $distance, VolumeHelper::round($stopVolume / 3))->build(),
            BuyOrderBuilder::short(2, $stopPrice - $distance - $distance / 2, VolumeHelper::round($stopVolume / 3.5))->build(),
            BuyOrderBuilder::short(3, $stopPrice - $distance - $distance / 3.8, VolumeHelper::round($stopVolume / 4.5))->build(),
        ];
        $this->setOnlyAfterExchangeOrderExecutedContext($mockedStopExchangeOrderIds[0], ...$oppositeBuyOrders);
        yield 'Big order => Partial opposites: ' . $this->ordersDesc(...$stops) . ' => ' . $this->ordersDesc(...$oppositeBuyOrders) => [
            '$position' => $position, '$ticker' => $ticker,
            '$stopFixtures' => array_map(static fn(Stop $stop) => new StopFixture($stop), $stops),
            'buyOrdersExpectedAfterHandle' => $oppositeBuyOrders,
            '$mockedStopExchangeOrderIds' => $mockedStopExchangeOrderIds
        ];
    }
}
