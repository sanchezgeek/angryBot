<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Helper\CheckLiquidationParametersHelper;
use App\Tests\Helper\Tests\TestCaseDescriptionHelper;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Worker\AppContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function array_merge;
use function array_sum;
use function round;
use function sprintf;
use function uuid_create;

/**
 * @group liquidation
 *
 * @covers CheckPositionIsUnderLiquidationHandler
 */
class AddStopWhenPositionLiquidationInWarningRangeTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;
    use ByBitV5ApiRequestsMocker;

//    private const ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA = CheckPositionIsUnderLiquidationHandler::ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA;
//    private const ADDITIONAL_STOP_TRIGGER_SHORT_DELTA = CheckPositionIsUnderLiquidationHandler::ADDITIONAL_STOP_TRIGGER_SHORT_DELTA;

    protected ExchangeServiceInterface $exchangeServiceMock;
    protected PositionServiceInterface $positionServiceStub;

    private CheckPositionIsUnderLiquidationHandler $handler;

    protected function setUp(): void
    {
        self::truncateStops();

        $this->handler = self::getContainer()->get(CheckPositionIsUnderLiquidationHandler::class);
    }

    /**
     * @dataProvider addStopTestCases
     */
    public function testAddStop(
        CheckPositionIsUnderLiquidation $message,
        Position $position,
        Ticker $ticker,
        array $delayedStops,
        array $activeConditionalStops,
        array $expectedAdditionalStops,
        bool $debug = false
    ): void {
        AppContext::setIsDebug($debug);

        $symbol = $position->symbol;
        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, $position);
        $this->haveAvailableSpotBalance($symbol, 0.1);

        $this->haveStopsInDb(...$delayedStops);
        $this->haveActiveConditionalStopsOnMultipleSymbols(...$activeConditionalStops);

        // Act
        ($this->handler)($message);

        // Arrange
        self::seeStopsInDb(...array_merge($delayedStops, $expectedAdditionalStops));
    }

    public function addStopTestCases(): iterable
    {
        $percentOfLiquidationDistanceToAddStop = 70;
        $warningPnlDistance = 100;

// (1) fixed applicableStoppedPart START
        $acceptableStoppedPart = 15.1;
        $delayedStopsPercent = 4;
        $pushedStopsPercent = 7;

        ### BTCUSDT ###
        $symbol = Symbol::BTCUSDT;
        $message = new CheckPositionIsUnderLiquidation(
            symbol: $symbol,
            percentOfLiquidationDistanceToAddStop: $percentOfLiquidationDistanceToAddStop,
            acceptableStoppedPart: $acceptableStoppedPart,
            warningPnlDistance: $warningPnlDistance
        );

        # BTCUSDT SHORT
        $shortEntry = 35000; $shortLiquidation = 40000;
        // --- just short
        $short = PositionBuilder::short()->entry($shortEntry)->size(0.5)->liq($shortLiquidation)->build();
        $ticker = self::tickerInCheckStopsRange($message, $short);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $short);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, $message, [...$delayed, ...$active], 0.021, 36500);

        yield self::caseDescription($message, $short, $ticker, $delayed, $active, $expectedStop, 'fixed. part') => [
            $message, $short, $ticker, $delayed, $active, [$expectedStop],
        ];

        // --- short with hedge
        $long = PositionBuilder::long()->entry($shortEntry - 10000)->size(0.11)->build();
        $short = PositionBuilder::short()->entry($shortEntry)->size(0.5)->liq($shortLiquidation)->opposite($long)->build();
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $short);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, $message, [...$delayed, ...$active], 0.016, 36500);
        yield self::caseDescription($message, $short, $ticker, $delayed, $active, $expectedStop, 'fixed. part') => [
            $message, $short, $ticker, $delayed, $active, [$expectedStop],
        ];

        # BTCUSDT LONG
        $longEntry = 35000; $longLiquidation = 25000;
        // -- just long
        $long = PositionBuilder::long()->entry($longEntry)->size(0.5)->liq($longLiquidation)->build();
        $ticker = self::tickerInCheckStopsRange($message, $long);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.021, 32000);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'fixed. part') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        // -- long with hedge
        $short = PositionBuilder::short()->entry($longEntry + 10000)->size(0.11)->build();
        $long = PositionBuilder::long()->entry($longEntry)->size(0.5)->liq($longLiquidation)->opposite($short)->build();
        $ticker = self::tickerInCheckStopsRange($message, $long);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.016, 32000);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'fixed. part') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        ### LINKUSDT ###
        $symbol = Symbol::LINKUSDT;
        $message = new CheckPositionIsUnderLiquidation(
            symbol: $symbol,
            percentOfLiquidationDistanceToAddStop: $percentOfLiquidationDistanceToAddStop,
            acceptableStoppedPart: $acceptableStoppedPart,
            warningPnlDistance: $warningPnlDistance
        );

        # LINKUSDT SHORT
        $shortEntry = 20.500; $shortLiquidation = 30.000;
        // --- just short
        $short = PositionBuilder::short()->symbol($symbol)->entry($shortEntry)->size(10.5)->liq($shortLiquidation)->build();
        $ticker = self::tickerInCheckStopsRange($message, $short);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $short);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, $message, [...$delayed, ...$active], 0.5, 23.35);
        yield self::caseDescription($message, $short, $ticker, $delayed, $active, $expectedStop, 'fixed. part') => [
            $message, $short, $ticker, $delayed, $active, [$expectedStop],
        ];

        // --- short with hedge
        $long = PositionBuilder::long()->symbol($symbol)->entry($shortEntry - 1)->size(2.5)->build();
        $short = PositionBuilder::short()->symbol($symbol)->entry($shortEntry)->size(10.5)->liq($shortLiquidation)->opposite($long)->build();
        $ticker = self::tickerInCheckStopsRange($message, $short);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $short);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, $message, [...$delayed, ...$active], 0.4, 23.35);
        yield self::caseDescription($message, $short, $ticker, $delayed, $active, $expectedStop, 'fixed. part') => [
            $message, $short, $ticker, $delayed, $active, [$expectedStop],
        ];

        # LINKUSDT LONG
        $longEntry = 20.500; $longLiquidation = 15.000;

        // --- just long
        $long = PositionBuilder::long()->symbol($symbol)->entry($longEntry)->size(10.5)->liq($longLiquidation)->build();
        $ticker = self::tickerInCheckStopsRange($message, $long);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.5, 18.85);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'fixed. part') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        // --- long with hedge
        $short = PositionBuilder::short()->symbol($symbol)->entry($longEntry + 1)->size(2.5)->build();
        $long = PositionBuilder::long()->symbol($symbol)->entry($longEntry)->size(10.5)->liq($longLiquidation)->opposite($short)->build();
        $ticker = self::tickerInCheckStopsRange($message, $long);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.4, 18.85);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'fixed. part') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

// fixed applicableStoppedPart END

// (2) dynamic applicableStoppedPart START / position in loss START
// @todo with hedge
// @todo надо добавить грейды size по разным условиям (после entry, перед entry [+по разным расстояниям])

        $delayedStopsPercent = 0.1;
        $pushedStopsPercent = 0.1;
        $symbol = Symbol::BTCUSDT;

        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: $percentOfLiquidationDistanceToAddStop, warningPnlDistance: $warningPnlDistance);
        $longEntry = 35000; $longLiquidation = 25000;
        $long = PositionBuilder::long()->entry($longEntry)->size(1)->liq($longLiquidation)->build();
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];

        # -- BTCUSDT LONG with ticker IN POSITION LOSS (1)
        $ticker = self::tickerInPositionLoss($long, Percent::string('10%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.04, 32000);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'dynamic part / ticker IN POSITION LOSS 1') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        # -- BTCUSDT LONG with ticker IN POSITION LOSS (2)
        $ticker = self::tickerInPositionLoss($long, Percent::string('30%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.04, 32000);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'dynamic part / ticker IN POSITION LOSS 2') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        # -- BTCUSDT LONG with ticker IN POSITION LOSS (3): when ticker is below top bound of range where additional stop must be placed
        $ticker = self::tickerInPositionLoss($long, Percent::string('50%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.092, 30000);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'dynamic part / ticker IN POSITION LOSS 3') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        # -- BTCUSDT LONG with ticker IN POSITION LOSS (4): go deeper ...
        $ticker = self::tickerInPositionLoss($long, Percent::string('70%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.199, 28000);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'dynamic part / ticker IN POSITION LOSS 4') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        # -- BTCUSDT LONG with ticker IN POSITION LOSS (5): and deeper ...
        $ticker = self::tickerInPositionLoss($long, Percent::string('90%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.392, 26000);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'dynamic part / ticker IN POSITION LOSS 5') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        # -- BTCUSDT LONG with ticker IN POSITION LOSS (5): and deeper ... + ticker in warning range
        $ticker = self::tickerInWarningRange($message, $long, Percent::string('10%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.422, 25315);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'dynamic part / ticker IN POSITION LOSS 6 (in warning range)') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];
// dynamic applicableStoppedPart START / position in loss END

// (3) dynamic applicableStoppedPart / liq. right after entry START
// @todo with hedge
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: $percentOfLiquidationDistanceToAddStop, warningPnlDistance: $warningPnlDistance);
        $long = PositionBuilder::long()->entry($longEntry = 30000)->size(1)->liq($longLiquidation = 29999)->build();
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];

        # -- ticker NOT in warn.range
        $ticker = self::tickerInCheckStopsRange($message, $long);
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.038, 30299);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'liq. right after entry / ticker NOT in warn.range') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        # -- ticker IN warn.range
        $ticker = self::tickerInWarningRange($message, $long, Percent::string('10%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.1, 30269);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'liq. right after entry / ticker IN warn.range (1)') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        $ticker = self::tickerInWarningRange($message, $long, Percent::string('20%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.2, 30239);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'liq. right after entry / ticker IN warn.range (2)') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        $ticker = self::tickerInWarningRange($message, $long, Percent::string('55%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.55, 30134.0);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'liq. right after entry / ticker IN warn.range (3)') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

// dynamic applicableStoppedPart / liq. right after entry END

// (4) dynamic applicableStoppedPart / liq. before entry START
// @todo with hedge
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: $percentOfLiquidationDistanceToAddStop, warningPnlDistance: $warningPnlDistance);
        $long = PositionBuilder::long()->entry($entry = 30000)->size(1)->liq($liquidation = 30050)->build();
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];

        # -- ticker NOT in warn.range
        $ticker = self::tickerInCheckStopsRange($message, $long);
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.038, 30350);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'liq. before entry / ticker NOT in warn.range') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        # -- ticker IN warn.range
        $ticker = self::tickerInWarningRange($message, $long, Percent::string('10%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.1, 30320);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'liq. before entry / ticker IN warn.range (1)') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        # -- ticker IN warn.range
        $ticker = self::tickerInWarningRange($message, $long, Percent::string('20%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.2, 30290);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'liq. before entry / ticker IN warn.range (2)') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

        # -- ticker IN warn.range
        $ticker = self::tickerInWarningRange($message, $long, Percent::string('55%'));
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.55, 30185.0);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'liq. before entry / ticker IN warn.range (3)') => [
            $message, $long, $ticker, $delayed, $active, [$expectedStop],
        ];

// dynamic applicableStoppedPart / liq. before entry END


//// other START
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, percentOfLiquidationDistanceToAddStop: $percentOfLiquidationDistanceToAddStop, warningPnlDistance: $warningPnlDistance);

        # -- BTCUSDT LONG with short liq. distance (and position in loss)
        $long = PositionBuilder::long()->entry($entry = 30000)->size(1)->liq($liquidation = 28000)->build();
        $ticker = self::tickerInPositionLoss($long, Percent::string('10%'));
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.129, 29400);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'short liq. distance') => [
            'message' => $message,
            'position' => $long,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];

        # -- BTCUSDT LONG with very long liq. distance (and position in loss)
        $long = PositionBuilder::long()->entry($entry = 100000)->size(1)->liq($liquidation = 30000)->build();
        $ticker = self::tickerInPositionLoss($long, Percent::string('10%'));
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active], 0.013, 79000);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop, 'long liq. distance') => [
            'message' => $message,
            'position' => $long,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];
//// other END
    }

    /**
     * @dataProvider addStopForMultiplePositionsTestCases
     */
    public function testAddStopForMultiplePositions(
        CheckPositionIsUnderLiquidation $message,
        array $allOpenedPositions,
        array $delayedStops,
        array $activeStopOrders,
        array $expectedAdditionalStops,
        bool $debug = false
    ): void {
        AppContext::setIsDebug($debug);

        $this->haveAllOpenedPositionsWithLastMarkPrices($allOpenedPositions);
        $this->haveStopsInDb(...$delayedStops);
        $this->haveActiveConditionalStopsOnMultipleSymbols(...$activeStopOrders);

        $symbol = $allOpenedPositions[array_key_first($allOpenedPositions)]->symbol;
        $this->haveAvailableSpotBalance($symbol, 0.1);

        // Act
        ($this->handler)($message);

        // Arrange
        self::seeStopsInDb(...array_merge($delayedStops, $expectedAdditionalStops));
    }

    public function addStopForMultiplePositionsTestCases(): iterable
    {
        $percentOfLiquidationDistanceToAddStop = 70;
        $warningPnlDistance = 100;

        $acceptableStoppedPart = 15.1;
        $delayedStopsPercent = 4;
        $pushedStopsPercent = 7;

        # BTCUSDT SHORT
        $symbol = Symbol::BTCUSDT;
        $message = new CheckPositionIsUnderLiquidation(
            symbol: $symbol,
            percentOfLiquidationDistanceToAddStop: $percentOfLiquidationDistanceToAddStop,
            acceptableStoppedPart: $acceptableStoppedPart,
            warningPnlDistance: $warningPnlDistance
        );
        $btcUsdtShort = PositionBuilder::short()->entry(35000)->size(0.5)->liq(40000)->build();
        $btcUsdtTicker = self::tickerInCheckStopsRange($message, $btcUsdtShort);
        $existedBtcUsdtStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $btcUsdtShort);
        $delayedBtcUsdtShortStops = [self::delayedStop($btcUsdtShort, $delayedStopsPercent, $existedBtcUsdtStopsPrice)];
        $activeBtcUsdtShortStops = [self::activeCondOrder($btcUsdtShort, $pushedStopsPercent, $existedBtcUsdtStopsPrice)];

        $expectedBtcUsdtStop = self::expectedAdditionalStop($btcUsdtShort, $btcUsdtTicker, $message, [...$delayedBtcUsdtShortStops, ...$activeBtcUsdtShortStops], 0.021, 36500);
//        self::$nextStopId++;

        # LINKUSDT LONG
        $symbol = Symbol::LINKUSDT;
        $message = new CheckPositionIsUnderLiquidation(
            symbol: $symbol,
            percentOfLiquidationDistanceToAddStop: $percentOfLiquidationDistanceToAddStop,
            acceptableStoppedPart: $acceptableStoppedPart,
            warningPnlDistance: $warningPnlDistance
        );
        $linkUsdtLong = PositionBuilder::long()->symbol($symbol)->entry(20.500)->size(10.5)->liq(15.000)->build();
        $linkUsdtTicker = self::tickerInCheckStopsRange($message, $linkUsdtLong);
        $existedLinkUsdtStopsPrice = self::stopPriceThatLeanInAcceptableRange($message, $linkUsdtLong);
        $delayedLinkUsdtStops = [self::delayedStop($linkUsdtLong, $delayedStopsPercent, $existedLinkUsdtStopsPrice)];
        $activeLinkUsdtStops = [self::activeCondOrder($linkUsdtLong, $pushedStopsPercent, $existedLinkUsdtStopsPrice)];
        $expectedLinkUsdtStop = self::expectedAdditionalStop($linkUsdtLong, $linkUsdtTicker, $message, [...$delayedLinkUsdtStops, ...$activeLinkUsdtStops], 0.5, 18.85);

        $commonMessage = new CheckPositionIsUnderLiquidation(
            symbol: null,
            percentOfLiquidationDistanceToAddStop: $percentOfLiquidationDistanceToAddStop,
            acceptableStoppedPart: $acceptableStoppedPart,
            warningPnlDistance: $warningPnlDistance
        );

        yield 'BTCUSDT SHORT + LINKUSDT LONG' => [
            $commonMessage,
            '$allOpenedPositions' => [
                $btcUsdtTicker->markPrice->value() =>  $btcUsdtShort,
                $linkUsdtTicker->markPrice->value() => $linkUsdtLong
            ],
            [...$delayedBtcUsdtShortStops, ...$delayedLinkUsdtStops],
            [...$activeBtcUsdtShortStops, ...$activeLinkUsdtStops],
            [$expectedBtcUsdtStop, $expectedLinkUsdtStop],
        ];
    }

    private static function tickerInCheckStopsRange(CheckPositionIsUnderLiquidation $message, Position $position): Ticker
    {
        $checkStopsOnDistance = CheckLiquidationParametersHelper::checkStopsOnDistance($message, $position);

        return TickerFactory::withMarkSomeBigger(
            $position->symbol,
            $position->isShort() ? $position->liquidationPrice - $checkStopsOnDistance : $position->liquidationPrice + $checkStopsOnDistance,
            Side::Sell
        );
    }

    private static function tickerInWarningRange(CheckPositionIsUnderLiquidation $message, Position $position, ?Percent $passedDistance = null): Ticker
    {
        $distancePnl = CheckLiquidationParametersHelper::warningDistancePnl($message);
        $pnlDistanceWithLiquidation = $distancePnl - ($passedDistance ? $passedDistance->value() : 0);
        $warningDistance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($pnlDistanceWithLiquidation, $position->entryPrice()), 0.1);

        return TickerFactory::withMarkSomeBigger(
            $position->symbol,
            $position->isShort() ? $position->liquidationPrice - $warningDistance : $position->liquidationPrice + $warningDistance,
            Side::Sell
        );
    }

    private static function tickerInPositionLoss(Position $position, ?Percent $passedDistance = null): Ticker
    {
        $initialLiqDistance = $position->liquidationDistance();
        $distanceWithLiquidation = $initialLiqDistance - ($passedDistance ? $passedDistance->of($initialLiqDistance) : 0);

        return TickerFactory::withMarkSomeBigger(
            $position->symbol,
            $position->isShort() ? $position->liquidationPrice - $distanceWithLiquidation : $position->liquidationPrice + $distanceWithLiquidation,
            Side::Sell
        );
    }

    private function stopPriceThatLeanInAcceptableRange(CheckPositionIsUnderLiquidation $message, Position $position): Price
    {
        $actualStopsRange = CheckLiquidationParametersHelper::actualStopsRange($message, $position);
        $someDelta = Percent::string('2%')->of($position->liquidationDistance());

        return $position->isShort() ? $actualStopsRange->to()->sub($someDelta) : $actualStopsRange->from()->add($someDelta);
    }

    private static int $nextStopId = 1;
    private static function delayedStop(Position $position, float $positionSizePart, Price|float $price): Stop
    {
        $size = $position->getNotCoveredSize();

        return new Stop(self::$nextStopId++, Price::toFloat($price), $position->symbol->roundVolume((new Percent($positionSizePart))->of($size)), 10, $position->symbol, $position->side);
    }
    private static function activeCondOrder(Position $position, float $positionSizePart, Price|float $price): ActiveStopOrder
    {
        return new ActiveStopOrder(
            $position->symbol,
            $position->side,
            uuid_create(),
            $position->symbol->roundVolume((new Percent($positionSizePart))->of($position->getNotCoveredSize())),
            Price::toFloat($price),
            TriggerBy::IndexPrice->value,
        );
    }

    private static function expectedAdditionalStop(
        Position $position,
        Ticker $ticker,
        CheckPositionIsUnderLiquidation $message,
        array $existedStops,
        ?float $withVolume = null,
        ?float $onPrice = null,
    ): Stop {
        $size = $position->getNotCoveredSize();

        $lastId = 1;
        $stopped = 0;
        foreach ($existedStops as $stop) {
            /** @var ActiveStopOrder|Stop $stop */
            $stopped += $stop instanceof Stop ? $stop->getVolume() : $stop->volume;
            if ($stop instanceof Stop) {
                $lastId = $stop->getId();
            }
        }

        $expectedStopSize = CheckLiquidationParametersHelper::acceptableStoppedPart($message) - Percent::fromPart($stopped / $size, false)->value();
        $additionalStopDistanceWithLiquidation = CheckLiquidationParametersHelper::additionalStopDistanceWithLiquidation($message, $position);
//        $additionalStopTriggerDelta = $additionalStopDistanceWithLiquidation > 500 ? self::ADDITIONAL_STOP_TRIGGER_SHORT_DELTA : self::ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA;

        // @todo | move to helper
        $stopDistanceWithLiquidation = min(
            $position->priceDistanceWithLiquidation($ticker),
            $additionalStopDistanceWithLiquidation
        );

        $volume = $withVolume ?? $position->symbol->roundVolumeUp((new Percent($expectedStopSize))->of($size));
        $price = $onPrice ?? (
            ($position->isShort() ? $position->liquidationPrice()->sub($stopDistanceWithLiquidation) : $position->liquidationPrice()->add($stopDistanceWithLiquidation))->value()
        );
        $triggerDelta = CheckLiquidationParametersHelper::additionalStopTriggerDelta($position->symbol);

        return new Stop(
            $lastId + 1,
            $price,
            $volume,
            $triggerDelta,
            $position->symbol,
            $position->side,
            [
                Stop::IS_ADDITIONAL_STOP_FROM_LIQUIDATION_HANDLER => true,
                Stop::CLOSE_BY_MARKET_CONTEXT => true,
            ],
        );
    }

    private static function caseDescription(
        CheckPositionIsUnderLiquidation $message,
        Position $mainPosition,
        Ticker $ticker,
        array $delayedStops,
        array $activeExchangeStops,
        Stop $expectedStop,
        ?string $additionalInfo = null
    ): string {
        $delayedStopsPositionSizePart = self::positionNotCoveredSizePart((new StopsCollection(...$delayedStops))->totalVolume(), $mainPosition);
        $activeConditionalOrdersSizePart = self::positionNotCoveredSizePart(
            array_sum(array_map(static fn(ActiveStopOrder $activeStopOrder) => $activeStopOrder->volume, $activeExchangeStops)),
            $mainPosition,
        );

        $needToCoverPercent = CheckLiquidationParametersHelper::acceptableStoppedPart($message) - $delayedStopsPositionSizePart - $activeConditionalOrdersSizePart;

        return sprintf(
            '[%s%s] / ticker.markPrice = %.2f) | stopped %.2f%% => need to cover %.2f%% => add %s on %s',
            $additionalInfo ? sprintf('%s / ', $additionalInfo) : '',
            TestCaseDescriptionHelper::getFullPositionCaption($mainPosition),
            $ticker->markPrice->value(),
            $delayedStopsPositionSizePart + $activeConditionalOrdersSizePart,
            $needToCoverPercent,
            $expectedStop->getVolume(),
            $expectedStop->getPrice(),
        );
    }

    private static function positionNotCoveredSizePart(float $volume, Position $position): int|float
    {
        return round(($volume / $position->getNotCoveredSize()) * 100, 3);
    }
}
