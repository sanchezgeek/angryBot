<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Strategy\StopCreate;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\OrderCasesTester;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function uuid_create;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler
 *
 * @todo | string "@ MarketBuyHandler: got "Call to a member function isMainPosition on null" exception while make `buyIsSafe` check"
 * * x3
 *
 * @group buy-orders
 */
final class PushBuyOrdersCommonCasesTest extends KernelTestCase
{
    use OrderCasesTester;
    use StopsTester;
    use BuyOrdersTester;
    use MessageConsumerTrait;
    use ByBitV5ApiRequestsMocker;
    use SettingsAwareTest;

    private const int DEFAULT_STOP_TD = 37;

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

        $this->setMinimalSafePriceDistance($position->symbol, $position->side);

        $this->expectsToMakeApiCalls(...$expectedMarketBuyApiCalls);

        $this->haveTicker($ticker);
        $this->havePosition($symbol, $position);
        $this->haveAvailableSpotBalance($symbol, 0);
        $this->haveContractWalletBalanceAllUsedToOpenPosition($position);
        $this->applyDbFixtures(...$buyOrdersFixtures);

        $this->runMessageConsume(new PushBuyOrders($position->symbol, $position->side));

        self::seeBuyOrdersInDb(...$buyOrdersExpectedAfterHandle);
        self::seeStopsInDb(...$stopsExpectedAfterHandle);
    }

    public function pushBuyOrdersTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        $ticker = TickerFactory::create($symbol, 29050);
        $buyOrders = [
            # [2] must be pushed | with stop
            10 => BuyOrderBuilder::short(10, 29060, 0.01)->build()->setActive(),

            # -- must not be pushed (idle) --
            20 => BuyOrderBuilder::short(20, 29155, 0.002)->build()->setIdle(),

            # [1] must be pushed | with stop
            30 => BuyOrderBuilder::short(30, 29060, 0.005)->build()->setActive(),

            # -- must not be pushed (idle) --
            40 => BuyOrderBuilder::short(40, 29105, 0.003)->build()->setIdle(),

            # [0] must be pushed | with stop
            50 => BuyOrderBuilder::short(50, 29060, 0.001)->build()->setActive(),

            # [4] must be pushed | withOUT stop
            60 => BuyOrderBuilder::short(60, 29060, 0.035)->build()->setActive()->setIsWithoutOppositeOrder(),

            # must not be pushed (already executed)
            70 => BuyOrderBuilder::short(70, 29055, 0.031)->build()->setActive()->setExchangeOrderId($existedExchangeOrderId = uuid_create()),

            # [3] must be pushed | with stop
            80 => BuyOrderBuilder::short(80, 29055, 0.03)->build()->setActive(),

            # other must not be pushed
            90 => BuyOrderBuilder::short(90, 29049, 0.032)->build()->setActive(),
        ];
        $buyOrdersExpectedToPush = [$buyOrders[50], $buyOrders[30], $buyOrders[10], $buyOrders[80], $buyOrders[60]];
        $exchangeOrderIds = [];

        /** @var BuyOrder $buyOrder */
        yield 'position value less than MVA + position in loss => can buy' => [
            '$ticker' => $ticker, '$position' => PositionFactory::short($symbol, 29000, 0.01, 100, 35000),
            '$buyOrdersFixtures' => array_map(static fn(BuyOrder $buyOrder) => new BuyOrderFixture($buyOrder), $buyOrders),
            'expectedMarketBuyCalls' => self::successMarketBuyApiCallExpectations($symbol, $buyOrdersExpectedToPush, $exchangeOrderIds),
            'buyOrdersExpectedAfterHandle' => [
                ### pushed (in right order) ###
                BuyOrderBuilder::short(50, 29060, 0.001)->build()->setActive()->setExchangeOrderId($exchangeOrderIds[0]),
                BuyOrderBuilder::short(30, 29060, 0.005)->build()->setActive()->setExchangeOrderId($exchangeOrderIds[1]),
                BuyOrderBuilder::short(10, 29060, 0.01)->build()->setActive()->setExchangeOrderId($exchangeOrderIds[2]),
                BuyOrderBuilder::short(80, 29055, 0.03)->build()->setActive()->setExchangeOrderId($exchangeOrderIds[3]),
                BuyOrderBuilder::short(60, 29060, 0.035)->build()->setActive()->setExchangeOrderId($exchangeOrderIds[4])->setIsWithoutOppositeOrder(),

                ### unchanged ###
                BuyOrderBuilder::short(20, 29155, 0.002)->build()->setIdle(),
                BuyOrderBuilder::short(40, 29105, 0.003)->build()->setIdle(),
                BuyOrderBuilder::short(70, 29055, 0.031)->build()->setActive()->setExchangeOrderId($existedExchangeOrderId),
                BuyOrderBuilder::short(90, 29049, 0.032)->build()->setActive(),
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

        return $buyOrder->getPrice() + ($buyOrder->getPositionSide()->isShort() ? $stopDistance : -$stopDistance);
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('cases: short_stop, ...');
    }
}
