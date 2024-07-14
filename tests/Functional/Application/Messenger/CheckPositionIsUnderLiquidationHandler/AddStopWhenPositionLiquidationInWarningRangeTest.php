<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Messenger\CheckPositionIsUnderLiquidationHandler;

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
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function array_merge;
use function array_sum;
use function round;
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
     * @param Position[] $positions
     *
     * @dataProvider addStopTestCases
     */
    public function testAddStop(
        array $positions,
        Position $mainPosition,
        Ticker $ticker,
        array $delayedStops,
        array $activeConditionalStops,
        array $expectedAdditionalStops
    ): void {
        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, ...$positions);
        $this->haveAvailableSpotBalance($mainPosition->symbol, 0.1);

        $this->haveStopsInDb(...$delayedStops);
        $this->haveActiveConditionalStops($mainPosition->symbol, ...$activeConditionalStops);

        // Act
        ($this->handler)(new CheckPositionIsUnderLiquidation($mainPosition->symbol));

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
        $shortPosition = PositionFactory::short($symbol, $markPrice, 0.5, 100, $liquidationPrice);
        $positions = [$shortPosition];

        $additionalStopPrice = PriceHelper::round($liquidationPrice - $additionalStopDistanceWithLiquidation);
        $delayedStopsPercent = 3; $pushedStopsPercent = 5;
        $needToCoverPercent = self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION - $delayedStopsPercent - $pushedStopsPercent;
        yield sprintf(
            '[BTCUSDT SHORT without hedge] liquidationPrice (=%.2f) in warning range (ticker.markPrice = %.2f) | stopped %.2f%% => need to cover %.2f%%',
            $liquidationPrice, $markPrice, $delayedStopsPercent + $pushedStopsPercent, $needToCoverPercent
        ) => [
            'positions' => $positions,
            'mainPosition' => $shortPosition,
            'ticker' => $ticker,
            'delayedStops' => [self::delayedStop($shortPosition, new Percent($delayedStopsPercent), $ticker->indexPrice->value() + 10)],
            'activeExchangeConditionalStops' => [self::activeCondOrder($shortPosition, new Percent($pushedStopsPercent), $ticker->indexPrice->value() + 20)],
            'expectedAdditionalStops' => [self::delayedStop($shortPosition, new Percent($needToCoverPercent), $additionalStopPrice)->setTriggerDelta($additionalStopTriggerDelta)]
        ];

        # BTCUSDT LONG
        $liquidationPrice = $markPrice - self::CHECK_STOPS_ON_DISTANCE;
        $longPosition = PositionFactory::long($symbol, $markPrice, 0.2, 100, $liquidationPrice);
        $positions = [$longPosition];

        $additionalStopPrice = PriceHelper::round($liquidationPrice + $additionalStopDistanceWithLiquidation);
        $delayedStopsPercent = 6; $pushedStopsPercent = 4;
        $needToCoverPercent = self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION - $delayedStopsPercent - $pushedStopsPercent;
        yield sprintf(
            '[BTCUSDT LONG without hedge] liquidationPrice (=%.2f) in warning range (ticker.markPrice = %.2f) | stopped %.2f%% => need to cover %.2f%%',
            $liquidationPrice, $markPrice, $delayedStopsPercent + $pushedStopsPercent, $needToCoverPercent
        ) => [
            'positions' => $positions,
            'mainPosition' => $longPosition,
            'ticker' => $ticker,
            'delayedStops' => [self::delayedStop($longPosition, new Percent($delayedStopsPercent), $ticker->indexPrice->value() - 10)],
            'activeExchangeConditionalStops' => [self::activeCondOrder($longPosition, new Percent($pushedStopsPercent), $ticker->indexPrice->value() - 20)],
            'expectedAdditionalStops' => [self::delayedStop($longPosition, new Percent($needToCoverPercent), $additionalStopPrice)->setTriggerDelta($additionalStopTriggerDelta)]
        ];
    }

    private static int $nextStopId = 1;
    private static function delayedStop(Position $position, Percent $positionSizePart, float $price): Stop
    {
        return new Stop(self::$nextStopId++, $price, VolumeHelper::round($positionSizePart->of($position->size)), 10, $position->side);
    }

    private static function activeCondOrder(Position $position, Percent $positionSizePart, float $price): ActiveStopOrder
    {
        return new ActiveStopOrder($position->symbol, $position->side, uuid_create(), VolumeHelper::round($positionSizePart->of($position->size)), $price, TriggerBy::IndexPrice->value);
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

    public function testDummy(): void
    {
        self::markTestIncomplete('add addStopTestCases: when position is under hedge');
    }
}
