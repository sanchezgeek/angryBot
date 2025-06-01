<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop;

use App\Application\EventListener\Stop\CreateOppositeBuyOrdersListener;
use App\Bot\Application\Helper\StopHelper;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Settings\TradingSettings;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\Order;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Liquidation\Application\Settings\LiquidationHandlerSettings;
use App\Settings\Application\Service\SettingAccessor;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\Position\PositionBuilder;
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
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function sprintf;

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

    /**
     * @todo | DRY
     * @see CreateOppositeBuyOrdersListener::MAIN_SYMBOLS
     */
    private const MAIN_SYMBOLS = [
        Symbol::BTCUSDT,
        Symbol::ETHUSDT,
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
        array $stops,
        array $expectedMarketBuyApiCalls,
        array $stopsExpectedAfterHandle,
        array $buyOrdersExpectedAfterHandle,
    ): void {
        $this->overrideSetting(SettingAccessor::exact(LiquidationHandlerSettings::WarningDistancePnl, $position->symbol, $position->side), self::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT);
        $this->overrideSetting(SettingAccessor::exact(LiquidationHandlerSettings::CriticalDistancePnl, $position->symbol, $position->side), self::LIQUIDATION_CRITICAL_DISTANCE_PNL_PERCENT);

        $this->haveTicker($ticker);
        $this->havePosition($position->symbol, $position);
        $this->expectsToMakeApiCalls(...$expectedMarketBuyApiCalls);

        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $stops));

        $this->runMessageConsume(new PushStops($position->symbol, $position->side));

        self::seeStopsInDb(...$stopsExpectedAfterHandle);
        self::seeBuyOrdersInDb(...self::cloneBuyOrders(...$buyOrdersExpectedAfterHandle));
    }

    public function pushStopsTestCases(): iterable
    {
        # BTCUSDT
        $symbol = Symbol::BTCUSDT;
        $addTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create($symbol, 29050, 29030, 29030);
        $liquidationWarningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT, $ticker->markPrice);
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
            'buyOrdersExpectedAfterHandle' => [
                ...$this->expectedOppositeOrders($stops[30], $exchangeOrderIds[1]),
                ...$this->expectedOppositeOrders($stops[10], $exchangeOrderIds[2]),
            ],
        ];

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create($symbol, 29010, 29030, 29010);
        $liquidationWarningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT, $ticker->markPrice);
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
            'buyOrdersExpectedAfterHandle' => [
                ...$this->expectedOppositeOrders($stopsExpectedToPush[0], $exchangeOrderIds[0]),
                ...$this->expectedOppositeOrders($stops[10], $exchangeOrderIds[2]),
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
            'buyOrdersExpectedAfterHandle' => [
                ...$this->expectedOppositeOrders($stops[15], $exchangeOrderIds[1]),
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
            'buyOrdersExpectedAfterHandle' => [
                ...$this->expectedOppositeOrders($stopsExpectedToPush[0], $exchangeOrderIds[0]),
                ...$this->expectedOppositeOrders($stops[30], $exchangeOrderIds[1]),
            ],
        ];

        # LINKUSDT
        $symbol = Symbol::LINKUSDT;
        $addTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create($symbol, 3.685, 3.687, 3.688);
        $liquidationWarningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT, $ticker->markPrice);
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
            'buyOrdersExpectedAfterHandle' => [
                ...$this->expectedOppositeOrders($stops[10], $exchangeOrderIds[1]),
            ],
        ];
    }

    /**
     * @dataProvider oppositeBuyOrderCreateTestCases
     *
     * @param BuyOrder[] $buyOrdersExpectedAfterHandle
     */
    public function testCreateOppositeBuyOrders(
        Position $position,
        Ticker $ticker,
        array $stops,
        array $expectedMarketBuyApiCalls,
        array $buyOrdersExpectedAfterHandle,
    ): void {
        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, $position);
        $this->expectsToMakeApiCalls(...$expectedMarketBuyApiCalls);
        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $stops));

        $this->runMessageConsume(new PushStops($position->symbol, $position->side));

        self::seeBuyOrdersInDb(...self::cloneBuyOrders(...$buyOrdersExpectedAfterHandle));
    }

    private function oppositeBuyOrderCreateTestCases(): iterable
    {
        # BTCUSDT SHORT
        $symbol = Symbol::BTCUSDT;
        $position = PositionFactory::short($symbol, 29000); $ticker = TickerFactory::create($symbol, 29050);

        $exchangeOrderIds = [];
        yield '[BTCUSDT SHORT] No opposite' => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops = [
                StopBuilder::short(10, 29060, 0.001)->withTD(10)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(20, 29055, 0.005)->withTD(10)->build()->setIsWithoutOppositeOrder(),
            ],
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($ticker->symbol, [$stops[1], $stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds),
            'buyOrdersExpectedAfterHandle' => [],
        ];

        $exchangeOrderIds = [];
        $stops = [
            StopBuilder::short(10, 29060, 0.001)->withTD(10)->build(),
            StopBuilder::short(20, 29055, 0.005)->withTD(10)->build(),
        ];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($ticker->symbol, [$stops[1], $stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = [
            ...$this->expectedOppositeOrders($stops[1], $exchangeOrderIds[0]),
            ...$this->expectedOppositeOrders($stops[0], $exchangeOrderIds[1]),
        ];
        yield sprintf('[BTCUSDT SHORT] Small order => One opposite: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        $stops = [StopBuilder::short(20, 29055, 0.006)->withTD(10)->build()];
        $exchangeOrderIds = [];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($ticker->symbol, $stops, TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = $this->expectedOppositeOrders($stops[0], $exchangeOrderIds[0]);
        yield \sprintf('[BTCUSDT SHORT] Big order => Partial opposites: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        # BTCUSDT LONG
        $position = PositionFactory::long($symbol, 29000); $ticker = TickerFactory::create($symbol, 29050);

        $exchangeOrderIds = [];
        yield '[BTCUSDT LONG] No opposite' => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops = [
                StopBuilder::long(10, 29040, 0.001)->withTD(10)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::long(20, 29045, 0.005)->withTD(10)->build()->setIsWithoutOppositeOrder(),
            ],
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($ticker->symbol, [$stops[1], $stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds),
            'buyOrdersExpectedAfterHandle' => [],
        ];

        $exchangeOrderIds = [];
        $stops = [
            StopBuilder::long(10, 29045, 0.001)->withTD(10)->build(),
            StopBuilder::long(20, 29040, 0.005)->withTD(10)->build(),
        ];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($ticker->symbol, $stops, TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = [
            ...$this->expectedOppositeOrders($stops[0], $exchangeOrderIds[0]),
            ...$this->expectedOppositeOrders($stops[1], $exchangeOrderIds[1]),
        ];
        yield sprintf('[BTCUSDT LONG] Small order => One opposite: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        $exchangeOrderIds = [];
        $stops = [StopBuilder::long(20, 29045, 0.02)->withTD(10)->build()];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($ticker->symbol, $stops, TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = $this->expectedOppositeOrders($stops[0], $exchangeOrderIds[0]);
        yield \sprintf('[BTCUSDT LONG] Big order => Partial opposites: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        # AAVEUSDT SHORT
        $symbol = Symbol::AAVEUSDT;
        $position = PositionFactory::short($symbol, 391.1, 45); $ticker = TickerFactory::create($symbol, 391.2);

        $exchangeOrderIds = [];
        yield '[AAVEUSDT SHORT] No opposite' => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops = [
                StopBuilder::short(10, 391.22, 0.01, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::short(20, 391.21, 0.05, $symbol)->build()->setIsWithoutOppositeOrder(),
            ],
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($symbol, [$stops[1], $stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds),
            'buyOrdersExpectedAfterHandle' => [],
        ];

        $exchangeOrderIds = [];
        $stops = [
            StopBuilder::short(10, 391.22, 0.01, $symbol)->build(),
            StopBuilder::short(20, 391.21, 0.05, $symbol)->build(),
        ];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($symbol, [$stops[1], $stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = [
            ...$this->expectedOppositeOrders($stops[1], $exchangeOrderIds[0]),
            ...$this->expectedOppositeOrders($stops[0], $exchangeOrderIds[1]),
        ];
        yield sprintf('[AAVEUSDT SHORT] Small order => One opposite: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        $stops = [StopBuilder::short(10, 391.22, 0.06, $symbol)->build()];
        $exchangeOrderIds = [];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($symbol, $stops, TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = $this->expectedOppositeOrders($stops[0], $exchangeOrderIds[0]);
        yield \sprintf('[AAVEUSDT SHORT] Big order => Partial opposites: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        # custom opposite orders distance
        $exchangeOrderIds = [];
        $stops = [
            StopBuilder::short(10, 391.22, 0.01, $symbol)->build()->setOppositeOrdersDistance(10),
        ];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($symbol, [$stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = [
            ...$this->expectedOppositeOrders($stops[0], $exchangeOrderIds[0]),
        ];
        yield sprintf('[AAVEUSDT SHORT] Custom opposite orders distance: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        # AAVEUSDT LONG
        $position = PositionBuilder::long()->symbol($symbol)->entry(391.1)->size(45)->build(); $ticker = TickerFactory::create($symbol, 391.2);

        $exchangeOrderIds = [];
        yield '[AAVEUSDT LONG] No opposite' => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops = [
                StopBuilder::long(10, 391.18, 0.01, $symbol)->build()->setIsWithoutOppositeOrder(),
                StopBuilder::long(20, 391.19, 0.05, $symbol)->build()->setIsWithoutOppositeOrder(),
            ],
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($symbol, [$stops[1], $stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds),
            'buyOrdersExpectedAfterHandle' => [],
        ];

        $exchangeOrderIds = [];
        $stops = [
            StopBuilder::long(10, 391.18, 0.01, $symbol)->build(),
            StopBuilder::long(20, 391.19, 0.05, $symbol)->build(),
        ];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($symbol, [$stops[1], $stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = [
            ...$this->expectedOppositeOrders($stops[1], $exchangeOrderIds[0]),
            ...$this->expectedOppositeOrders($stops[0], $exchangeOrderIds[1]),
        ];
        yield sprintf('[AAVEUSDT LONG] Small order => One opposite: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        $stops = [StopBuilder::long(10, 391.19, 0.06, $symbol)->build()];
        $exchangeOrderIds = [];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($symbol, $stops, TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = $this->expectedOppositeOrders($stops[0], $exchangeOrderIds[0]);
        yield \sprintf('[AAVEUSDT LONG] Big order => Partial opposites: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];

        # custom opposite orders distance
        $exchangeOrderIds = [];
        $stops = [
            StopBuilder::long(20, 391.19, 0.05, $symbol)->build()->setOppositeOrdersDistance(10),
        ];
        $expectedStopAddApiCalls = self::successConditionalStopApiCallExpectations($symbol, [$stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds);
        $oppositeOrders = [
            ...$this->expectedOppositeOrders($stops[0], $exchangeOrderIds[0]),
        ];
        yield sprintf('[AAVEUSDT LONG] Custom opposite orders distance: %s => %s', self::ordersDesc(...$stops), self::ordersDesc(...$oppositeOrders)) => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops,
            'expectedStopAddApiCalls' => $expectedStopAddApiCalls,
            'buyOrdersExpectedAfterHandle' => $oppositeOrders,
        ];
    }

    /**
     * @return BuyOrder[]
     */
    private function expectedOppositeOrders(Stop $stop, string $pushedStopExchangeOrderId, int $fromId = 1): array
    {
        $side = $stop->getPositionSide();
        $symbol = $stop->getSymbol();
        $stopPrice = $stop->getPrice();
        $stopVolume = $stop->getVolume();

        $defaultDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta($this->oppositeBuyOrderPnlDistance($stop), $stop->getSymbol()->makePrice($stopPrice));
        $distance = $stop->getOppositeOrderDistance() ?? FloatHelper::modify($defaultDistance, 0.1, 0.2);

        $priceModifier = $side->isLong() ? $distance : -$distance;
        $oppositeSlPriceDistanceOnCreatedBuyOrders = $distance * CreateOppositeBuyOrdersListener::OPPOSITE_SL_PRICE_MODIFIER;

        $bigStopVolume = $symbol->roundVolume($symbol->minOrderQty() * 6);


        if ($stopVolume >= $bigStopVolume) {
            $ordersDef = [
                new Order($symbol->makePrice($stopPrice + $priceModifier), $symbol->roundVolume($stopVolume / 3)),
                new Order($symbol->makePrice($stopPrice + $priceModifier + $priceModifier / 3.8), $symbol->roundVolume($stopVolume / 4.5)),
                new Order($symbol->makePrice($stopPrice + $priceModifier + $priceModifier / 2),   $symbol->roundVolume($stopVolume / 3.5)),
            ];
        } else {
            $ordersDef = [
                new Order($symbol->makePrice($stopPrice + $priceModifier), $symbol->roundVolume($stopVolume)),
            ];
        }

        $ordersDef = new OrdersLimitedWithMaxVolume(
            new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$ordersDef)),
            $stopVolume
        );

        $orders = [];
        foreach ($ordersDef->getOrders() as $key => $order) {
            $orders[] = new BuyOrder($fromId++, $order->price(), $order->volume(), $symbol, $side);
        }

        foreach ($orders as $order) {
            $order->setOnlyAfterExchangeOrderExecutedContext($pushedStopExchangeOrderId);
            $order->setOppositeStopId($stop->getId());
            $order->setIsOppositeBuyOrderAfterStopLossContext();
            $order->setIsForceBuyOrderContext();
            $order->setOppositeOrdersDistance($oppositeSlPriceDistanceOnCreatedBuyOrders);
        }

        return $orders;
    }

    private static ?array $oppositeBuyOrderPnlDistances = null;
    private static ?array $oppositeBuyOrderPnlDistancesForAltCoins = null;
    private static function oppositeBuyOrderPnlDistance(Stop $stop): Percent
    {
        if (null === self::$oppositeBuyOrderPnlDistances) {
            self::$oppositeBuyOrderPnlDistances = [
                Side::Buy->value => self::getSettingValue(TradingSettings::Opposite_BuyOrder_PnlDistance_ForLongPosition),
                Side::Sell->value => self::getSettingValue(TradingSettings::Opposite_BuyOrder_PnlDistance_ForShortPosition),
            ];
        }

        if (null === self::$oppositeBuyOrderPnlDistancesForAltCoins) {
            self::$oppositeBuyOrderPnlDistancesForAltCoins = [
                Side::Buy->value => self::getSettingValue(TradingSettings::Opposite_BuyOrder_PnlDistance_ForLongPosition_AltCoin),
                Side::Sell->value => self::getSettingValue(TradingSettings::Opposite_BuyOrder_PnlDistance_ForShortPosition_AltCoin),
            ];
        }

        if (!in_array($stop->getSymbol(), self::MAIN_SYMBOLS, true)) {
            return self::$oppositeBuyOrderPnlDistancesForAltCoins[$stop->getPositionSide()->value];
        }

        return self::$oppositeBuyOrderPnlDistances[$stop->getPositionSide()->value];
    }

    /**
     * @param Stop[] $stops
     *
     * @return ByBitApiCallExpectation[]
     *
     * @todo | tests | move to helper
     */
    public static function successConditionalStopApiCallExpectations(Symbol $symbol, array $stops, TriggerBy $triggerBy, ?array &$exchangeOrderIdsCollector = null): array
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
    protected static function successByMarketApiCallExpectations(Symbol $symbol, array $stops, ?array &$exchangeOrderIdsCollector = null): array
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
