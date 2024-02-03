<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Strategy\StopCreate;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Service\OrderCostHelper;
use App\Infrastructure\ByBit\Service\ByBitMarketService;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Functional\Bot\Handler\PushOrdersToExchange\PushOrderHandlerTestAbstract;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;

use function array_map;
use function uuid_create;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler
 */
final class PushBuyOrdersCommonCasesTest extends PushOrderHandlerTestAbstract
{
    use StopsTester;
    use BuyOrdersTester;
    use ByBitV5ApiRequestsMocker;

    private const SYMBOL = Symbol::BTCUSDT;
    private const DEFAULT_STOP_TD = 37;

    private PushBuyOrdersHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        self::truncateStops();
        self::truncateBuyOrders();

        $this->handler = new PushBuyOrdersHandler(
            self::getBuyOrderRepository(),
            $this->stopRepository,
            $this->stopService,
            self::getContainer()->get(OrderCostHelper::class),

            self::getContainer()->get(ExchangeAccountServiceInterface::class),
            self::getContainer()->get(ByBitMarketService::class),
            self::getContainer()->get(OrderServiceInterface::class),

            $this->exchangeServiceMock,
            $this->positionServiceStub,

            $this->clockMock,
            $this->loggerMock,
        );
    }

    /**
     * @dataProvider pushBuyOrdersTestDataProvider
     *
     * @param BuyOrder[] $buyOrdersExpectedAfterHandle
     * @param ByBitApiCallExpectation[] $expectedMarketBuyApiCalls
     */
    public function testPushRelevantBuyOrders(
        Position $position,
        Ticker $ticker,
        array $buyOrdersFixtures,
        array $expectedMarketBuyApiCalls,
        array $buyOrdersExpectedAfterHandle,
    ): void {
        $this->expectsToMakeApiCalls(...$expectedMarketBuyApiCalls);
        $this->haveSpotBalance($position->symbol, 0);

        $this->haveTicker($ticker);
        $this->positionServiceStub->havePosition($position);
        $this->applyDbFixtures(...$buyOrdersFixtures);

        ($this->handler)(new PushBuyOrders($position->symbol, $position->side));

        self::seeBuyOrdersInDb(...$buyOrdersExpectedAfterHandle);
    }

    public function pushBuyOrdersTestDataProvider(): iterable
    {
        $position = PositionFactory::short($symbol = self::SYMBOL, 29000);
        $buyOrders = [
            BuyOrderBuilder::short(10, 29060, 0.01)->build(),  // must be pushed
            BuyOrderBuilder::short(15, 29060, 0.005)->build(),  // must be pushed and removed
            BuyOrderBuilder::short(20, 29155, 0.002)->build(),
            BuyOrderBuilder::short(30, 29055, 0.03)->build(), // must be pushed
            BuyOrderBuilder::short(40, 29055, 0.04)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create()), // must not be pushed (not active)
        ];
        $buyOrdersExpectedToPush = [$buyOrders[1], $buyOrders[0], $buyOrders[3]];
        $exchangeOrderIds = [];

        yield [
            '$position' => $position,
            '$ticker' => TickerFactory::create($symbol, 29050),
            '$buyOrdersFixtures' => array_map(static fn(BuyOrder $buyOrder) => new BuyOrderFixture($buyOrder), $buyOrders),
            'expectedMarketBuyCalls' => self::successMarketBuyApiCallExpectations($symbol, $buyOrdersExpectedToPush, $exchangeOrderIds),
            'buyOrdersExpectedAfterHandle' => [
                ### pushed (in right order) ###
                BuyOrderBuilder::short(10, 29060, 0.01)->build()->setExchangeOrderId($exchangeOrderIds[1]),
                BuyOrderBuilder::short(30, 29055, 0.03)->build()->setExchangeOrderId($exchangeOrderIds[2]),

                ### unchanged ###
                BuyOrderBuilder::short(20, 29155, 0.002)->build(),
                BuyOrderBuilder::short(40, 29055, 0.04)->build()->setExchangeOrderId($existedExchangeOrderId),
            ],
        ];
    }

    /**
     * @dataProvider createOppositeStopsTestCases
     *
     * @param Stop[] $stopsExpectedAfterHandle
     */
    public function testCreateOppositeStops(
        Position $position,
        Ticker $ticker,
        array $buyOrdersFixtures,
        array $expectedMarketBuyCalls,
        array $stopsExpectedAfterHandle,
    ): void {
        $this->expectsToMakeApiCalls(...$expectedMarketBuyCalls);
        $this->haveSpotBalance($position->symbol, 0);

        $this->haveTicker($ticker);
        $this->positionServiceStub->havePosition($position);
        $this->applyDbFixtures(...$buyOrdersFixtures);

        ($this->handler)(new PushBuyOrders($position->symbol, $position->side));

        self::seeStopsInDb(...$stopsExpectedAfterHandle);
    }

    public function createOppositeStopsTestCases(): iterable
    {
        /** @var BuyOrder[] $buyOrders */
        $buyOrders = [
            BuyOrderBuilder::short(10, 29060, 0.001)->build(),
            BuyOrderBuilder::short(20, 29155, 0.002)->build(), // not handled
            BuyOrderBuilder::short(30, 29055, 0.003)->build(),
            BuyOrderBuilder::short(40, 29060, 0.005)->build(),
            BuyOrderBuilder::short(50, 29060, 0.005)->build()->setIsWithoutOppositeOrder(), // not handled
        ];
        $buyOrdersExpectedToPush = [$buyOrders[0], $buyOrders[2], $buyOrders[3], $buyOrders[4]];

        $symbol = self::SYMBOL;
        $position = PositionFactory::short($symbol, 29000);
        yield [
            '$position' => $position,
            '$ticker' => TickerFactory::create($symbol, 29050),
            '$buyOrdersFixtures' => array_map(static fn(BuyOrder $buyOrder) => new BuyOrderFixture($buyOrder), $buyOrders),
            'expectedMarketBuyCalls' => self::successMarketBuyApiCallExpectations($symbol, $buyOrdersExpectedToPush, $exchangeOrderIds),
            'stopsExpectedAfterHandle' => [
                StopBuilder::short(1, $buyOrders[0]->getPrice() + StopCreate::getDefaultStrategyStopOrderDistance(0.001), 0.001)->withTD(self::DEFAULT_STOP_TD)->build(),
                StopBuilder::short(2, $buyOrders[2]->getPrice() + StopCreate::getDefaultStrategyStopOrderDistance(0.003), 0.003)->withTD(self::DEFAULT_STOP_TD)->build(),
                StopBuilder::short(3, $buyOrders[3]->getPrice() + StopCreate::getDefaultStrategyStopOrderDistance(0.005), 0.005)->withTD(self::DEFAULT_STOP_TD)->build(),
            ],
        ];
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('cases: short_stop, ...');
    }
}
