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
 *
 * @group liquidation
 */
class AddStopWhenPositionLiquidationInWarningRangeTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;
    use ByBitV5ApiRequestsMocker;

    private const CHECK_STOPS_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::CHECK_STOPS_ON_DISTANCE;
    private const ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION = CheckPositionIsUnderLiquidationHandler::ACCEPTABLE_STOPPED_PART;

    private const ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION = CheckPositionIsUnderLiquidationHandler::ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION;
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
        ($this->handler)(new CheckPositionIsUnderLiquidation($symbol));

        // Arrange
        self::seeStopsInDb(...array_merge($delayedStops, $expectedAdditionalStops));
    }

    public function addStopTestCases(): iterable
    {
        ### BTCUSDT ###
        $markPrice = 35000;
        $ticker = TickerFactory::withMarkSomeBigger($symbol = Symbol::BTCUSDT, $markPrice, Side::Sell);
        $checkStopsOnDistance = CheckLiquidationParametersHelper::checkStopsDistance($ticker);

        # SHORT
        $liquidationPrice = $symbol->makePrice($markPrice + $checkStopsOnDistance);
        $delayedStopsPercent = 4; $pushedStopsPercent = 7;

        // --- just short
        $short = PositionBuilder::short()->entry($markPrice)->size(0.5)->liq($liquidationPrice)->build();
        $existedStopsPrice = PriceTestHelper::middleBetween($short->liquidationPrice(), $ticker->indexPrice);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, [...$delayed, ...$active]);
        yield self::caseDescription($short, $ticker, $delayed, $active, $expectedStop) => [
            'position' => $short, 'ticker' => $ticker, 'delayedStops' => $delayed, 'activeExchangeConditionalStops' => $active, 'expectedAdditionalStops' => [$expectedStop],
        ];

        // --- short with hedge
        $long = PositionBuilder::long()->entry($markPrice - 10000)->size(0.11)->build();
        $short = PositionBuilder::short()->entry($markPrice)->size(0.5)->liq($liquidationPrice)->opposite($long)->build();
        $existedStopsPrice = PriceTestHelper::middleBetween($short->liquidationPrice(), $ticker->indexPrice);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, [...$delayed, ...$active]);
        yield self::caseDescription($short, $ticker, $delayed, $active, $expectedStop) => [
            'position' => $short, 'ticker' => $ticker, 'delayedStops' => $delayed, 'activeExchangeConditionalStops' => $active, 'expectedAdditionalStops' => [$expectedStop]
        ];

        # LONG
        $liquidationPrice = $symbol->makePrice($markPrice - $checkStopsOnDistance);
        $delayedStopsPercent = 4; $pushedStopsPercent = 7;

        // -- just long
        $long = PositionBuilder::long()->entry($markPrice)->size(0.2)->liq($liquidationPrice)->build();
        $existedStopsPrice = PriceTestHelper::middleBetween($long->liquidationPrice(), $ticker->indexPrice);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, [...$delayed, ...$active]);
        yield self::caseDescription($long, $ticker, $delayed, $active, $expectedStop) => [
            'position' => $long, 'ticker' => $ticker, 'delayedStops' => $delayed, 'activeExchangeConditionalStops' => $active, 'expectedAdditionalStops' => [$expectedStop]
        ];

        // -- long with hedge
        $short = PositionBuilder::short()->entry($markPrice + 10000)->size(0.11)->build();
        $long = PositionBuilder::long()->entry($markPrice)->size(0.5)->liq($liquidationPrice)->opposite($short)->build();
        $existedStopsPrice = PriceTestHelper::middleBetween($long->liquidationPrice(), $ticker->indexPrice);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, [...$delayed, ...$active]);
        yield self::caseDescription($long, $ticker, $delayed, $active, $expectedStop) => [
            'position' => $long, 'ticker' => $ticker, 'delayedStops' => $delayed, 'activeExchangeConditionalStops' => $active, 'expectedAdditionalStops' => [$expectedStop],
        ];

        ### LINKUSDT ###
        $symbol = Symbol::LINKUSDT;
        $ticker = TickerFactory::withMarkSomeBigger($symbol, $markPrice = 29.500, Side::Sell);
        $checkStopsOnDistance = CheckLiquidationParametersHelper::checkStopsDistance($ticker);

        # SHORT
        $liquidationPrice = $symbol->makePrice($markPrice + $checkStopsOnDistance);
        $delayedStopsPercent = 4; $pushedStopsPercent = 7;

        // --- just short
        $short = PositionBuilder::short()->symbol($symbol)->entry($markPrice)->size(10.5)->liq($liquidationPrice)->build();
        $existedStopsPrice = PriceTestHelper::middleBetween($short->liquidationPrice(), $ticker->indexPrice);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, [...$delayed, ...$active]);
        yield self::caseDescription($short, $ticker, $delayed, $active, $expectedStop) => [
            'position' => $short, 'ticker' => $ticker, 'delayedStops' => $delayed, 'activeExchangeConditionalStops' => $active, 'expectedAdditionalStops' => [$expectedStop],
        ];

        // --- short with hedge
        $long = PositionBuilder::long()->symbol($symbol)->entry($markPrice - 1)->size(2.5)->build();
        $short = PositionBuilder::short()->symbol($symbol)->entry($markPrice)->size(10.5)->liq($liquidationPrice)->opposite($long)->build();
        $existedStopsPrice = PriceTestHelper::middleBetween($short->liquidationPrice(), $ticker->indexPrice);
        $delayed = [self::delayedStop($short, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($short, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($short, $ticker, [...$delayed, ...$active]);
        yield self::caseDescription($short, $ticker, $delayed, $active, $expectedStop) => [
            'position' => $short, 'ticker' => $ticker, 'delayedStops' => $delayed, 'activeExchangeConditionalStops' => $active, 'expectedAdditionalStops' => [$expectedStop],
        ];

        # SHORT
        $liquidationPrice = $symbol->makePrice($markPrice - $checkStopsOnDistance);
        $delayedStopsPercent = 4; $pushedStopsPercent = 7;

        // --- just long
        $long = PositionBuilder::long()->symbol($symbol)->entry($markPrice)->size(10.5)->liq($liquidationPrice)->build();
        $existedStopsPrice = PriceTestHelper::middleBetween($long->liquidationPrice(), $ticker->indexPrice);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, [...$delayed, ...$active]);
        yield self::caseDescription($long, $ticker, $delayed, $active, $expectedStop) => [
            'position' => $long, 'ticker' => $ticker, 'delayedStops' => $delayed, 'activeExchangeConditionalStops' => $active, 'expectedAdditionalStops' => [$expectedStop],
        ];

        // --- short with hedge
        $short = PositionBuilder::short()->symbol($symbol)->entry($markPrice + 1)->size(2.5)->build();
        $long = PositionBuilder::long()->symbol($symbol)->entry($markPrice)->size(10.5)->liq($liquidationPrice)->opposite($short)->build();

        $existedStopsPrice = PriceTestHelper::middleBetween($long->liquidationPrice(), $ticker->indexPrice);
        $delayed = [self::delayedStop($long, $delayedStopsPercent, $existedStopsPrice)];
        $active = [self::activeCondOrder($long, $pushedStopsPercent, $existedStopsPrice)];
        $expectedStop = self::expectedAdditionalStop($long, $ticker, [...$delayed, ...$active]);
        yield self::caseDescription($long, $ticker, $delayed, $active, $expectedStop) => [
            'position' => $long, 'ticker' => $ticker, 'delayedStops' => $delayed, 'activeExchangeConditionalStops' => $active, 'expectedAdditionalStops' => [$expectedStop],
        ];
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

    private static function expectedAdditionalStop(Position $position, Ticker $ticker, array $existedStops): Stop
    {
        $size = $position->getNotCoveredSize();

        $stopped = 0;
        foreach ($existedStops as $stop) {
            /** @var ActiveStopOrder|Stop $stop */
            $stopped += $stop instanceof Stop ? $stop->getVolume() : $stop->volume;
        }

        $expectedStopSize = self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION - Percent::fromPart($stopped / $size)->value();
        $distanceWithLiquidation = CheckLiquidationParametersHelper::additionalStopDistanceWithLiquidation($ticker);
//        $additionalStopTriggerDelta = $additionalStopDistanceWithLiquidation > 500 ? self::ADDITIONAL_STOP_TRIGGER_SHORT_DELTA : self::ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA;

        return new Stop(
            self::$nextStopId++,
            ($position->isShort() ? $position->liquidationPrice()->sub($distanceWithLiquidation) : $position->liquidationPrice()->add($distanceWithLiquidation))->value(),
            $position->symbol->roundVolumeUp((new Percent($expectedStopSize))->of($size)),
            CheckLiquidationParametersHelper::additionalStopTriggerDelta($position->symbol),
            $position->symbol,
            $position->side,
        );
    }

    private static function caseDescription(Position $mainPosition, Ticker $ticker, array $delayedStops, array $activeExchangeStops, Stop $expectedStop): string
    {
        $delayedStopsPositionSizePart = self::positionNotCoveredSizePart((new StopsCollection(...$delayedStops))->totalVolume(), $mainPosition);
        $activeConditionalOrdersSizePart = self::positionNotCoveredSizePart(
            array_sum(array_map(static fn(ActiveStopOrder $activeStopOrder) => $activeStopOrder->volume, $activeExchangeStops)),
            $mainPosition
        );

        $needToCoverPercent = self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION - $delayedStopsPositionSizePart - $activeConditionalOrdersSizePart;

        return sprintf(
            '[%s] in warning range (ticker.markPrice = %.2f) | stopped %.2f%% => need to cover %.2f%% => add %s on %s',
            TestCaseDescriptionHelper::getPositionCaption($mainPosition),
            $ticker->markPrice->value(),
            $delayedStopsPositionSizePart + $activeConditionalOrdersSizePart,
            $needToCoverPercent,
            $expectedStop->getVolume(),
            $expectedStop->getPrice()
        );
    }

    private static function positionNotCoveredSizePart(float $volume, Position $position): int|float
    {
        return round(($volume / $position->getNotCoveredSize()) * 100, 3);
    }
}
