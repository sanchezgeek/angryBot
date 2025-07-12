<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\MoveStops;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Stop\Application\UseCase\MoveStops\MoveStopsEntryDto;
use App\Stop\Application\UseCase\MoveStops\MoveStopsToBreakevenHandler;
use App\Stop\Application\UseCase\MoveStops\MoveStopsToBreakevenHandlerInterface;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\StopsTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MoveStopsHandlerTest extends KernelTestCase
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
     * @dataProvider cases
     */
    public function testMove(
        Position $position,
        array $stopsBeforeHandle,
        $moveToPercent,
        array $stopsAfterHandle,
    ): void {
        foreach ($stopsBeforeHandle as $stop) {
            $this->applyDbFixtures(new StopFixture($stop));
        }

        $entry = new MoveStopsEntryDto(
            $position,
            $moveToPercent
        );

        $this->handler->handle($entry);

        $this->seeStopsInDb(...$stopsAfterHandle);
    }

    public function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        $position = PositionBuilder::short()->symbol($symbol)->entry(100000);

        $stops = [
            StopBuilder::short(1, 101500, 0.002, $symbol)->build(),
            StopBuilder::short(2, 100500, 0.001, $symbol)->build(),
        ];

        $moveToPercent = 0;

        yield [
            $position->build(),
            $stops,
            $moveToPercent,
            [
                StopBuilder::short(1, 101000, 0.002, $symbol)->build()->setOriginalPrice(101500),
                StopBuilder::short(2, 100000, 0.001, $symbol)->build()->setOriginalPrice(100500),
            ]
        ];
    }
}
