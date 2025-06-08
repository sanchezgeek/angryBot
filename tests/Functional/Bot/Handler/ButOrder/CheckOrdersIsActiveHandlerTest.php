<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\ButOrder;

use App\Bot\Application\Messenger\Job\BuyOrder\CheckOrdersNowIsActive;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Helper\Buy\BuyOrderTestHelper;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\OrderCasesTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CheckOrdersIsActiveHandlerTest extends KernelTestCase
{
    use OrderCasesTester;
    use BuyOrdersTester;
    use ByBitV5ApiRequestsMocker;
    use MessageConsumerTrait;

    /**
     * @dataProvider idleOrdersBecameActiveTestDataProvider
     *
     * @param BuyOrder[] $buyOrdersExpectedAfterHandle
     */
    public function testIdleOrdersBecameActive(
        array $activeConditionalStops,
        array $buyOrdersFixtures,
        array $tickers,
        array $buyOrdersExpectedAfterHandle,
    ): void {
        $this->haveActiveConditionalStopsOnMultipleSymbols(...$activeConditionalStops);
        foreach ($tickers as $symbolRaw => $price) {
            $this->haveTicker(TickerFactory::withEqualPrices(SymbolEnum::from($symbolRaw), $price));
        }

        $this->applyDbFixtures(...$buyOrdersFixtures);

        $this->runMessageConsume(new CheckOrdersNowIsActive());

        foreach ($buyOrdersExpectedAfterHandle as $expectedBuyOrder) {
            if ($expectedBuyOrder->isOrderActive()) {
                BuyOrderTestHelper::setActive($expectedBuyOrder); // for get same `activeStateSetAtTimestamp` (also can mock with ClockMock)
            }
        }

        self::seeBuyOrdersInDb(...$buyOrdersExpectedAfterHandle);
    }

    public function idleOrdersBecameActiveTestDataProvider(): iterable
    {
        $tickers = [
            SymbolEnum::BTCUSDT->value => 30000,
            SymbolEnum::ETHUSDT->value => 2140,
        ];

        $buyOrders = [
            BuyOrderBuilder::short(10, 30001, 0.01)->build(),
            BuyOrderBuilder::short(20, 29999, 0.011)->build(),

            BuyOrderBuilder::long(30, 30001, 0.01)->build(),
            BuyOrderBuilder::long(40, 29999, 0.011)->build(),

            BuyOrderBuilder::short(50, 2141, 0.01, SymbolEnum::ETHUSDT)->build(),
            BuyOrderBuilder::short(60, 2139, 0.11, SymbolEnum::ETHUSDT)->build(),
            BuyOrderBuilder::short(61, 2139, 0.12, SymbolEnum::ETHUSDT)->build()->setOnlyAfterExchangeOrderExecutedContext(
                $oppositeETHStopExchangeId = '100500'
            ),

            BuyOrderBuilder::long(70, 2141, 0.01, SymbolEnum::ETHUSDT)->build(),
            BuyOrderBuilder::long(80, 2139, 0.11, SymbolEnum::ETHUSDT)->build(),
        ];

        yield [
            '$activeConditionalStops' => [
                new ActiveStopOrder(SymbolEnum::ETHUSDT, Side::Buy, $oppositeETHStopExchangeId, 0.12, 2139, TriggerBy::IndexPrice->value)
            ],
            '$buyOrdersFixtures' => array_map(static fn(BuyOrder $buyOrder) => new BuyOrderFixture($buyOrder), $buyOrders),
            '$tickers' => $tickers,
            'buyOrdersExpectedAfterHandle' => [
                BuyOrderBuilder::short(10, 30001, 0.01)->build()->setIdle(),
                BuyOrderTestHelper::setActive(BuyOrderBuilder::short(20, 29999, 0.011)->build()),

                BuyOrderTestHelper::setActive(BuyOrderBuilder::long(30, 30001, 0.01)->build()),
                BuyOrderBuilder::long(40, 29999, 0.011)->build()->setIdle(),

                BuyOrderBuilder::short(50, 2141, 0.01, SymbolEnum::ETHUSDT)->build()->setIdle(),
                BuyOrderTestHelper::setActive(BuyOrderBuilder::short(60, 2139, 0.11, SymbolEnum::ETHUSDT)->build()),
                BuyOrderBuilder::short(61, 2139, 0.12, SymbolEnum::ETHUSDT)->build()->setOnlyAfterExchangeOrderExecutedContext($oppositeETHStopExchangeId)->setIdle(),

                BuyOrderTestHelper::setActive(BuyOrderBuilder::long(70, 2141, 0.01, SymbolEnum::ETHUSDT)->build()),
                BuyOrderBuilder::long(80, 2139, 0.11, SymbolEnum::ETHUSDT)->build()->setIdle(),
            ],
        ];
    }
}
