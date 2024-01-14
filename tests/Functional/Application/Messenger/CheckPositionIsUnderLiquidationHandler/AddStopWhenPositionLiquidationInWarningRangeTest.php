<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Messenger\CheckPositionIsUnderLiquidationHandler;

use App\Application\Messenger\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\CheckPositionIsUnderLiquidationHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Stop\StopsCollection;
use App\Domain\Value\Percent\Percent;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function array_merge;
use function array_sum;
use function round;
use function uuid_create;

/**
 * @group liquidation
 */
class AddStopWhenPositionLiquidationInWarningRangeTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;

    private const WARNING_LIQUIDATION_DELTA = CheckPositionIsUnderLiquidationHandler::WARNING_DELTA;
    private const ACCEPTABLE_STOPS_POSITION_SIZE_PART_BEFORE_CRITICAL_RANGE = CheckPositionIsUnderLiquidationHandler::ACCEPTABLE_POSITION_STOPS_PART_BEFORE_CRITICAL_RANGE;

    private const ADDITIONAL_STOP_MIN_DELTA_WITH_POSITION_LIQUIDATION = CheckPositionIsUnderLiquidationHandler::STOP_CRITICAL_DELTA_BEFORE_LIQUIDATION;
    private const ADDITIONAL_STOP_TRIGGER_DELTA = CheckPositionIsUnderLiquidationHandler::ADDITIONAL_STOP_TRIGGER_DELTA;

    private static int $nextStopId = 1;

    protected ExchangeServiceInterface $exchangeServiceMock;
    protected PositionServiceInterface $positionServiceStub;

    private CheckPositionIsUnderLiquidationHandler $handler;

    protected function setUp(): void
    {
        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        self::getContainer()->set(ExchangeServiceInterface::class, $this->exchangeServiceMock);
        $this->positionServiceStub = self::getContainer()->get(PositionServiceInterface::class);

        $this->handler = self::getContainer()->get(CheckPositionIsUnderLiquidationHandler::class);

        self::truncateStops();
    }

    /**
     * @dataProvider addStopTestCases
     */
    public function testAddStop(Position $position, Ticker $ticker, array $delayedStops, array $activeConditionalStops, array $expectedAdditionalStops): void
    {
        $this->haveTicker($ticker);
        $this->havePosition($position);

        $this->haveStopsInDb(...$delayedStops);
        $this->haveActiveConditionalStops($position->symbol, ...$activeConditionalStops);

        // Act
        ($this->handler)(new CheckPositionIsUnderLiquidation($position->symbol, $position->side));

        // Arrange
        self::seeStopsInDb(...array_merge($delayedStops, $expectedAdditionalStops));
    }

    public function addStopTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $position = PositionFactory::short($symbol, 34000, 0.5, 100, $liquidationPrice = 35000);
        $tickerMarkPrice = $liquidationPrice - self::WARNING_LIQUIDATION_DELTA;

        yield sprintf('liquidationPrice=%.2f in warning range (ticker.markPrice = %.2f)', $liquidationPrice, $tickerMarkPrice) => [
            'position' => $position,
            'ticker' => $ticker = TickerFactory::create($symbol, $tickerMarkPrice - 20, $tickerMarkPrice, $tickerMarkPrice - 20),
            'delayedStops' => $delayedStops = [
                self::delayedStop($position, Percent::string('12%'), $ticker->indexPrice->value() + 10)
            ],
            'activeExchangeConditionalStops' => $activeExchangeStops = [
                self::activeConditionalOrder($position, Percent::string('3%'), $ticker->indexPrice->value() + 20)
            ],
            'expectedAdditionalStops' => [
                self::delayedStop(
                    $position,
                    # acceptable stops position size part - total stops position size part ... before critical range
                    new Percent(
                        self::ACCEPTABLE_STOPS_POSITION_SIZE_PART_BEFORE_CRITICAL_RANGE
                        - self::positionSizePart((new StopsCollection(...$delayedStops))->totalVolume(), $position)
                        - self::positionSizePart(array_sum(array_map(static fn (ActiveStopOrder $activeStopOrder) => $activeStopOrder->volume, $activeExchangeStops)), $position)
                    ),
                    $liquidationPrice - self::ADDITIONAL_STOP_MIN_DELTA_WITH_POSITION_LIQUIDATION
                )->setTriggerDelta(self::ADDITIONAL_STOP_TRIGGER_DELTA)
            ]
        ];
    }

    private static function delayedStop(Position $position, Percent $positionSizePart, float $price): Stop
    {
        return new Stop(self::$nextStopId++, $price, $positionSizePart->of($position->size), 10, $position->side);
    }

    private static function activeConditionalOrder(Position $position, Percent $positionSizePart, float $price): ActiveStopOrder
    {
        return new ActiveStopOrder($position->symbol, $position->side, uuid_create(), $positionSizePart->of($position->size), $price, TriggerBy::IndexPrice->value);
    }

    private static function positionSizePart(float $volume, Position $position): int|float
    {
        return round(($volume / $position->size) * 100, 3);
    }

    protected function haveTicker(Ticker $ticker): void
    {
        $this->exchangeServiceMock->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }

    private function havePosition(Position $position): void
    {
        $this->positionServiceStub->havePosition($position);
    }

    private function haveActiveConditionalStops(Symbol $symbol, ActiveStopOrder ...$activeStopOrders): void
    {
        $this->exchangeServiceMock->expects(self::once())->method('activeConditionalOrders')->with($symbol)->willReturn($activeStopOrders);
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('add addStopTestCases: when position is under hedge');
    }
}
