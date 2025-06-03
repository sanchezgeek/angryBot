<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\Push;

use App\Bot\Application\Helper\StopHelper;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Helper\PnlHelper;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Liquidation\Application\Settings\LiquidationHandlerSettings;
use App\Settings\Application\Service\SettingAccessor;
use App\Stop\Application\UseCase\Push\MainPositionsStops\PushAllMainPositionsStops;
use App\Stop\Application\UseCase\Push\RestPositionsStops\PushAllRestPositionsStops;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Functional\Bot\Handler\PushOrdersToExchange\Stop\PushStopsCommonCasesTest;
use App\Tests\Helper\StopTestHelper;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mock\Response\ByBitV5Api\PositionResponseBuilder;
use App\Tests\Utils\TradingSetup\TradingSetup;

final class PushRestPositionsStopsTest extends PushMultiplePositionsStopsTestAbstract
{
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
        $tickersMap = array_combine(array_map(static fn(SymbolInterface $symbol) => $symbol->value, $symbols), $tickers);

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

        $this->runMessageConsume(new PushAllRestPositionsStops());

        self::seeStopsInDb(...$stopsAfterHandle);
    }

    public function cases(): iterable
    {
        $setup = self::baseSetup();

        $symbol = SymbolEnum::BTCUSDT;
        $btcTicker = TickerFactory::create($symbol, 29000, 29000, 29000);
        $setup->addTicker($btcTicker);

        $triggerBy = TriggerBy::IndexPrice;
        $addPriceDelta = StopHelper::priceModifierIfCurrentPriceOverStop($btcTicker->indexPrice); $addTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);

        $original150Stop = $setup->getStopById(150);
        $new150Price = $btcTicker->indexPrice->value() - $addPriceDelta;

        $exchangeOrderIds = [];
        $btcShortStopsExpectedToPush = [StopTestHelper::clone($original150Stop)->setPrice($new150Price)];
        $btcShortStopsApiCalls = PushStopsCommonCasesTest::successConditionalStopApiCallExpectations($symbol, $btcShortStopsExpectedToPush, $triggerBy, $exchangeOrderIds);

        $btcStopsAfter = array_replace($setup->getStopsCollection()->grabBySymbolAndSide($symbol), [
            # initial price is before ticker => set new price + push
            150 => StopBuilder::long(150, $new150Price, $original150Stop->getVolume())
                ->withTD($symbol->makePrice($original150Stop->getTriggerDelta() + $addTriggerDelta)->value())
                ->withContext($original150Stop->getContext())
                ->build()
                    ->setOriginalPrice($original150Stop->getPrice())
                    ->setExchangeOrderId($exchangeOrderIds[0]),
        ]);

        $symbol = SymbolEnum::LINKUSDT;
        $linkTicker = TickerFactory::create($symbol, 23.6, 23.6, 23.6);
        $setup->addTicker($linkTicker);

        $triggerBy = TriggerBy::IndexPrice;
        $addPriceDelta = StopHelper::priceModifierIfCurrentPriceOverStop($linkTicker->indexPrice); $addTriggerDelta = StopHelper::additionalTriggerDeltaIfCurrentPriceOverStop($symbol);

        $original230Stop = $setup->getStopById(230);
        $new230Price = $linkTicker->indexPrice->value() - $addPriceDelta;

        $linkShortStopsExpectedToPush = [StopTestHelper::clone($original230Stop)->setPrice($new230Price)];
        $exchangeOrderIds = [];
        $linkShortStopsApiCalls = PushStopsCommonCasesTest::successConditionalStopApiCallExpectations($symbol, $linkShortStopsExpectedToPush, $triggerBy, $exchangeOrderIds);

        $linkShortResultStopsAfter = array_replace($setup->getStopsCollection()->grabBySymbolAndSide($symbol), [
            # initial price is before ticker => set new price + push
            230 => StopBuilder::long(230, $new230Price, $original230Stop->getVolume(), $symbol)
                ->withTD($symbol->makePrice($original230Stop->getTriggerDelta() + $addTriggerDelta)->value())
                ->withContext($original230Stop->getContext())
                ->build()
                    ->setOriginalPrice($original230Stop->getPrice())
                    ->setExchangeOrderId($exchangeOrderIds[0]),
        ]);

        # other symbols without stops
        $setup->addTicker(TickerFactory::create(SymbolEnum::ETHUSDT, 2100,  2100,  2100));
        $setup->addTicker(TickerFactory::create(SymbolEnum::ADAUSDT, 0.6, 0.6, 0.6));

        yield [
            'setup' => $setup,
            'apiCalls' => array_merge($btcShortStopsApiCalls, $linkShortStopsApiCalls),
            'stopsAfterHandle' => array_merge($linkShortResultStopsAfter, $btcStopsAfter),
            // @todo BuyOrders after handle
        ];
    }
}
