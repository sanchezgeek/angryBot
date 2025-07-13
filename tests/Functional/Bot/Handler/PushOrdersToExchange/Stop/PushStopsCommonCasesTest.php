<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop;

use App\Bot\Application\Helper\StopHelper;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Stop\Helper\PnlHelper;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Liquidation\Application\Settings\LiquidationHandlerSettings;
use App\Settings\Application\Service\SettingAccessor;
use App\Stop\Application\Contract\Command\CreateBuyOrderAfterStop;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\OrderCasesTester;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\Trading\TradingParametersMocker;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use App\Trading\Application\EventListener\CreateOppositeBuyOrdersListener;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler
 *
 * @group stop
 */
final class PushStopsCommonCasesTest extends KernelTestCase
{
    use OrderCasesTester;
    use StopsTester;
    use BuyOrdersTester;
    use MessageConsumerTrait;
    use ByBitV5ApiRequestsMocker;
    use SettingsAwareTest;
    use TradingParametersMocker;

    /**
     * @todo | DRY
     * @see CreateOppositeBuyOrdersListener::MAIN_SYMBOLS
     */
    private const array MAIN_SYMBOLS = [
        SymbolEnum::BTCUSDT->value,
        SymbolEnum::ETHUSDT->value,
    ];

    private const WITHOUT_OPPOSITE_CONTEXT = Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT;
    private const OPPOSITE_BUY_DISTANCE = 38;
    private const ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP = 15;

    private const ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP = 7;
    private const LIQUIDATION_CRITICAL_DISTANCE_PNL_PERCENT = 10;
    private const LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT = 18;

    protected function setUp(): void
    {
        parent::setUp();

        self::createTradingParametersStub();
    }

    /**
     * @dataProvider pushStopsTestCases
     *
     * @param Stop[] $stopsExpectedAfterHandle
     */
    public function testPushRelevantStopOrders(
        Position $position,
        Ticker $ticker,
        array $stops,
        array $expectedMarketBuyApiCalls,
        array $stopsExpectedAfterHandle,
        array $expectedMessengerMessages,
    ): void {
        self::mockTradingParametersForLiquidationTests($position->symbol, '0.09%');

        $this->overrideSetting(SettingAccessor::exact(LiquidationHandlerSettings::WarningDistancePnl, $position->symbol, $position->side), self::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT);
        $this->overrideSetting(SettingAccessor::exact(LiquidationHandlerSettings::CriticalDistancePnl, $position->symbol, $position->side), self::LIQUIDATION_CRITICAL_DISTANCE_PNL_PERCENT);

        $this->haveTicker($ticker);
        $this->havePosition($position->symbol, $position);
        $this->expectsToMakeApiCalls(...$expectedMarketBuyApiCalls);

        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $stops));

        $this->runMessageConsume(new PushStops($position->symbol, $position->side));

        self::seeStopsInDb(...$stopsExpectedAfterHandle);

        self::assertMessagesWasDispatched(self::ASYNC_HIGH_QUEUE, $expectedMessengerMessages);
    }

    public static function getDistanceAfterWhichMarkPriceUsedForTrigger(Ticker $ticker): float
    {
        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT, $ticker->markPrice) * 2;
    }

    public function pushStopsTestCases(): iterable
    {
        # BTCUSDT
        $symbol = SymbolEnum::BTCUSDT;
        $addTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create($symbol, 29050, 29030, 29030);
        $liquidationWarningDistance = self::getDistanceAfterWhichMarkPriceUsedForTrigger($ticker);
        $position = PositionFactory::short($symbol, 29000, 1, 100, $ticker->markPrice->value() + $liquidationWarningDistance + 1);
        $triggerBy = TriggerBy::IndexPrice;
        $addPriceDelta = StopHelper::priceModifierIfCurrentPriceOverStop($ticker->indexPrice);

        /** @var Stop[] $stops */
        $stops = [
            1 => StopBuilder::short(1, 29055, 0.4)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create()), # must not be pushed (not active)
            5 => StopBuilder::short(5, 29030, 0.011)->withTD(10)->build()->setIsWithoutOppositeOrder(), # before ticker => push | without oppositeBuy
            10 => StopBuilder::short(10, 29060, 0.1)->withTD(10)->build(), # by tD | with oppositeBuy
            15 => StopBuilder::short(15, 29061, 0.1)->withTD(10)->build(),
            20 => StopBuilder::short(20, 29155, 0.2)->withTD(100)->build(),
            30 => StopBuilder::short(30, 29055, 0.3)->withTD(5)->build(), # by tD | with oppositeBuy
            40 => StopBuilder::short(40, 29029, 0.33)->withTD(5)->build()->setIsTakeProfitOrder(),
        ];
        $stopsExpectedToPush = [(clone $stops[5])->setPrice($ticker->indexPrice->value() + $addPriceDelta), $stops[30], $stops[10]];

        yield 'BTCUSDT SHORT (liquidation not in critical range => by indexPrice)' => [
            '$position' => $position,
            '$ticker' => $ticker,
            'stops' => $stops,
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($ticker->symbol, $stopsExpectedToPush, $triggerBy, $exchangeOrderIds),
            'stopsExpectedAfterHandle' => [
                ### pushed (in right order) ###

                # initial price is before ticker => set new price + push
                StopBuilder::short(5, $ticker->indexPrice->value() + $addPriceDelta, 0.011)->withTD(10 + $addTriggerDelta)->build()
                    ->setOriginalPrice(29030)
                    ->setIsWithoutOppositeOrder()
                    ->setExchangeOrderId($exchangeOrderIds[0]),

                # just push
                StopBuilder::short(30, 29055, 0.3)->withTD(5)->build()->setExchangeOrderId($exchangeOrderIds[1]),
                StopBuilder::short(10, 29060, 0.1)->withTD(10)->build()->setExchangeOrderId($exchangeOrderIds[2]),

                ### unchanged ###
                StopBuilder::short(1, 29055, 0.4)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId),
                StopBuilder::short(15, 29061, 0.1)->withTD(10)->build(),
                StopBuilder::short(20, 29155, 0.2)->withTD(100)->build(),
                StopBuilder::short(40, 29029, 0.33)->withTD(5)->build()->setIsTakeProfitOrder(),
            ],
            'expectedMessengerMessages' => [
                new CreateBuyOrderAfterStop(30),
                new CreateBuyOrderAfterStop(10),
            ],
        ];

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create($symbol, 29010, 29030, 29010);
        $liquidationWarningDistance = self::getDistanceAfterWhichMarkPriceUsedForTrigger($ticker);
        $position = PositionFactory::short($symbol, 29000, 1, 99, $ticker->markPrice->value() + $liquidationWarningDistance);
        $triggerBy = TriggerBy::MarkPrice;
        $addPriceDelta = StopHelper::priceModifierIfCurrentPriceOverStop($ticker->markPrice);

        $stops = [
            1 => StopBuilder::short(1, 29035, 0.4)->withTD(5)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create()), # must not be pushed (not active)
            5 => StopBuilder::short(5, 29020, 0.011)->withTD(10)->build(), # before ticker | with oppositeBuy
            10 => StopBuilder::short(10, 29040, 0.1)->withTD(10)->build(), # by tD | with oppositeBuy
            15 => StopBuilder::short(15, 29041, 0.1)->withTD(10)->build(),
            20 => StopBuilder::short(20, 29131, 0.2)->withTD(100)->build(),
            30 => StopBuilder::short(30, 29035, 0.3)->withTD(5)->build()->setIsWithoutOppositeOrder(), # by tD | without oppositeBuy
            40 => StopBuilder::short(40, 29009, 0.33)->withTD(5)->build()->setIsTakeProfitOrder(),
        ];
        $stopsExpectedToPush = [(clone $stops[5])->setPrice($ticker->markPrice->value() + $addPriceDelta), $stops[30], $stops[10]];
        yield 'BTCUSDT SHORT (liquidation in critical range => by marketPrice)' => [
            '$position' => $position,
            '$ticker' => $ticker,
            'stops' => $stops,
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($ticker->symbol, $stopsExpectedToPush, $triggerBy, $exchangeOrderIds),
            'stopsExpectedAfterHandle' => [
                ### pushed (in right order) ###
                StopBuilder::short(5, $ticker->markPrice->value() + $addPriceDelta, 0.011)->withTD(10 + $addTriggerDelta)->build() // initial price is before ticker => set new price + push
                ->setOriginalPrice(29020)
                    ->setExchangeOrderId($exchangeOrderIds[0]),
                StopBuilder::short(30, 29035, 0.3)->withTD(5)->build()->setIsWithoutOppositeOrder()->setExchangeOrderId($exchangeOrderIds[1]),
                StopBuilder::short(10, 29040, 0.1)->withTD(10)->build()->setExchangeOrderId($exchangeOrderIds[2]),

                ### unchanged ###
                StopBuilder::short(1, 29035, 0.4)->withTD(5)->build()->setExchangeOrderId($existedExchangeOrderId),
                StopBuilder::short(15, 29041, 0.1)->withTD(10)->build(),
                StopBuilder::short(20, 29131, 0.2)->withTD(100)->build(),
                StopBuilder::short(40, 29009, 0.33)->withTD(5)->build()->setIsTakeProfitOrder(),
            ],
            'expectedMessengerMessages' => [
                new CreateBuyOrderAfterStop(5),
                new CreateBuyOrderAfterStop(10),
            ],
        ];

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create($symbol, 29050, 29030, 29030);
        $position = PositionFactory::short($symbol, 29000, 1, 100, 100500);

        /** @var Stop[] $stops */
        $stops = [
            1 => StopBuilder::short(1, 29049, 0.4)->build()->setIsCloseByMarketContext()->setExchangeOrderId($existedExchangeOrderId = uuid_create()), # must not be pushed (not active)
            5 => StopBuilder::short(5, 29049, 0.011)->build()->setIsWithoutOppositeOrder()->setIsCloseByMarketContext(), # must be pushed
            10 => StopBuilder::short(10, 29050, 0.1)->build()->setIsCloseByMarketContext(), # must not be pushed (ticker index price is not over stop price)
            15 => StopBuilder::short(15, 29049.99, 0.012)->build()->setIsCloseByMarketContext(), # must be pushed + with opposite orders
        ];
        $stopsExpectedToPush = [clone $stops[5], clone $stops[15]];

        yield 'BTCUSDT SHORT (close by market)' => [
            '$position' => $position,
            '$ticker' => $ticker,
            'stops' => $stops,
            'expectedStopAddApiCalls' => self::successByMarketApiCallExpectations($ticker->symbol, $stopsExpectedToPush,$exchangeOrderIds),
            'stopsExpectedAfterHandle' => [
                ### pushed (in right order) ###

                # initial price is before ticker => set new price + push
                StopBuilder::short(5, 29049, 0.011)->build()->setIsCloseByMarketContext()->setIsWithoutOppositeOrder()->setExchangeOrderId($exchangeOrderIds[0]),
                StopBuilder::short(15, 29049.99, 0.012)->build()->setIsCloseByMarketContext()->setExchangeOrderId($exchangeOrderIds[1]),

                ### unchanged ###
                StopBuilder::short(1, 29049, 0.4)->build()->setIsCloseByMarketContext()->setExchangeOrderId($existedExchangeOrderId),
                StopBuilder::short(10, 29050, 0.1)->build()->setIsCloseByMarketContext(),
            ],
            'expectedMessengerMessages' => [
                new CreateBuyOrderAfterStop(15),
            ],
        ];

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create($symbol, 29050);
        $position = PositionFactory::long($symbol, 29000);
        $triggerBy = TriggerBy::IndexPrice;
        $addPriceDelta = StopHelper::priceModifierIfCurrentPriceOverStop($ticker->indexPrice);

        $stops = [
            1 => StopBuilder::long(1, 29045, 0.011)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create()), # must not be pushed (not active)
            5 => StopBuilder::long(5, 29070, 0.011)->withTD(10)->build(), # must be pushed (before ticker)
            10 => StopBuilder::long(10, 29040, 0.1)->withTD(10)->build()->setIsWithoutOppositeOrder(), # must be pushed (by tD)
            15 => StopBuilder::long(15, 29039, 0.1)->withTD(10)->build(),
            20 => StopBuilder::long(20, 28949, 0.2)->withTD(100)->build(),
            30 => StopBuilder::long(30, 29045, 0.3)->withTD(5)->build(),  # must be pushed (by tD)
        ];
        $stopsExpectedToPush = [(clone $stops[5])->setPrice($ticker->indexPrice->value() - $addPriceDelta), $stops[30], $stops[10]];
        yield 'BTCUSDT LONG' => [
            '$position' => $position,
            '$ticker' => $ticker,
            'stops' => $stops,
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($ticker->symbol, $stopsExpectedToPush, $triggerBy, $exchangeOrderIds),
            'stopsExpectedAfterHandle' => [
                ### pushed (in right order) ###
                StopBuilder::long(5, $ticker->indexPrice->value() - $addPriceDelta, 0.011)->withTD(10 + $addTriggerDelta)->build()
                    ->setExchangeOrderId($exchangeOrderIds[0])
                    ->setOriginalPrice(29070),
                StopBuilder::long(30, 29045, 0.3)->withTD(5)->build()->setExchangeOrderId($exchangeOrderIds[1]),
                StopBuilder::long(10, 29040, 0.1)->withTD(10)->build()->setIsWithoutOppositeOrder()->setExchangeOrderId($exchangeOrderIds[2]),

                ### unchanged ###
                StopBuilder::long(1, 29045, 0.011)->withTD(10)->build()->setExchangeOrderId($existedExchangeOrderId),
                StopBuilder::long(15, 29039, 0.1)->withTD(10)->build(),
                StopBuilder::long(20, 28949, 0.2)->withTD(100)->build(),
            ],
            'expectedMessengerMessages' => [
                new CreateBuyOrderAfterStop(5),
                new CreateBuyOrderAfterStop(30),
            ],
        ];

        # LINKUSDT
        $symbol = SymbolEnum::LINKUSDT;
        $addTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create($symbol, 3.685, 3.687, 3.688);
        $liquidationWarningDistance = self::getDistanceAfterWhichMarkPriceUsedForTrigger($ticker);
        $position = PositionFactory::short($symbol, 24.894, 30, 100, $ticker->markPrice->value() + $liquidationWarningDistance + 1);
        $triggerBy = TriggerBy::IndexPrice;
        $addPriceDelta = StopHelper::priceModifierIfCurrentPriceOverStop($ticker->indexPrice);
        $defaultTd = 0.01;

        /** @var Stop[] $stops */
        $stops = [
            1 => StopBuilder::short(1, 3.685, 10, $symbol)->withTD($defaultTd)->build()->setExchangeOrderId($existedExchangeOrderId = uuid_create()), # must not be pushed (not active)
            5 => StopBuilder::short(5, 3.684, 11, $symbol)->withTD($defaultTd)->build()->setIsWithoutOppositeOrder(), # before ticker => push | without oppositeBuy
            10 => StopBuilder::short(10, 3.695, 12, $symbol)->withTD($defaultTd)->build()->setOppositeOrdersDistance(0.06), # by tD | with oppositeBuy
            15 => StopBuilder::short(15, 3.696, 12, $symbol)->withTD($defaultTd)->build(),
            // @todo takeProfit order
        ];
        $stopsExpectedToPush = [(clone $stops[5])->setPrice($ticker->indexPrice->value() + $addPriceDelta), $stops[10]];

        yield 'LINKUSDT SHORT (liquidation not in critical range => by indexPrice)' => [
            '$position' => $position,
            '$ticker' => $ticker,
            'stops' => $stops,
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($ticker->symbol, $stopsExpectedToPush, $triggerBy, $exchangeOrderIds),
            'stopsExpectedAfterHandle' => [
                ### pushed (in right order) ###

                # initial price is before ticker => set new price + push
                StopBuilder::short(5, $ticker->indexPrice->value() + $addPriceDelta, 11, $symbol)->withTD($symbol->makePrice($defaultTd + $addTriggerDelta)->value())->build()
                    ->setOriginalPrice(3.684)
                    ->setIsWithoutOppositeOrder()
                    ->setExchangeOrderId($exchangeOrderIds[0]),

                # just push
                StopBuilder::short(10, 3.695, 12, $symbol)->withTD($defaultTd)->build()
                    ->setOppositeOrdersDistance(0.06)
                    ->setExchangeOrderId($exchangeOrderIds[1]),

                ### unchanged ###
                StopBuilder::short(1, 3.685, 10, $symbol)->withTD($defaultTd)->build()->setExchangeOrderId($existedExchangeOrderId),
                StopBuilder::short(15, 3.696, 12, $symbol)->withTD($defaultTd)->build(),
            ],
            'expectedMessengerMessages' => [
                new CreateBuyOrderAfterStop(10),
            ],
        ];
    }

    /**
     * @param Stop[] $stops
     *
     * @return ByBitApiCallExpectation[]
     *
     * @todo | tests | move to helper
     */
    public static function successConditionalStopApiCallExpectations(SymbolInterface $symbol, array $stops, TriggerBy $triggerBy, ?array &$exchangeOrderIdsCollector = null): array
    {
        $result = [];
        foreach ($stops as $stop) {
            $exchangeOrderId = uuid_create();

            if ($exchangeOrderIdsCollector !== null) {
                $exchangeOrderIdsCollector[] = $exchangeOrderId;
            }

            $request = PlaceOrderRequest::stopConditionalOrder(
                $symbol->associatedCategory(),
                $symbol,
                $stop->getPositionSide(),
                $stop->getVolume(),
                $stop->getPrice(),
                $triggerBy,
            );

            $result[] = new ByBitApiCallExpectation($request, PlaceOrderResponseBuilder::ok($exchangeOrderId)->build());
        }

        return $result;
    }

    /**
     * @param Stop[] $stops
     *
     * @return ByBitApiCallExpectation[]
     */
    protected static function successByMarketApiCallExpectations(SymbolInterface $symbol, array $stops, ?array &$exchangeOrderIdsCollector = null): array
    {
        $result = [];
        foreach ($stops as $stop) {
            $exchangeOrderId = uuid_create();

            if ($exchangeOrderIdsCollector !== null) {
                $exchangeOrderIdsCollector[] = $exchangeOrderId;
            }

            $request = PlaceOrderRequest::marketClose($symbol->associatedCategory(), $symbol, $stop->getPositionSide(), $stop->getVolume());
            $result[] = new ByBitApiCallExpectation($request, PlaceOrderResponseBuilder::ok($exchangeOrderId)->build());
        }

        return $result;
    }

    /**
     * @return BuyOrder[]
     */
    private static function cloneBuyOrders(BuyOrder ...$buyOrders): array
    {
        $startId = 1;
        $orders = [];
        foreach ($buyOrders as $buyOrder) {
            $orders[] = new BuyOrder($startId++, $buyOrder->getPrice(), $buyOrder->getVolume(), $buyOrder->getSymbol(), $buyOrder->getPositionSide(), $buyOrder->getContext());
        }

        return $orders;
    }
}
