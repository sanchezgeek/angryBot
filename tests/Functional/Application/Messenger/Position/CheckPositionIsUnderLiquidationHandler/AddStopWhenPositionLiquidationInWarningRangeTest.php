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
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Helper\VolumeHelper;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function array_merge;
use function array_sum;
use function assert;
use function in_array;
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

    private const CHECK_STOPS_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::CHECK_STOPS_ON_DISTANCE;
    private const ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION = CheckPositionIsUnderLiquidationHandler::ACCEPTABLE_STOPPED_PART;

    private const ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION = CheckPositionIsUnderLiquidationHandler::ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION;
    private const ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA = CheckPositionIsUnderLiquidationHandler::ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA;
    private const ADDITIONAL_STOP_TRIGGER_SHORT_DELTA = CheckPositionIsUnderLiquidationHandler::ADDITIONAL_STOP_TRIGGER_SHORT_DELTA;

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
        array $expectedAdditionalStops
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
        # CONST
        $additionalStopDistanceWithLiquidation = self::ADDITIONAL_STOP_DISTANCE_WITH_LIQUIDATION;
        $additionalStopTriggerDelta = $additionalStopDistanceWithLiquidation > 500 ? self::ADDITIONAL_STOP_TRIGGER_SHORT_DELTA : self::ADDITIONAL_STOP_TRIGGER_DEFAULT_DELTA;

        $symbol = Symbol::BTCUSDT;
        $markPrice = 35000;
        $ticker = TickerFactory::create($symbol, $markPrice - 20, $markPrice, $markPrice - 20);

        # BTCUSDT SHORT
        $liquidationPrice = $markPrice + self::CHECK_STOPS_ON_DISTANCE;
        $additionalStopPrice = PriceHelper::round($liquidationPrice - $additionalStopDistanceWithLiquidation);
        $delayedStopsPercent = 4; $pushedStopsPercent = 7;
        $needToCoverPercent = self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION - $delayedStopsPercent - $pushedStopsPercent;

        $short = PositionBuilder::short()->entry($markPrice)->size(0.5)->liq($liquidationPrice)->build();
        yield sprintf(
            '[BTCUSDT SHORT] liquidationPrice (=%.2f) in warning range (ticker.markPrice = %.2f) | stopped %.2f%% => need to cover %.2f%%',
            $liquidationPrice, $markPrice, $delayedStopsPercent + $pushedStopsPercent, $needToCoverPercent
        ) => [
            'position' => $short,
            'ticker' => $ticker,
            'delayedStops' => [self::delayedStop($short, $delayedStopsPercent, $ticker->indexPrice->value() + 10)],
            'activeExchangeConditionalStops' => [self::activeCondOrder($short, $pushedStopsPercent, $ticker->indexPrice->value() + 20)],
            'expectedAdditionalStops' => [self::delayedStop($short, $needToCoverPercent, $additionalStopPrice)->setTriggerDelta($additionalStopTriggerDelta)]
        ];

        $long = PositionBuilder::long()->entry($markPrice - 10000)->size(0.11)->build();
        $short = PositionBuilder::short()->entry($markPrice)->size(0.5)->liq($liquidationPrice)->opposite($long)->build();
        yield sprintf(
            '[BTCUSDT SHORT %.3f vs BTCUSDT LONG %.3f] liquidationPrice (=%.2f) in warning range (ticker.markPrice = %.2f) | stopped %.2f%% => need to cover %.2f%%',
            $short->size, $long->size, $liquidationPrice, $markPrice, $delayedStopsPercent + $pushedStopsPercent, $needToCoverPercent
        ) => [
            'position' => $short,
            'ticker' => $ticker,
            'delayedStops' => [self::delayedStop($short, $delayedStopsPercent, $ticker->indexPrice->value() + 10, 'notCovered')],
            'activeExchangeConditionalStops' => [self::activeCondOrder($short, $pushedStopsPercent, $ticker->indexPrice->value() + 20)],
            'expectedAdditionalStops' => [self::delayedStop($short, $needToCoverPercent, $additionalStopPrice, 'notCovered')->setTriggerDelta($additionalStopTriggerDelta)]
        ];

        # BTCUSDT LONG
        $liquidationPrice = $markPrice - self::CHECK_STOPS_ON_DISTANCE;
        $additionalStopPrice = PriceHelper::round($liquidationPrice + $additionalStopDistanceWithLiquidation);
        $delayedStopsPercent = 6; $pushedStopsPercent = 4;
        $needToCoverPercent = self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION - $delayedStopsPercent - $pushedStopsPercent;

        $long = PositionBuilder::long()->entry($markPrice)->size(0.2)->liq($liquidationPrice)->build();
        yield sprintf(
            '[BTCUSDT LONG] liquidationPrice (=%.2f) in warning range (ticker.markPrice = %.2f) | stopped %.2f%% => need to cover %.2f%%',
            $liquidationPrice, $markPrice, $delayedStopsPercent + $pushedStopsPercent, $needToCoverPercent
        ) => [
            'position' => $long,
            'ticker' => $ticker,
            'delayedStops' => [self::delayedStop($long, $delayedStopsPercent, $ticker->indexPrice->value() - 10)],
            'activeExchangeConditionalStops' => [self::activeCondOrder($long, $pushedStopsPercent, $ticker->indexPrice->value() - 20)],
            'expectedAdditionalStops' => [self::delayedStop($long, $needToCoverPercent, $additionalStopPrice)->setTriggerDelta($additionalStopTriggerDelta)]
        ];

        $short = PositionBuilder::short()->entry($markPrice + 10000)->size(0.11)->build();
        $long = PositionBuilder::long()->entry($markPrice)->size(0.5)->liq($liquidationPrice)->opposite($short)->build();
        yield sprintf(
            '[BTCUSDT LONG %.3f vs BTCUSDT SHORT %.3f] liquidationPrice (=%.2f) in warning range (ticker.markPrice = %.2f) | stopped %.2f%% => need to cover %.2f%%',
            $long->size, $short->size, $liquidationPrice, $markPrice, $delayedStopsPercent + $pushedStopsPercent, $needToCoverPercent
        ) => [
            'position' => $long,
            'ticker' => $ticker,
            'delayedStops' => [self::delayedStop($long, $delayedStopsPercent, $ticker->indexPrice->value() - 10, 'notCovered')],
            'activeExchangeConditionalStops' => [self::activeCondOrder($long, $pushedStopsPercent, $ticker->indexPrice->value() - 20)],
            'expectedAdditionalStops' => [self::delayedStop($long, $needToCoverPercent, $additionalStopPrice, 'notCovered')->setTriggerDelta($additionalStopTriggerDelta)]
        ];
    }

    private static int $nextStopId = 1;
    private static function delayedStop(Position $position, float $positionSizePart, float $price, string $part = 'whole'): Stop
    {
        assert(in_array($part, ['whole', 'notCovered']));
        $size = $part === 'whole' ? $position->size : $position->getNotCoveredSize();

        return new Stop(self::$nextStopId++, $price, VolumeHelper::round((new Percent($positionSizePart))->of($size)), 10, $position->side);
    }

    private static function activeCondOrder(Position $position, float $positionSizePart, float $price): ActiveStopOrder
    {
        return new ActiveStopOrder(
            $position->symbol,
            $position->side,
            uuid_create(),
            VolumeHelper::round((new Percent($positionSizePart))->of($position->getNotCoveredSize())),
            $price,
            TriggerBy::IndexPrice->value,
        );
    }

    private static function positionSizePart(float $volume, Position $position): int|float
    {
        return round(($volume / $position->size) * 100, 3);
    }

    /**
     * @param Stop[] $delayedStops
     * @param Position $position
     * @param ActiveStopOrder[] $activeExchangeStops
     *
     * @return Percent
     */
    public static function additionalStopExpectedPositionSizePart(array $delayedStops, Position $position, array $activeExchangeStops): Percent
    {
        $delayedStopsPositionSizePart = self::positionSizePart((new StopsCollection(...$delayedStops))->totalVolume(), $position);
        $activeConditionalOrdersSizePart = self::positionSizePart(
            array_sum(array_map(static fn(ActiveStopOrder $activeStopOrder) => $activeStopOrder->volume, $activeExchangeStops)),
            $position
        );

        // Acceptable stops position size part - total stops position size part
        return new Percent(self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION - $delayedStopsPositionSizePart - $activeConditionalOrdersSizePart);
    }
}
