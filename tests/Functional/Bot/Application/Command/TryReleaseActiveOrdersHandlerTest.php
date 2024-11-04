<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Application\Command;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Application\Command\Exchange\TryReleaseActiveOrdersHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Tests\Stub\Bot\PositionServiceStub;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function uuid_create;

final class TryReleaseActiveOrdersHandlerTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;
    use BuyOrdersTester;

    private const DEFAULT_RELEASE_OVER_DISTANCE = TryReleaseActiveOrdersHandler::DEFAULT_RELEASE_OVER_DISTANCE;

    protected EventDispatcherInterface $eventDispatcher;
    protected StopService $stopService;
    private ExchangeServiceInterface $exchangeServiceMock;
    private PositionServiceStub $positionServiceStub;

    private TryReleaseActiveOrdersHandler $handler;

    public static function setUpBeforeClass(): void
    {
        self::truncateStops();
        self::truncateBuyOrders();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->positionServiceStub = new PositionServiceStub();
        $this->stopService = self::getContainer()->get(StopService::class);

        $this->handler = new TryReleaseActiveOrdersHandler(
            $this->exchangeServiceMock,
            $this->positionServiceStub,
            $this->stopService,
            self::getStopRepository(),
            self::getBuyOrderRepository(),
            $this->eventDispatcher,
            self::getContainer()->get(EntityManagerInterface::class),
        );

        self::ensureTableIsEmpty(Stop::class);
    }

    /**
     * @dataProvider releaseActiveOrdersTestDataProvider
     */
    public function testReleaseLongBtcUsdtActiveConditionalStops(
        Ticker $ticker,
        array $stopsFixtures,
        array $buyOrdersFixtures,
        array $activeConditionalOrders,
        array $expectedClosedActiveConditionalOrders,
        array $stopsExpectedAfterHandle,
        array $buyOrdersExpectedAfterHandle
    ): void {
        $this->applyDbFixtures(...$stopsFixtures);
        $this->applyDbFixtures(...$buyOrdersFixtures);
        $this->haveTicker($ticker);
        $this->exchangeServiceMock->method('activeConditionalOrders')->willReturn($activeConditionalOrders);

        if (!$expectedClosedActiveConditionalOrders) {
            $this->exchangeServiceMock->expects(self::never())->method('closeActiveConditionalOrder');
        } else {
            $this->exchangeServiceMock->expects(self::once())->method('closeActiveConditionalOrder')->with($expectedClosedActiveConditionalOrders[0]);
        }

        $command = new TryReleaseActiveOrders(symbol: Symbol::BTCUSDT, force: true);

        ($this->handler)($command);

        self::seeStopsInDb(...$stopsExpectedAfterHandle);
        self::seeBuyOrdersInDb(...$buyOrdersExpectedAfterHandle);
    }

    private function releaseActiveOrdersTestDataProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $exchangeOrderId = uuid_create();
        yield 'no need to release' => [
            '$ticker' => TickerFactory::create($symbol, 28510, 28510, 28510),
            '$stopsFixtures' => [
                new StopFixture(StopBuilder::long(1, 28500, 0.001)->withTD(10)->build()->setExchangeOrderId($exchangeOrderId))
            ],
            '$buyOrdersFixtures' => [
                new BuyOrderFixture(BuyOrderBuilder::long(1, 28600, 0.001)->build()->setOnlyAfterExchangeOrderExecutedContext($exchangeOrderId))
            ],
            '$activeConditionalOrders' => [
                new ActiveStopOrder($symbol, Side::Buy, $exchangeOrderId, 0.001, 28500, 'хз')
            ],
            '$expectedClosedActiveConditionalOrders' => [],
            'stopsExpectedAfterHandle' => [
                StopBuilder::long(1, 28500, 0.001)->withTD(10)->build()->setExchangeOrderId($exchangeOrderId)
            ],
            'buyOrdersExpectedAfterHandle' => [
                BuyOrderBuilder::long(1, 28600, 0.001)->build()->setOnlyAfterExchangeOrderExecutedContext($exchangeOrderId)
            ]
        ];

        $releaseOnDistance = self::DEFAULT_RELEASE_OVER_DISTANCE + 1;
        yield 'need to release' => [
            '$ticker' => $ticker = TickerFactory::create($symbol, 28571, 28571, 28571),
            '$stopsFixtures' => [
                new StopFixture(StopBuilder::long(1, 28500, 0.001)->withTD(10)->build()->setExchangeOrderId($exchangeOrderId))
            ],
            '$buyOrdersFixtures' => [
                new BuyOrderFixture(BuyOrderBuilder::long(1, 28600, 0.001)->build()->setOnlyAfterExchangeOrderExecutedContext($exchangeOrderId)),
                new BuyOrderFixture(BuyOrderBuilder::long(2, 28700, 0.002)->build()->setOnlyAfterExchangeOrderExecutedContext($exchangeOrderId))
            ],
            '$activeConditionalOrders' => [
                new ActiveStopOrder($symbol, Side::Buy, $exchangeOrderId, 0.001, $ticker->indexPrice->value() - $releaseOnDistance, 'хз')
            ],
            '$expectedClosedActiveConditionalOrders' => [
                new ActiveStopOrder($symbol, Side::Buy, $exchangeOrderId, 0.001, $ticker->indexPrice->value() - $releaseOnDistance, 'хз')
            ],
            'stopsExpectedAfterHandle' => [
                StopBuilder::long(1, 28500, 0.001)->withTD(13)->build()
            ],
            'buyOrdersExpectedAfterHandle' => [

            ]
        ];
    }

    protected function haveTicker(Ticker $ticker): void
    {
        $this->exchangeServiceMock->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }
}
