<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Buy\Application\Command\CreateStopsAfterBuy;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Helper\Buy\BuyOrderTestHelper;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\OrderCasesTester;
use App\Tests\Mixin\Settings\SettingsAwareTest;
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
    use BuyOrdersTester;
    use MessageConsumerTrait;
    use ByBitV5ApiRequestsMocker;
    use SettingsAwareTest;

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
        array $expectedMessengerMessages,
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

        self::assertMessagesWasDispatched(self::ASYNC_CRITICAL_QUEUE, $expectedMessengerMessages);
    }

    public function pushBuyOrdersTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        $ticker = TickerFactory::create($symbol, 29050);
        $buyOrders = [
            # [2] must be pushed | with stop
            10 => BuyOrderTestHelper::setActive(BuyOrderBuilder::short(10, 29060, 0.01)->build()),

            # -- must not be pushed (idle) --
            20 => BuyOrderBuilder::short(20, 29155, 0.002)->build()->setIdle(),

            # [1] must be pushed | with stop
            30 => BuyOrderTestHelper::setActive(BuyOrderBuilder::short(30, 29060, 0.005)->build()),

            # -- must not be pushed (idle) --
            40 => BuyOrderBuilder::short(40, 29105, 0.003)->build()->setIdle(),

            # [0] must be pushed | with stop
            50 => BuyOrderTestHelper::setActive(BuyOrderBuilder::short(50, 29060, 0.001)->build()),

            # [4] must be pushed | withOUT stop
            60 => BuyOrderTestHelper::setActive(BuyOrderBuilder::short(60, 29060, 0.035)->build())->setIsWithoutOppositeOrder(),

            # must not be pushed (already executed)
            70 => BuyOrderTestHelper::setActive(BuyOrderBuilder::short(70, 29055, 0.031)->build())->setExchangeOrderId($existedExchangeOrderId = uuid_create()),

            # [3] must be pushed | with stop
            80 => BuyOrderTestHelper::setActive(BuyOrderBuilder::short(80, 29055, 0.03)->build()),

            # other must not be pushed
            90 => BuyOrderTestHelper::setActive(BuyOrderBuilder::short(90, 29049, 0.032)->build()),
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
                BuyOrderTestHelper::setActive(BuyOrderBuilder::short(50, 29060, 0.001)->build())->setExchangeOrderId($exchangeOrderIds[0]),
                BuyOrderTestHelper::setActive(BuyOrderBuilder::short(30, 29060, 0.005)->build())->setExchangeOrderId($exchangeOrderIds[1]),
                BuyOrderTestHelper::setActive(BuyOrderBuilder::short(10, 29060, 0.01)->build())->setExchangeOrderId($exchangeOrderIds[2]),
                BuyOrderTestHelper::setActive(BuyOrderBuilder::short(80, 29055, 0.03)->build())->setExchangeOrderId($exchangeOrderIds[3]),
                BuyOrderTestHelper::setActive(BuyOrderBuilder::short(60, 29060, 0.035)->build())->setExchangeOrderId($exchangeOrderIds[4])->setIsWithoutOppositeOrder(),

                ### unchanged ###
                BuyOrderBuilder::short(20, 29155, 0.002)->build()->setIdle(),
                BuyOrderBuilder::short(40, 29105, 0.003)->build()->setIdle(),
                BuyOrderTestHelper::setActive(BuyOrderBuilder::short(70, 29055, 0.031)->build())->setExchangeOrderId($existedExchangeOrderId),
                BuyOrderTestHelper::setActive(BuyOrderBuilder::short(90, 29049, 0.032)->build()),
            ],
            'expectedMessengerMessages' => [
                new CreateStopsAfterBuy(50),
                new CreateStopsAfterBuy(30),
                new CreateStopsAfterBuy(10),
                new CreateStopsAfterBuy(80),
            ]
        ];
    }
}
