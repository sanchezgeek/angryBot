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
use App\Tests\Mixin\CommandsTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use Symfony\Component\Console\Tester\CommandTester;

use function array_map;

/**
 * @covers \App\Command\Stop\EditStopsCommand::ACTION_REMOVE
 */
final class RemoveStopsInRangeTest extends KernelTestCase
{
    use StopsTester;
    use CommandsTester;
    use ByBitV5ApiRequestsMocker;

    private const COMMAND_NAME = 'sl:range-edit';
    private const ACTION = EditStopsCommand::ACTION_REMOVE;

    private CommandTester $tester;

    protected function setUp(): void
    {
        self::truncateStops();

        $this->tester = $this->createCommandTester(self::COMMAND_NAME);
    }

    /**
     * @dataProvider removeStopsFromRangeDataProvider
     *
     * @todo add symbol in command args
     */
    public function testCanRemoveStopsFromRange(
        array $initialStops,
        Position $position,
        Symbol $symbol,
        Side $side,
        string $from,
        string $to,
        array $expectedStopsInDb
    ): void {
        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $initialStops));
        $this->havePosition($symbol, $position);

        $this->tester->execute(['position_side' => $side->value, '-f' => $from, '-t' => $to, '-a' => self::ACTION]);

        $this->tester->assertCommandIsSuccessful();
        self::seeStopsInDb(...$expectedStopsInDb);
    }

    private function removeStopsFromRangeDataProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $position = PositionFactory::short($symbol, 29000, 1.5);

        $fromPnl = '50%'; $toPnl = '70%';
        yield 'remove in PNL% range' => [
            [
                new Stop(1, 28710, 0.003, 10, $side),
                new Stop(2, 28740, 0.003, 10, $side),
                new Stop(3, 28760, 0.003, 10, $side),
                new Stop(4, 28790, 0.003, 10, $side),
                new Stop(5, 28810, 0.002, 10, $side),
                new Stop(6, 28840, 0.004, 10, $side),
                new Stop(7, 28860, 0.003, 10, $side),
                new Stop(8, 28710, 0.003, 10, $side),
                new Stop(9, 28890, 0.003, 10, $side),
                new Stop(10, 28920, 0.003, 10, $side),
            ],
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$from' => $fromPnl,
            '$to' => $toPnl,
            'expectedStopsInDb' => [
                new Stop(1, 28710, 0.003, 10, $side),
                new Stop(2, 28740, 0.003, 10, $side),
                new Stop(3, 28760, 0.003, 10, $side),
                new Stop(4, 28790, 0.003, 10, $side),
                new Stop(7, 28860, 0.003, 10, $side),
                new Stop(8, 28710, 0.003, 10, $side),
                new Stop(9, 28890, 0.003, 10, $side),
                new Stop(10, 28920, 0.003, 10, $side),
            ]
        ];
    }
}
