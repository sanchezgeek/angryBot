<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantBuyOrdersHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\StopsTester;

use function uuid_create;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantBuyOrdersHandler
 */
final class PushBtcUsdtShortBuyOrdersTest extends PushOrderHandlerTestAbstract
{
    use StopsTester;
    use BuyOrdersTester;

    private const SYMBOL = Symbol::BTCUSDT;
    private const DEFAULT_STOP_TD = 17;

    private PushRelevantBuyOrdersHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        self::truncateStops();
        self::truncateBuyOrders();

        $this->handler = new PushRelevantBuyOrdersHandler(
            self::getBuyOrderRepository(),
            $this->stopRepository,
            $this->stopService,
            $this->messageBus,
            $this->eventDispatcher,
            $this->exchangeServiceMock,
            $this->positionServiceStub,
            $this->loggerMock,
            $this->clockMock
        );
    }

    /**
     * @dataProvider pushBuyOrdersTestDataProvider
     *
     * @param BuyOrder[] $buyOrdersExpectedAfterHandle
     */
    public function testPushRelevantStopOrders(
        Position $position,
        Ticker $ticker,
        array $buyOrdersFixtures,
        array $expectedAddBuyOrderCallsStack,
        array $buyOrdersExpectedAfterHandle,
        array $mockedExchangeOrderIds
    ): void {
        $this->haveTicker($ticker);
        $this->positionServiceStub->havePosition($position);
        $this->positionServiceStub->setMockedExchangeOrdersIds($mockedExchangeOrderIds);
        $this->applyDbFixtures(...$buyOrdersFixtures);

        ($this->handler)(new PushRelevantBuyOrders($position->symbol, $position->side));

        self::assertSame($expectedAddBuyOrderCallsStack, $this->positionServiceStub->getAddBuyOrderCallsStack());
        self::seeBuyOrdersInDb(...$buyOrdersExpectedAfterHandle);
    }

    public function pushBuyOrdersTestDataProvider(): iterable
    {
        yield [
            '$position' => $position = PositionFactory::short(self::SYMBOL, 29000),
            '$ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29050),
            '$buyOrdersFixtures' => [
                new BuyOrderFixture(BuyOrderBuilder::short(10, 29060, 0.01)->build()), // must be pushed
                new BuyOrderFixture(BuyOrderBuilder::short(20, 29155, 0.002)->build()),
                new BuyOrderFixture(BuyOrderBuilder::short(30, 29055, 0.03)->build()), // must be pushed
                new BuyOrderFixture(BuyOrderBuilder::short(40, 29055, 0.04)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create())), // must not be pushed (not active)
            ],
            'expectedAddBuyOrderCalls' => [
                [$position, $ticker, 29060.0, 0.01],
                [$position, $ticker, 29055.0, 0.03],
            ],
            'buyOrdersExpectedAfterHandle' => [
                ### pushed (in right order) ###
                BuyOrderBuilder::short(10, 29060, 0.01)->build()->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create()),
                BuyOrderBuilder::short(30, 29055, 0.03)->build()->setExchangeOrderId($mockedExchangeOrderIds[] = uuid_create()),

                ### unchanged ###
                BuyOrderBuilder::short(20, 29155, 0.002)->build(),
                BuyOrderBuilder::short(40, 29055, 0.04)->build()->setExchangeOrderId($existedExchangeOrderId),
            ],
            '$mockedExchangeOrderIds' => $mockedExchangeOrderIds
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
        array $stopsExpectedAfterHandle,
    ): void {
        $this->haveTicker($ticker);
        $this->positionServiceStub->havePosition($position);
        $this->applyDbFixtures(...$buyOrdersFixtures);

        ($this->handler)(new PushRelevantBuyOrders($position->symbol, $position->side));

        self::seeStopsInDb(...$stopsExpectedAfterHandle);
    }

    public function createOppositeStopsTestCases(): iterable
    {
        yield [
            '$position' => PositionFactory::short(self::SYMBOL, 29000),
            '$ticker' => TickerFactory::create(self::SYMBOL, 29050),
            '$buyOrdersFixtures' => [
                new BuyOrderFixture(BuyOrderBuilder::short(10, 29060, 0.001)->build()),
                new BuyOrderFixture(BuyOrderBuilder::short(20, 29155, 0.002)->build()),
                new BuyOrderFixture(BuyOrderBuilder::short(30, 29055, 0.003)->build()),
                new BuyOrderFixture(BuyOrderBuilder::short(40, 29060, 0.005)->build()),
                new BuyOrderFixture(BuyOrderBuilder::short(50, 29060, 0.005)->build()->setIsWithoutOppositeOrder()),
            ],
            'stopsExpectedAfterHandle' => [
                /**
                 * @see \App\Bot\Domain\Strategy\StopCreate::getDefaultStrategyStopOrderDistance
                 */
                StopBuilder::short(1, 29133, 0.001)->withTD(self::DEFAULT_STOP_TD)->build(), // + 73
                StopBuilder::short(2, 29128, 0.003)->withTD(self::DEFAULT_STOP_TD)->build(), // + 73
                StopBuilder::short(3, 29155, 0.005)->withTD(self::DEFAULT_STOP_TD)->build(), // + 95
            ],
        ];
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('cases: short_stop, ...');
    }
}
