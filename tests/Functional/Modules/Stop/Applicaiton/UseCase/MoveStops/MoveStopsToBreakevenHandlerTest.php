<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\MoveStops;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Stop\Application\UseCase\MoveStopsToBreakeven\MoveStopsToBreakevenEntryDto;
use App\Stop\Application\UseCase\MoveStopsToBreakeven\MoveStopsToBreakevenHandler;
use App\Stop\Application\UseCase\MoveStopsToBreakeven\MoveStopsToBreakevenHandlerInterface;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\StopsTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers MoveStopsToBreakevenHandler
 */
final class MoveStopsToBreakevenHandlerTest extends KernelTestCase
{
    use StopsTester;

    private MoveStopsToBreakevenHandler $handler;

    protected function setUp(): void
    {
        /** @var MoveStopsToBreakevenHandlerInterface $handler */
        $handler = self::getContainer()->get(MoveStopsToBreakevenHandler::class);

        $this->handler = $handler;
    }

    /**
     * @dataProvider simpleCases
     */
    public function testSimpleMove(
        Position $position,
        array $stopsBeforeHandle,
        $moveToPercent,
        array $stopsAfterHandle,
    ): void {
        foreach ($stopsBeforeHandle as $stop) {
            $this->applyDbFixtures(new StopFixture($stop));
        }

        $this->handler->handle(
            MoveStopsToBreakevenEntryDto::simple($position, $moveToPercent)
        );

        $this->seeStopsInDb(...$stopsAfterHandle);
    }

    public function simpleCases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $moveToPercent = -10;

        # SHORT
        yield [
            PositionBuilder::short()->symbol($symbol)->entry(100000)->build(),
            [
                StopBuilder::short(1, 101500, 0.002, $symbol)->build(),
                StopBuilder::short(2, 100500, 0.001, $symbol)->build(),
                StopBuilder::short(3, 50000, 0.001, $symbol)->build()->setIsStopAfterOtherSymbolLoss(),
            ],
            $moveToPercent,
            [
                StopBuilder::short(1, 101500, 0.002, $symbol)->build(),
                StopBuilder::short(2, 100500, 0.001, $symbol)->build(),
                StopBuilder::short(3, 50000, 0.001, $symbol)->build()->setIsStopAfterOtherSymbolLoss(),
            ]
        ];

        # LONG
        yield [
            PositionBuilder::long()->symbol($symbol)->entry(100000)->build(),
            [
                StopBuilder::long(1, 98500, 0.002, $symbol)->build(),
                StopBuilder::long(2, 99500, 0.001, $symbol)->build(),
                StopBuilder::long(3, 110000, 0.001, $symbol)->build()->setStopAfterFixHedgeOppositePositionContest(),
            ],
            $moveToPercent,
            [
                StopBuilder::long(1, 98500, 0.002, $symbol)->build(),
                StopBuilder::long(2, 99500, 0.001, $symbol)->build(),
                StopBuilder::long(3, 110000, 0.001, $symbol)->build()->setStopAfterFixHedgeOppositePositionContest(),
            ]
        ];
    }

    /**
     * @dataProvider excludeFixationsCases
     */
    public function testWithExcludeFixations(
        Position $position,
        array $stopsBeforeHandle,
        $moveToPercent,
        array $stopsAfterHandle,
    ): void {
        foreach ($stopsBeforeHandle as $stop) {
            $this->applyDbFixtures(new StopFixture($stop));
        }

        $this->handler->handle(
            MoveStopsToBreakevenEntryDto::excludeFixationStops($position, $moveToPercent)
        );

        $this->seeStopsInDb(...$stopsAfterHandle);
    }

    public function excludeFixationsCases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $moveToPercent = -10;

        # SHORT
        yield [
            PositionBuilder::short()->symbol($symbol)->entry(100000)->build(),
            [
                StopBuilder::short(1, 101500, 0.002, $symbol)->build(),
                StopBuilder::short(2, 100500, 0.001, $symbol)->build(),
                StopBuilder::short(3, 50000, 0.001, $symbol)->build()->setIsStopAfterOtherSymbolLoss(),
            ],
            $moveToPercent,
            [
                StopBuilder::short(1, 101100, 0.002, $symbol)->build()->setOriginalPrice(101500),
                StopBuilder::short(2, 100100, 0.001, $symbol)->build()->setOriginalPrice(100500),
                StopBuilder::short(3, 50000, 0.001, $symbol)->build()->setIsStopAfterOtherSymbolLoss(),
            ]
        ];

        # LONG
        yield [
            PositionBuilder::long()->symbol($symbol)->entry(100000)->build(),
            [
                StopBuilder::long(1, 98500, 0.002, $symbol)->build(),
                StopBuilder::long(2, 99500, 0.001, $symbol)->build(),
                StopBuilder::long(3, 110000, 0.001, $symbol)->build()->setStopAfterFixHedgeOppositePositionContest(),
            ],
            $moveToPercent,
            [
                StopBuilder::long(1, 98900, 0.002, $symbol)->build()->setOriginalPrice(98500),
                StopBuilder::long(2, 99900, 0.001, $symbol)->build()->setOriginalPrice(99500),
                StopBuilder::long(3, 110000, 0.001, $symbol)->build()->setStopAfterFixHedgeOppositePositionContest(),
            ]
        ];
    }
}
