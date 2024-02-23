<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop;

use App\Application\EventListener\Stop\CreateOppositeBuyOrdersListener;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Price\Helper\PriceHelper;
use App\Helper\VolumeHelper;
use App\Infrastructure\ByBit\API\V5\Request\Trade\PlaceOrderRequest;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\OrderCasesTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mock\Response\ByBitV5Api\PlaceOrderResponseBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\AbstractOrdersPusher
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler
 */
final class PushStopsCommonCasesTest extends KernelTestCase
{
    use OrderCasesTester;
    use StopsTester;
    use BuyOrdersTester;
    use MessageConsumerTrait;
    use ByBitV5ApiRequestsMocker;

    private const WITHOUT_OPPOSITE_CONTEXT = Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT;
    private const OPPOSITE_BUY_DISTANCE = 38;
    private const ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP = 15;

    private const SYMBOL = Symbol::BTCUSDT;
    private const ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP = 7;
    private const LIQUIDATION_WARNING_DELTA = PushStopsHandler::LIQUIDATION_WARNING_DELTA;

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
        $addPriceDelta = self::ADD_PRICE_DELTA_IF_INDEX_ALREADY_OVER_STOP;
        $addTriggerDelta = self::ADD_TRIGGER_DELTA_IF_INDEX_ALREADY_OVER_STOP;

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create(self::SYMBOL, 29050, 29030, 29030);
        $position = PositionFactory::short(self::SYMBOL, 29000, 1, 100, $ticker->markPrice->value() + self::LIQUIDATION_WARNING_DELTA + 1);
        $triggerBy = TriggerBy::IndexPrice;
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
                ...$this->expectedOppositeOrders($stops[10], $exchangeOrderIds[2])
            ],
        ];

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create(self::SYMBOL, 29010, 29030, 29010);
        $position = PositionFactory::short(self::SYMBOL, 29000, 1, 99, $ticker->markPrice->value() + self::LIQUIDATION_WARNING_DELTA);
        $triggerBy = TriggerBy::MarkPrice;
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
                ...$this->expectedOppositeOrders($stops[10], $exchangeOrderIds[2])
            ],
        ];

        $exchangeOrderIds = [];
        $ticker = TickerFactory::create(self::SYMBOL, 29050);
        $position = PositionFactory::long(self::SYMBOL, 29000);
        $triggerBy = TriggerBy::IndexPrice;
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
                ...$this->expectedOppositeOrders($stops[30], $exchangeOrderIds[1])
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
        $position = PositionFactory::short(self::SYMBOL, 29000); $ticker = TickerFactory::create(self::SYMBOL, 29050);

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
        yield '[BTCUSDT SHORT] Small order => One opposite' => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops = [
                StopBuilder::short(10, 29060, 0.001)->withTD(10)->build(),
                StopBuilder::short(20, 29055, 0.005)->withTD(10)->build(),
            ],
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($ticker->symbol, [$stops[1], $stops[0]], TriggerBy::IndexPrice, $exchangeOrderIds),
            'buyOrdersExpectedAfterHandle' => [
                ...$this->expectedOppositeOrders($stops[1], $exchangeOrderIds[0]),
                ...$this->expectedOppositeOrders($stops[0], $exchangeOrderIds[1])
            ],
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
        $position = PositionFactory::long(self::SYMBOL, 29000); $ticker = TickerFactory::create(self::SYMBOL, 29050);

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
        yield '[BTCUSDT LONG] Small order => One opposite' => [
            '$position' => $position,
            '$ticker' => $ticker,
            '$stops' => $stops = [
                StopBuilder::long(10, 29045, 0.001)->withTD(10)->build(),
                StopBuilder::long(20, 29040, 0.005)->withTD(10)->build(),
            ],
            'expectedStopAddApiCalls' => self::successConditionalStopApiCallExpectations($ticker->symbol, $stops, TriggerBy::IndexPrice, $exchangeOrderIds),
            'buyOrdersExpectedAfterHandle' => [
                ...$this->expectedOppositeOrders($stops[0], $exchangeOrderIds[0]),
                ...$this->expectedOppositeOrders($stops[1], $exchangeOrderIds[1])
            ],
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
    }

    /**
     * @return BuyOrder[]
     */
    private function expectedOppositeOrders(Stop $stop, string $pushedStopExchangeOrderId, int $fromId = 1): array
    {
        $side = $stop->getPositionSide();
        $stopPrice = $stop->getPrice();
        $stopVolume = $stop->getVolume();

        $baseDistance = $side->isLong() ? CreateOppositeBuyOrdersListener::LONG_BUY_ORDER_OPPOSITE_PRICE_DISTANCE : CreateOppositeBuyOrdersListener::SHORT_BUY_ORDER_OPPOSITE_PRICE_DISTANCE;
        $baseDistance = $side->isLong() ? $baseDistance : -$baseDistance;

        if ($stopVolume >= 0.006) {
            $orders = [
                new BuyOrder($fromId++, PriceHelper::round($stopPrice + $baseDistance), VolumeHelper::round($stopVolume / 3), $side),
                new BuyOrder($fromId++, PriceHelper::round($stopPrice + $baseDistance + $baseDistance / 3.8), VolumeHelper::round($stopVolume / 4.5), $side),
                new BuyOrder($fromId, PriceHelper::round($stopPrice + $baseDistance + $baseDistance / 2), VolumeHelper::round($stopVolume / 3.5), $side),
            ];
        } else {
            $orders = [new BuyOrder($fromId, $stopPrice + $baseDistance, $stopVolume, $side)];
        }

        foreach ($orders as $order) {
            $order->setOnlyAfterExchangeOrderExecutedContext($pushedStopExchangeOrderId);
        }

        return $orders;
    }

    /**
     * @param BuyOrder[] $stops
     *
     * @return ByBitApiCallExpectation[]
     */
    protected static function successConditionalStopApiCallExpectations(Symbol $symbol, array $stops, TriggerBy $triggerBy, array &$exchangeOrderIdsCollector = null): array
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
     * @return BuyOrder[]
     */
    private static function cloneBuyOrders(BuyOrder ...$buyOrders): array
    {
        $startId = 1;
        $orders = [];
        foreach ($buyOrders as $buyOrder) {
            $orders[] = new BuyOrder($startId++, $buyOrder->getPrice(), $buyOrder->getVolume(), $buyOrder->getPositionSide(), $buyOrder->getContext());
        }

        return $orders;
    }
}
