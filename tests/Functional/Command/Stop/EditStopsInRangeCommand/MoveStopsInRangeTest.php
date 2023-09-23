<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command\Stop\EditStopsInRangeCommand;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\Stop\EditStopsCommand;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\PositionFactory;
use App\Tests\Fixture\StopFixture;

use function array_map;
use function sprintf;

/**
 * @covers \App\Command\Stop\EditStopsCommand::ACTION_MOVE
 */
final class MoveStopsInRangeTest extends EditStopsInRangeTestAbstract
{
    private const ACTION = EditStopsCommand::ACTION_MOVE;

    /**
     * @dataProvider editStopsInRangeDataProvider
     *
     * @todo add symbol in command args
     */
    public function testCanEditStopsInRange(
        array $initialStops,
        Position $position,
        Symbol $symbol,
        Side $side,
        string $from,
        string $to,
        array $additionalParams,
        array $expectedStopsInDb
    ): void {
        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $initialStops));
        $this->positionServiceStub->havePosition($position);

        $params = ['position_side' => $side->value, '-f' => $from, '-t' => $to, '-a' => self::ACTION];
        foreach ($additionalParams as $name => $value) {
            $params[sprintf('--%s', $name)] = $value;
        }

        $this->tester->execute($params);

        $this->tester->assertCommandIsSuccessful();
        self::seeStopsInDb(...$expectedStopsInDb);
    }

    private function editStopsInRangeDataProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $position = PositionFactory::short($symbol, 29000, 1.5);

        $fromPnl = '28930'; $toPnl = '28990';
        yield 'move to specified price' => [
            [
                new Stop(1, 28890, 0.003, 10, $side),
                new Stop(2, 28920, 0.003, 10, $side),
                new Stop(3, 28930, 0.002, 10, $side),
                new Stop(4, 28931, 0.002, 10, $side),
                new Stop(5, 28940, 0.003, 10, $side),
                new Stop(6, 28941, 0.004, 10, $side),
                new Stop(7, 28950, 0.002, 10, $side),
                new Stop(8, 28951, 0.001, 10, $side),
                new Stop(9, 28960, 0.002, 10, $side),
                new Stop(10, 28961, 0.002, 10, $side),
                new Stop(11, 28970, 0.004, 10, $side),
                new Stop(12, 28971, 0.005, 10, $side),
                new Stop(13, 28972, 0.01, 10, $side),
            ],
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$from' => $fromPnl,
            '$to' => $toPnl,
            '$params' => [
                EditStopsCommand::MOVE_TO_PRICE_OPTION => '0%',
                EditStopsCommand::MOVE_PART_OPTION => '35%',
            ],
            'expectedStopsInDb' => [
                new Stop(1, 28890, 0.003, 10, $side),
                new Stop(2, 28920, 0.003, 10, $side),
                new Stop(5, 28940, 0.001, 10, $side),
                new Stop(6, 28941, 0.004, 10, $side),
                new Stop(11, 28970, 0.004, 10, $side),
                new Stop(12, 28971, 0.005, 10, $side),
                new Stop(13, 28972, 0.01, 10, $side),
                new Stop(14, 29000, 0.013, 20, $side),
            ]
        ];

        // @todo | 2 big stops + remove most part
    }
}
