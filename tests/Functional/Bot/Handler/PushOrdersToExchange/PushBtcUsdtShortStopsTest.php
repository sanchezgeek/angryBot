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
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\StopTest;

use function uuid_create;

/**
 * @covers PushRelevantStopsHandler
 */
final class PushBtcUsdtShortStopsTest extends PushOrderHandlerTestAbstract
{
    use StopTest;

    private const SYMBOL = Symbol::BTCUSDT;

    private PushRelevantStopsHandler $handler;

    public static function setUpBeforeClass(): void
    {
        self::truncate(Stop::class);
        self::beginTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        /** @var BuyOrderService $buyOrderService */
        $buyOrderService = self::getContainer()->get(BuyOrderService::class);

        $this->handler = new PushRelevantStopsHandler($this->hedgeService, $this->stopRepository, $buyOrderService, $this->stopService, $this->messageBus, $this->eventDispatcher, $this->exchangeServiceMock, $this->positionServiceStub, $this->loggerMock, $this->clockMock, 0);

        self::ensureTableIsEmpty(Stop::class);
    }

    /**
     * @dataProvider successPushCasesProvider
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

        self::assertSame($expectedStopAddMethodCalls, $this->positionServiceStub->getStopAddMethodCalls());
        self::seeStopsInDb(...$stopsExpectedAfterHandle);
    }

    public function successPushCasesProvider(): iterable
    {
        yield 'by trigger delta' => [
            '$position' => $position = PositionFactory::short(self::SYMBOL, 29000),
            '$ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29050),
            '$stopFixtures' => [
                new StopFixture(StopBuilder::short(1, 29060, 0.1)->withTD(10)), // must be pushed
                new StopFixture(StopBuilder::short(2, 29155, 0.2)->withTD(100)),
                new StopFixture(StopBuilder::short(3, 29055, 0.3)->withTD(5)),  // must be pushed
            ],
            '$expectedStopAddMethodCalls' => [
                [$position, $ticker, 29055.0, 0.3],
                [$position, $ticker, 29060.0, 0.1],
            ],
            '$stopsExpectedAfterHandle' => [
                ### pushed (in right order) ###
                StopBuilder::short(3, 29055, 0.3)->withTD(5)->withContext(['exchange.orderId' => $mockedExchangeOrderIds[] = uuid_create()])->build(),
                StopBuilder::short(1, 29060, 0.1)->withTD(10)->withContext(['exchange.orderId' => $mockedExchangeOrderIds[] = uuid_create()])->build(),

                ### unchanged ###
                StopBuilder::short(2, 29155, 0.2)->withTD(100)->build(),
            ],
            $mockedExchangeOrderIds
        ];
    }
}
