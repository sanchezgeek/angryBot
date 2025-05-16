<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\Push;

use App\Bot\Application\Helper\StopHelper;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Helper\PnlHelper;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Liquidation\Application\Settings\LiquidationHandlerSettings;
use App\Stop\Application\UseCase\Push\MainPositionsStops\PushAllMainPositionsStops;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCommonCasesTest;
use App\Tests\Helper\StopTestHelper;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mock\Response\ByBitV5Api\PositionResponseBuilder;
use App\Tests\Utils\TradingSetup\TradingSetup;

final class PushMainPositionsStopsTest extends PushMultiplePositionsStopsTestAbstract
{
    private const LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT = PushStopsHandler::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT;

    const CATEGORY = AssetCategory::linear;

    protected function setUp(): void
    {
        parent::setUp();

        self::truncateStops();
        self::truncateBuyOrders();
    }

    /**
     * @dataProvider cases
     */
    public function testTest(
        TradingSetup $setup,
        array $apiCalls,
        array $stopsAfterHandle
    ): void {
        $tickers = $setup->getTickers();
        $symbols = array_map(static fn(Ticker $ticker) => $ticker->symbol, $tickers);
        $tickersMap = array_combine(array_map(static fn(Symbol $symbol) => $symbol->value, $symbols), $tickers);

        $tickersApiCalls = [];
        foreach ($tickers as $ticker) {
            $tickersApiCalls[] = self::tickerApiCallExpectation($ticker)->setNoNeedToTrackRequestCallToFurtherCheck();
        }

        $positionsApiResponse = (new PositionResponseBuilder(self::CATEGORY));
        foreach ($setup->getPositions() as $position) {
            $this->havePosition($position->symbol, $position); // fallback for PositionServiceInterface
            $ticker = $tickersMap[$position->symbol->value];
            $positionsApiResponse->withPosition($position, $ticker->markPrice->value());
        }
        $positionsApiCall = new ByBitApiCallExpectation(new GetPositionsRequest(self::CATEGORY, null), $positionsApiResponse->build());

        $this->expectsToMakeApiCalls(...array_merge([$positionsApiCall], $tickersApiCalls, $apiCalls));

        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $setup->getStopsCollection()->getItems()));

        self::warmupSettings([
            LiquidationHandlerSettings::WarningDistancePnl,
            LiquidationHandlerSettings::CriticalPartOfLiquidationDistance,
        ], $symbols);

        $this->runMessageConsume(new PushAllMainPositionsStops());

        self::seeStopsInDb(...$stopsAfterHandle);
    }

    public function cases(): iterable
    {
        $setup = self::baseSetup();

        $symbol = Symbol::BTCUSDT;
        $btcShort = $setup->getPosition($symbol, Side::Sell);

        $markPrice = $btcShort->liquidationPrice - PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT, $btcShort->liquidationPrice) - 100;
        $btcTicker = TickerFactory::create($symbol, ceil($markPrice + 20), ceil($markPrice), ceil($markPrice)); // @todo index?
        $setup->addTicker($btcTicker);

        $triggerBy = TriggerBy::IndexPrice;
        $addPriceDelta = StopHelper::priceModifierIfCurrentPriceOverStop($btcTicker->indexPrice); $addTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);

        $original105Stop = $setup->getStopById(105);
        $new105Price = $btcTicker->indexPrice->value() + $addPriceDelta;

        $exchangeOrderIds = [];
        $btcShortStopsExpectedToPush = [StopTestHelper::clone($original105Stop)->setPrice($new105Price), $setup->getStopById(130), $setup->getStopById(110)];
        $btcShortStopsApiCalls = PushStopsCommonCasesTest::successConditionalStopApiCallExpectations($symbol, $btcShortStopsExpectedToPush, $triggerBy, $exchangeOrderIds);

        $btcStopsAfter = array_replace($setup->getStopsCollection()->grabBySymbolAndSide($symbol), [
            # initial price is before ticker => set new price + push
            105 => StopBuilder::short(105, $new105Price, $original105Stop->getVolume())
                ->withTD($symbol->makePrice($original105Stop->getTriggerDelta() + $addTriggerDelta)->value())
                ->withContext($original105Stop->getContext())
                ->build()
                    ->setOriginalPrice($original105Stop->getPrice())
                    ->setIsWithoutOppositeOrder()
                    ->setExchangeOrderId($exchangeOrderIds[0]),

            # just push
            130 => StopTestHelper::clone($setup->getStopById(130))->setExchangeOrderId($exchangeOrderIds[1]),
            110 => StopTestHelper::clone($setup->getStopById(110))->setExchangeOrderId($exchangeOrderIds[2]),
        ]);

        $symbol = Symbol::LINKUSDT;
        $linkTicker = TickerFactory::create($symbol, 23.685, 23.687, 23.688);
        $setup->addTicker($linkTicker);

        $triggerBy = TriggerBy::IndexPrice; // $liquidationWarningDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::LIQUIDATION_WARNING_DISTANCE_PNL_PERCENT, $linkTicker->markPrice);
        $addPriceDelta = StopHelper::priceModifierIfCurrentPriceOverStop($linkTicker->indexPrice); $addTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);

        $original205Stop = $setup->getStopById(205);
        $new205Price = $linkTicker->indexPrice->value() + $addPriceDelta;

        $linkShortStopsExpectedToPush = [StopTestHelper::clone($setup->getStopById(205))->setPrice($new205Price), $setup->getStopById(210)];
        $exchangeOrderIds = [];
        $linkShortStopsApiCalls = PushStopsCommonCasesTest::successConditionalStopApiCallExpectations($symbol, $linkShortStopsExpectedToPush, $triggerBy, $exchangeOrderIds);

        $linkShortResultStopsAfter = array_replace($setup->getStopsCollection()->grabBySymbolAndSide($symbol), [
            # initial price is before ticker => set new price + push
            205 => StopBuilder::short(205, $new205Price, $original205Stop->getVolume(), $symbol)
                ->withTD($symbol->makePrice($original205Stop->getTriggerDelta() + $addTriggerDelta)->value())
                ->withContext($original205Stop->getContext())
                ->build()
                    ->setOriginalPrice($original205Stop->getPrice())
                    ->setIsWithoutOppositeOrder()
                    ->setExchangeOrderId($exchangeOrderIds[0]),

            # just push
            210 => StopTestHelper::clone($setup->getStopById(210))->setExchangeOrderId($exchangeOrderIds[1]),
        ]);

        # other symbols without stops
        $setup->addTicker(TickerFactory::create(Symbol::ETHUSDT, 2100,  2100,  2100));
        $setup->addTicker(TickerFactory::create(Symbol::ADAUSDT, 0.6, 0.6, 0.6));

        yield [
            'setup' => $setup,
            'apiCalls' => array_merge($linkShortStopsApiCalls, $btcShortStopsApiCalls), # order matters
            'stopsAfterHandle' => array_merge($linkShortResultStopsAfter, $btcStopsAfter),
            // @todo BuyOrders after handle
        ];
    }
}
