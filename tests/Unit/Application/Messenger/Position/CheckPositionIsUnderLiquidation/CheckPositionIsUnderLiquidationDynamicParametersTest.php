<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Messenger\Position\CheckPositionIsUnderLiquidation;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParameters;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Price\PriceRange;
use App\Domain\Price\SymbolPrice;
use App\Helper\FloatHelper;
use App\Liquidation\Application\Settings\LiquidationHandlerSettings;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Functional\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler\AddStopWhenPositionLiquidationInWarningRangeTest;
use App\Tests\Helper\CheckLiquidationParametersBag;
use App\Tests\Helper\Tests\TestCaseDescriptionHelper;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Worker\AppContext;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group liquidation
 *
 * @covers LiquidationDynamicParameters
 */
final class CheckPositionIsUnderLiquidationDynamicParametersTest extends KernelTestCase
{
    use SettingsAwareTest;

    public function testCriticalPartOfLiquidationDistance(): void
    {
        $criticalPartOfLiqDistance = 10;
        $settingsMock = $this->settingsProviderMock([LiquidationHandlerSettings::CriticalPartOfLiquidationDistance->getSettingKey() => $criticalPartOfLiqDistance]);

        $symbol = SymbolEnum::BTCUSDT;
        $position = PositionBuilder::long()->entry(30000)->size(1)->liq(29999)->build();
        $ticker = TickerFactory::withEqualPrices($symbol, 35000);

        # without override
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: 70, warningPnlDistance: 100);
        $dynamicParameters = new LiquidationDynamicParameters(settingsProvider: $settingsMock, position: $position, ticker: $ticker, handledMessage: $message);

        self::assertEquals($criticalPartOfLiqDistance, $dynamicParameters->criticalPartOfLiquidationDistance());

        # with override
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: 70, warningPnlDistance: 100, criticalPartOfLiquidationDistance: $criticalPartOfLiquidationDistance = 50);
        $dynamicParameters = new LiquidationDynamicParameters(
            settingsProvider: $this->createMock(AppSettingsProviderInterface::class),
            position: $position,
            ticker: $ticker,
            handledMessage: $message
        );

        self::assertEquals(
            $criticalPartOfLiquidationDistance,
            $dynamicParameters->criticalPartOfLiquidationDistance()
        );
    }

    /**
     * @dataProvider additionalStopCases
     */
    public function testAddAdditionalStopCases(
        CheckPositionIsUnderLiquidation $message,
        Position $position,
        Ticker $ticker,
        PriceRange $expectedRangeResult,
        SymbolPrice $expectedStopPrice,
        float $expectedWarningDistance,
        float $expectedCriticalDistance,
        float|int $expectedAcceptableStoppedPart,
        bool $debug = false
    ): void {
        $debug && AppContext::setIsDebug($debug);

        $dynamicParameters = new LiquidationDynamicParameters(
            settingsProvider: self::getContainerSettingsProvider(),
            position: $position,
            ticker: $ticker,
            handledMessage: $message
        );

        $actualStopsRange = $dynamicParameters->actualStopsRange();
        $additionalStopPrice = $dynamicParameters->additionalStopPrice();
        $warningDistance = $dynamicParameters->warningDistance();
        $criticalDistance = $dynamicParameters->criticalDistance();
        $acceptableStoppedPart = $dynamicParameters->acceptableStoppedPart();

        if (!$additionalStopPrice->isPriceInRange($actualStopsRange)) {
            throw new RuntimeException('CheckPositionIsUnderLiquidationDynamicParametersTest | Something went wrong: $additionalStopPrice is not inside of $actualStopsRange');
        }

        self::assertEquals($expectedStopPrice, $additionalStopPrice);
        self::assertEquals($expectedRangeResult, $actualStopsRange);
        self::assertEquals($expectedCriticalDistance, $criticalDistance);
        self::assertEquals($expectedWarningDistance, $warningDistance);
        self::assertEquals(FloatHelper::round($expectedAcceptableStoppedPart, 5), FloatHelper::round($acceptableStoppedPart, 5));
    }

    public function additionalStopCases(): iterable
    {
        $criticalPartOfLiquidationDistance = 30;
 // from functional
        $source = AddStopWhenPositionLiquidationInWarningRangeTest::addStopTestCases('fromDynamicParametersTest', $criticalPartOfLiquidationDistance);
        $source = array_values(iterator_to_array($source));

        foreach ($source as $key => $data) {
            $data = array_values($data);
            $message = $data[0];
            $position = $data[1];
            $ticker = $data[2];
            $note = 'from functional|' . $key . '|' . ($data[6] ?? 'none');

            /**
             * @var CheckPositionIsUnderLiquidation $message
             * @var Position $position
             * @var Ticker $ticker
             */
            $bag = CheckLiquidationParametersBag::create(self::getContainerSettingsProvider(), $message, $position, $ticker);
            $expectedActualStopsRange = $bag->actualStopsRange();
            $expectedStopPrice = $bag->additionalStopPrice();
            $expectedCriticalDistance = $bag->criticalDistance();
            $expectedWarningDistance = $bag->warningDistance();
            $acceptableStoppedPart = $bag->acceptableStoppedPart();
            $caseName = self::wholeDataCaseDescription($message, $position, $ticker, $expectedActualStopsRange, $expectedStopPrice, $expectedWarningDistance, $expectedCriticalDistance, $acceptableStoppedPart, $note);

            $debug = false;
            if ($key === 18) {
//                $expectedStopPrice = Price::float(30176.0);
//                $expectedActualStopsRange = PriceRange::create(30169.96, 30326.88);
//                $debug = true;
            } else {
//                continue;
            }

            yield $caseName => [
                $message, $position, $ticker, $expectedActualStopsRange, $expectedStopPrice, $expectedWarningDistance, $expectedCriticalDistance, $acceptableStoppedPart, $debug,
            ];
        }

        $calculationsInitialModifier = 2.3;
        $actualModifier = LiquidationDynamicParameters::ACCEPTABLE_STOPPED_PART_DIVIDER;
        $koefficientForInLossCases = $calculationsInitialModifier / $actualModifier;

// manual
        #### corner cases
        $symbol = SymbolEnum::BTCUSDT;
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: 70, warningPnlDistance: 100, criticalPartOfLiquidationDistance: $criticalPartOfLiquidationDistance);
        $long = PositionBuilder::long()->entry(30000)->size(1)->liq(29999)->build();

        $ticker = TickerFactory::withEqualPrices($symbol, 30449);
        $expectedActualStopsRange = PriceRange::create(30258.03, 30348.95, $symbol);
        $expectedStopPrice = $symbol->makePrice(30303.49);
        $warningDistance = 304.49;
        $criticalDistance = 182.69;
        $acceptableStoppedPart = 4;
        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedActualStopsRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, 'manual | corner.cases | liq. right after entry / ticker NOT in warn.range (double from functional)') => [
            $message, $long, $ticker, $expectedActualStopsRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart
        ];

        $ticker = TickerFactory::withEqualPrices($symbol, 30100);
        $expectedPriceRange = PriceRange::create(30093.96, 30224.87, $symbol);
        $expectedStopPrice = $symbol->makePrice(30179.6);
        $warningDistance = 301.0;
        $criticalDistance = 180.6;
        $acceptableStoppedPart = 66.4451827242525;
        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, 'manual | corner.cases | liq. right after entry / ticker right before liquidation (in critical range)') => [
            $message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart,
        ];

        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: 1, warningPnlDistance: 1, criticalPartOfLiquidationDistance: $criticalPartOfLiquidationDistance);
        $ticker = TickerFactory::withEqualPrices($symbol, 30100);
        $expectedPriceRange = PriceRange::create(30093.96, 30224.87, $symbol);
        $expectedStopPrice = $symbol->makePrice(30179.6); /** @see LiquidationHandlerSettings::CriticalDistancePnl */
        $warningDistance = 180.6;
        $criticalDistance = 180.6;
        $acceptableStoppedPart = 44.0753;
        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, 'manual | corner.cases | liq. right after entry / ticker right before liquidation (in critical range) + minimal allowed parameters') => [
            $message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart,
        ];

        # scenario when liquidation moved up through position entry price (from previous example)
        $long = PositionBuilder::long()->entry(30000)->size(1)->liq(30050)->build();
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: 1, warningPnlDistance: 1, criticalPartOfLiquidationDistance: $criticalPartOfLiquidationDistance);
        $ticker = TickerFactory::withEqualPrices($symbol, 30100);
        $expectedPriceRange = PriceRange::create(30093.95, 30275.949999999997, $symbol);
        $expectedStopPrice = $symbol->makePrice(30230.6);
        $warningDistance = 180.6;
        $criticalDistance = 180.6;
        $acceptableStoppedPart = 72.31451;
        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, ' => manual | corner.cases | liq before entry / ticker right before liquidation (in critical range) + minimal allowed parameters') => [
            $message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart,
        ];

        // @todo | liquidation | corner cases
//        $ticker = TickerFactory::withEqualPrices($symbol, 30001);
//        $expectedPriceRange = PriceRange::create(30093.97, 30179.65);
//        $expectedStopPrice = $symbol->makePrice(30099.0);
//        $warningDistance = 301.0;
//        $criticalDistance = 180.6;
//        $acceptableStoppedPart = 66.4451827242525;
//        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, 'manual | corner.cases | liq. right after entry / ticker between entry and liquidation (in loss)') => [
//            $message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, true
//        ];
//return;
        #### simple
        $long = PositionBuilder::long()->entry(30000)->size(1)->liq(25000)->build();

        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: 70, warningPnlDistance: 100, criticalPartOfLiquidationDistance: $criticalPartOfLiquidationDistance);

        $ticker = TickerFactory::withEqualPrices($symbol, 29000);
        $expectedPriceRange = PriceRange::create(28457.25, 28542.75, $symbol);
        $expectedStopPrice = $symbol->makePrice(28500);
        $warningDistance = 1500.0;
        $criticalDistance = 174.0;
        $acceptableStoppedPart = 7.434782608695653 * $koefficientForInLossCases;
        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, 'manual | short liq distance / ticker NOT in range to add stop') => [
            $message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart,
        ];

        $ticker = TickerFactory::withEqualPrices($symbol, 27500);
        $expectedPriceRange = PriceRange::create(27458.75, 27541.25, $symbol);
        $expectedStopPrice = $symbol->makePrice(27500);
        $warningDistance = 1500;
        $criticalDistance = 165.0;
        $acceptableStoppedPart = 16.73913043478261 * $koefficientForInLossCases;
        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, 'manual | short liq distance / ticker already in range to add stop') => [
            $message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart,
        ];

        $ticker = TickerFactory::withEqualPrices($symbol, 25200);
        $expectedPriceRange = PriceRange::create(25162.2, 25237.8, $symbol);
        $expectedStopPrice = $symbol->makePrice(25200);
        $warningDistance = 1500.0;
        $criticalDistance = 151.2;
        $acceptableStoppedPart = 41.73913043478261 * $koefficientForInLossCases;
        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, 'manual | short liq distance / ticker IN warn.range') => [
            $message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart,
        ];

        // !!!
        $ticker = TickerFactory::withEqualPrices($symbol, 25100);
        $expectedPriceRange = PriceRange::create(25094.97, 25188.329999999998, $symbol);
        $expectedStopPrice = $symbol->makePrice(25150.6);
        $warningDistance = 1500.0;
        $criticalDistance = 150.6;
        $acceptableStoppedPart = 27.71086; // !!!
        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, '!!!!!!!fix!!!!! manual | short liq distance / ticker right before liquidation (in critical range)') => [
            $message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart,
        ];

        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: 10, warningPnlDistance: 10, criticalPartOfLiquidationDistance: $criticalPartOfLiquidationDistance);
        $ticker = TickerFactory::withEqualPrices($symbol, 25100);
        $expectedPriceRange = PriceRange::create(25094.97, 25188.329999999998, $symbol);
        $expectedStopPrice = $symbol->makePrice(25150.6);
        $warningDistance = 1500.0;
        $criticalDistance = 150.6;
        $acceptableStoppedPart = 27.71086;
        yield self::wholeDataCaseDescription($message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, 'manual | short liq distance / ticker right before liquidation (in critical range) + minimal allowed parameters') => [
            $message, $long, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart,
        ];

        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: 70, warningPnlDistance: 100, criticalPartOfLiquidationDistance: $criticalPartOfLiquidationDistance);
        $short = PositionBuilder::short()->entry(90000)->size(1)->liq(95000)->build();

        $ticker = TickerFactory::withEqualPrices($symbol, 94700);
        $expectedPriceRange = PriceRange::create(94290.15000000001, 94718.89, $symbol);
        $expectedStopPrice = $symbol->makePrice(94431.8);
        $warningDistance = 1500.0;
        $criticalDistance = 568.2;
        $acceptableStoppedPart = 25.32457;
        yield self::wholeDataCaseDescription($message, $short, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart, 'manual | ticker in crit.range') => [
            $message, $short, $ticker, $expectedPriceRange, $expectedStopPrice, $warningDistance, $criticalDistance, $acceptableStoppedPart,
        ];

        // min allowed on 100000

//        $ticker = TickerFactory::withEqualPrices($symbol, 93800);
//        $expectedPriceRange = PriceRange::create(93000.0, 94430.0);
//        yield self::actualStopsRangeCaseDescription($message, $short, $ticker, $expectedPriceRange, 'liq. right after entry / ticker NOT in warn.range') => [
//            $message, $short, $ticker, $expectedPriceRange,
//        ];
//        $ticker = TickerFactory::withEqualPrices($symbol, 90000);
//        $expectedPriceRange = PriceRange::create(89200.0, 90800.0);
//        yield self::actualStopsRangeCaseDescription($message, $short, $ticker, $expectedPriceRange, 'liq. right after entry / ticker NOT in warn.range') => [
//            $message, $short, $ticker, $expectedPriceRange,
//        ];
//
//        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: 30, warningPnlDistance: 30);
//        $ticker = TickerFactory::withEqualPrices($symbol, 90000);
//        $expectedPriceRange = PriceRange::create(91200.0, 92800.0);
//        yield self::actualStopsRangeCaseDescription($message, $short, $ticker, $expectedPriceRange, 'liq. right after entry / ticker NOT in warn.range') => [
//            $message, $short, $ticker, $expectedPriceRange,
//        ];
    }

    private static function tickerInCheckStopsRange(CheckPositionIsUnderLiquidation $message, Position $position): Ticker
    {
        return AddStopWhenPositionLiquidationInWarningRangeTest::tickerInCheckStopsRange($message, $position);
    }

    private static function actualStopsRangeCaseDescription(
        CheckPositionIsUnderLiquidation $message,
        Position $position,
        Ticker $ticker,
        PriceRange $expectedPriceRange,
        ?string $note = null
    ): string {
        return sprintf(
            '[%s%s] / %s / ticker.markPrice = %.2f) => %s',
            $note ? sprintf('%s / ', $note) : '',
            TestCaseDescriptionHelper::getFullPositionCaption($position),
            self::formatHandledMessage($message),
            $ticker->markPrice->value(),
            $expectedPriceRange,
        );
    }

    private static function wholeDataCaseDescription(
        CheckPositionIsUnderLiquidation $message,
        Position $position,
        Ticker $ticker,
        PriceRange $expectedActualStopsRange,
        SymbolPrice $expectedAdditionalStopPrice,
        float $warningDistance,
        float $criticalDistance,
        float|int $acceptableStoppedPart,
        ?string $note = null
    ): string {
        $parameters = [
            'actualStopsRange' => $expectedActualStopsRange,
            'additionalStopPrice' => $expectedAdditionalStopPrice,
            'warningDistance' => $warningDistance,
            'criticalDistance' => $criticalDistance,
            'acceptableStoppedPart' => $acceptableStoppedPart
        ];
        $parametersDesc = '';
        foreach ($parameters as $key => $value) {
            $parametersDesc .= sprintf("     % 25s: %s\n", $key, $value);
        }

        return sprintf(
            "\n[%s]\n  %s\n   ticker.markPrice = %s\n    %s\n    =>\n%s",
            $note ?? 'none',
            TestCaseDescriptionHelper::getFullPositionCaption($position),
            $ticker->markPrice->value(),
            self::formatHandledMessage($message),
            $parametersDesc,
        );
    }

    private static function formatHandledMessage(CheckPositionIsUnderLiquidation $message): string
    {
        return sprintf(
            'msg: percentOfLiquidationDistanceToAddStop: %.1f, acceptableStoppedPart: %.1f, warningPnlDistance: %.1f',
            $message->percentOfLiquidationDistanceToAddStop ?? 'null',
            $message->acceptableStoppedPart ?? 'null',
            $message->warningPnlDistance ?? 'null',
        );
    }
}
