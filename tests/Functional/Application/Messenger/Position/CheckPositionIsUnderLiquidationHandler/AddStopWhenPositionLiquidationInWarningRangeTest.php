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
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Helper\CheckLiquidationParametersHelper;
use App\Tests\Helper\PriceTestHelper;
use App\Tests\Helper\Tests\TestCaseDescriptionHelper;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
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
    ): void {
        $symbol = $position->symbol;
        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, $position);
        $this->haveAvailableSpotBalance($symbol, 0.1);

        $this->haveStopsInDb(...$delayedStops);
        $this->haveActiveConditionalStops($symbol, ...$activeConditionalStops);

        // Act
        ($this->handler)($message);

        // Arrange
        self::seeStopsInDb(...array_merge($delayedStops, $expectedAdditionalStops));
    }

    public function addStopTestCases(): iterable
    {
        $acceptableStoppedPart = 15;
        $delayedStopsPercent = 4;
        $pushedStopsPercent = 7;

        ### BTCUSDT ###
        $symbol = Symbol::BTCUSDT;
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, acceptableStoppedPart: $acceptableStoppedPart);

        # SHORT
        $shortEntry = 35000; $shortLiquidation = 40000;
        // --- just short
        $short = PositionBuilder::short()->entry($shortEntry)->size(0.5)->liq($shortLiquidation)->build();
        $ticker = self::tickerInWarningRange($short);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($short);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, $message, [...$delayed, ...$active]);

        yield self::caseDescription($message, $short, $ticker, $delayed, $active, $expectedStop) => [
            'message' => $message,
            'position' => $short,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];

        // --- short with hedge
        $long = PositionBuilder::long()->entry($shortEntry - 10000)->size(0.11)->build();
        $short = PositionBuilder::short()->entry($shortEntry)->size(0.5)->liq($shortLiquidation)->opposite($long)->build();
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($short);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, $message, [...$delayed, ...$active]);
        yield self::caseDescription($message, $short, $ticker, $delayed, $active, $expectedStop) => [
            'message' => $message,
            'position' => $short,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];

        # LONG
        $longEntry = 35000; $longLiquidation = 25000;
        // -- just long
        $long = PositionBuilder::long()->entry($longEntry)->size(0.5)->liq($longLiquidation)->build();
        $ticker = self::tickerInWarningRange($long);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active]);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop) => [
            'message' => $message,
            'position' => $long,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];

        // -- long with hedge
        $short = PositionBuilder::short()->entry($longEntry + 10000)->size(0.11)->build();
        $long = PositionBuilder::long()->entry($longEntry)->size(0.5)->liq($longLiquidation)->opposite($short)->build();
        $ticker = self::tickerInWarningRange($long);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active]);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop) => [
            'message' => $message,
            'position' => $long,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];

        ### LINKUSDT ###
        $symbol = Symbol::LINKUSDT;
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol, acceptableStoppedPart: $acceptableStoppedPart);

        # SHORT
        $shortEntry = 20.500; $shortLiquidation = 30.000;
        // --- just short
        $short = PositionBuilder::short()->symbol($symbol)->entry($shortEntry)->size(10.5)->liq($shortLiquidation)->build();
        $ticker = self::tickerInWarningRange($short);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($short);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, $message, [...$delayed, ...$active]);
        yield self::caseDescription($message, $short, $ticker, $delayed, $active, $expectedStop) => [
            'message' => $message,
            'position' => $short,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];

        // --- short with hedge
        $long = PositionBuilder::long()->symbol($symbol)->entry($shortEntry - 1)->size(2.5)->build();
        $short = PositionBuilder::short()->symbol($symbol)->entry($shortEntry)->size(10.5)->liq($shortLiquidation)->opposite($long)->build();
        $ticker = self::tickerInWarningRange($short);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($short);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, $message, [...$delayed, ...$active]);
        yield self::caseDescription($message, $short, $ticker, $delayed, $active, $expectedStop) => [
            'message' => $message,
            'position' => $short,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];

        # LONG
        $longEntry = 20.500; $longLiquidation = 15.000;

        // --- just long
        $long = PositionBuilder::long()->symbol($symbol)->entry($longEntry)->size(10.5)->liq($longLiquidation)->build();
        $ticker = self::tickerInWarningRange($long);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active]);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop) => [
            'message' => $message,
            'position' => $long,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];

        // --- long with hedge
        $short = PositionBuilder::short()->symbol($symbol)->entry($longEntry + 1)->size(2.5)->build();
        $long = PositionBuilder::long()->symbol($symbol)->entry($longEntry)->size(10.5)->liq($longLiquidation)->opposite($short)->build();
        $ticker = self::tickerInWarningRange($long);
        $existedStopsPrice = self::stopPriceThatLeanInAcceptableRange($long);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, $message, [...$delayed, ...$active]);
        yield self::caseDescription($message, $long, $ticker, $delayed, $active, $expectedStop) => [
            'message' => $message,
            'position' => $long,
            'ticker' => $ticker,
            'delayedStops' => $delayed,
            'activeExchangeConditionalStops' => $active,
            'expectedAdditionalStops' => [$expectedStop],
        ];
    }

    private static function tickerInWarningRange(Position $position): Ticker
    {
        $checkStopsOnDistance = CheckLiquidationParametersHelper::checkStopsOnDistance($position);

        return TickerFactory::withMarkSomeBigger(
            $position->symbol,
            $position->isShort() ? $position->liquidationPrice - $checkStopsOnDistance : $position->liquidationPrice + $checkStopsOnDistance,
            Side::Sell
        );
    }

    private function stopPriceThatLeanInAcceptableRange(Position $position): Price
    {
        $actualStopsRange = CheckLiquidationParametersHelper::actualStopsRange($position);
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
        array $existedStops
    ): Stop {
        $size = $position->getNotCoveredSize();

        $stopped = 0;
        foreach ($existedStops as $stop) {
            /** @var ActiveStopOrder|Stop $stop */
            $stopped += $stop instanceof Stop ? $stop->getVolume() : $stop->volume;
        }

        $expectedStopSize = CheckLiquidationParametersHelper::acceptableStoppedPart($message) - Percent::fromPart($stopped / $size)->value();
        $distanceWithLiquidation = CheckLiquidationParametersHelper::additionalStopDistanceWithLiquidation($position);
//        $additionalStopTriggerDelta = $additionalStopDistanceWithLiquidation > 500 ? self::ADDITIONAL_STOP_TRIGGER_SHORT_DELTA : self::ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA;

        return new Stop(
            self::$nextStopId++,
            ($position->isShort() ? $position->liquidationPrice()->sub($distanceWithLiquidation) : $position->liquidationPrice()->add($distanceWithLiquidation))->value(),
            $position->symbol->roundVolumeUp((new Percent($expectedStopSize))->of($size)),
            CheckLiquidationParametersHelper::additionalStopTriggerDelta($position->symbol),
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
        Stop $expectedStop
    ): string {
        $delayedStopsPositionSizePart = self::positionNotCoveredSizePart((new StopsCollection(...$delayedStops))->totalVolume(), $mainPosition);
        $activeConditionalOrdersSizePart = self::positionNotCoveredSizePart(
            array_sum(array_map(static fn(ActiveStopOrder $activeStopOrder) => $activeStopOrder->volume, $activeExchangeStops)),
            $mainPosition,
        );

        $needToCoverPercent = CheckLiquidationParametersHelper::acceptableStoppedPart($message) - $delayedStopsPositionSizePart - $activeConditionalOrdersSizePart;

        return sprintf(
            '[%s] in warning range (ticker.markPrice = %.2f) | stopped %.2f%% => need to cover %.2f%% => add %s on %s',
            TestCaseDescriptionHelper::getPositionCaption($mainPosition),
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
