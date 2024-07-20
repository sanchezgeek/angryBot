<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder;

use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Strategy\StopCreate;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\OrderCasesTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function uuid_create;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler
 */
final class PushBuyOrdersCommonCasesTest extends KernelTestCase
{
    use OrderCasesTester;
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

        $this->handler = self::getContainer()->get(PushBuyOrdersHandler::class);

        # @todo | for now to prevent MarketBuyHandler "buyIsSafe" checks
        $marketBuyHandler = self::getContainer()->get(MarketBuyHandler::class); /** @var MarketBuyHandler $marketBuyHandler */
        $marketBuyHandler->setSafeLiquidationPriceDistance(100);
    }

    /**
     * @dataProvider pushBuyOrdersTestDataProvider
     *
     * @param BuyOrder[] $buyOrdersExpectedAfterHandle
     * @param ByBitApiCallExpectation[] $expectedMarketBuyApiCalls
     */
    public function testPushRelevantBuyOrders(
        Ticker $ticker,
        Position $position,
        array $buyOrdersFixtures,
        array $expectedMarketBuyApiCalls,
        array $buyOrdersExpectedAfterHandle,
        array $stopsExpectedAfterHandle,
    ): void {
        $symbol = $position->symbol;

        $this->expectsToMakeApiCalls(...$expectedMarketBuyApiCalls);
        $this->haveAvailableSpotBalance($symbol, 0);

        $this->haveTicker($ticker);
        $this->havePosition($symbol, $position);
        $this->haveAvailableSpotBalance($symbol, 0);
        $this->haveContractWalletBalanceAllUsedToOpenPosition($position);
        $this->applyDbFixtures(...$buyOrdersFixtures);

        ($this->handler)(new PushBuyOrders($position->symbol, $position->side));

        self::seeBuyOrdersInDb(...$buyOrdersExpectedAfterHandle);
        self::seeStopsInDb(...$stopsExpectedAfterHandle);
    }

    public function pushBuyOrdersTestDataProvider(): iterable
    {
        $ticker = TickerFactory::create($symbol = self::SYMBOL, 29050);
        $buyOrders = [
            # [2] must be pushed | with stop
            10 => BuyOrderBuilder::short(10, 29060, 0.01)->build(),

            # -- must not be pushed (too far) --
            20 => BuyOrderBuilder::short(20, 29155, 0.002)->build(),

            # [1] must be pushed and removed | with stop
            30 => BuyOrderBuilder::short(30, 29060, 0.005)->build(),

            # -- must not be pushed (too far) --
            40 => BuyOrderBuilder::short(40, 29105, 0.003)->build(),

            # [0] must be pushed and removed | with stop
            50 => BuyOrderBuilder::short(50, 29060, 0.001)->build(),

            # [4] must be pushed | withOUT stop
            60 => BuyOrderBuilder::short(60, 29060, 0.035)->build()->setIsWithoutOppositeOrder(),

            # must not be pushed (not active)
            70 => BuyOrderBuilder::short(70, 29055, 0.031)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create()),

            # [3] must be pushed | with stop
            80 => BuyOrderBuilder::short(80, 29055, 0.03)->build(),
        ];
        $buyOrdersExpectedToPush = [$buyOrders[50], $buyOrders[30], $buyOrders[10], $buyOrders[80], $buyOrders[60]];
        $exchangeOrderIds = [];

        /** @var BuyOrder $buyOrder */
        yield 'position value less than MVA + position in loss => can buy' => [
            '$ticker' => $ticker, '$position' => PositionFactory::short($symbol, 29000, 0.01),
            '$buyOrdersFixtures' => array_map(static fn(BuyOrder $buyOrder) => new BuyOrderFixture($buyOrder), $buyOrders),
            'expectedMarketBuyCalls' => self::successMarketBuyApiCallExpectations($symbol, $buyOrdersExpectedToPush, $exchangeOrderIds),
            'buyOrdersExpectedAfterHandle' => [
                ### pushed (in right order) ###
                BuyOrderBuilder::short(10, 29060, 0.01)->build()->setExchangeOrderId($exchangeOrderIds[2]),
                BuyOrderBuilder::short(80, 29055, 0.03)->build()->setExchangeOrderId($exchangeOrderIds[3]),
                BuyOrderBuilder::short(60, 29060, 0.035)->build()->setExchangeOrderId($exchangeOrderIds[4])->setIsWithoutOppositeOrder(),

                ### unchanged ###
                BuyOrderBuilder::short(20, 29155, 0.002)->build(),
                BuyOrderBuilder::short(40, 29105, 0.003)->build(),
                BuyOrderBuilder::short(70, 29055, 0.031)->build()->setExchangeOrderId($existedExchangeOrderId),
            ],
            'stopsExpectedAfterHandle' => [
                StopBuilder::short(1, self::expectedStopPrice($buyOrders[50]), $buyOrders[50]->getVolume())->withTD(self::DEFAULT_STOP_TD)->build(),
                StopBuilder::short(2, self::expectedStopPrice($buyOrders[30]), $buyOrders[30]->getVolume())->withTD(self::DEFAULT_STOP_TD)->build(),
                StopBuilder::short(3, self::expectedStopPrice($buyOrders[10]), $buyOrders[10]->getVolume())->withTD(self::DEFAULT_STOP_TD)->build(),
                StopBuilder::short(4, self::expectedStopPrice($buyOrders[80]), $buyOrders[80]->getVolume())->withTD(self::DEFAULT_STOP_TD)->build(),
            ],
        ];
    }

    private static function expectedStopPrice(BuyOrder $buyOrder): float
    {
        $stopDistance = StopCreate::getDefaultStrategyStopOrderDistance($buyOrder->getVolume());

        return $buyOrder->getPrice() + $stopDistance;
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('cases: short_stop, ...');
    }
}
