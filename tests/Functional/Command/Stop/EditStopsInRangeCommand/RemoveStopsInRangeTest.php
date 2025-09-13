<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command\Stop\EditStopsInRangeCommand;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Command\Stop\EditStopsCommand;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\PositionFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\CommandsTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Trading\Domain\Symbol\SymbolInterface;
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

    private const COMMAND_NAME = 'sl:edit';
    private const ACTION = EditStopsCommand::ACTION_REMOVE;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tester = $this->createCommandTester(self::COMMAND_NAME);
    }

    /**
     * @dataProvider removeStopsFromRangeDataProvider
     *
     * @todo add symbol in command args
     */
    public function testCanRemoveStopsFromRange(
        array $initialStops,
        array $activeConditionalStops,
        Position $position,
        SymbolInterface $symbol,
        Side $side,
        string $from,
        string $to,
        array $expectedStopsInDb
    ): void {
        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $initialStops));
        $this->havePosition($symbol, $position);
        $this->haveActiveConditionalStops($symbol, ...$activeConditionalStops);
        foreach ($activeConditionalStops as $activeConditionalStop) {
            $this->expectsToMakeApiCalls($this->successCloseActiveConditionalOrderApiCallExpectation($symbol, $activeConditionalStop));
        }

        $this->tester->execute(['position_side' => $side->value, '--symbol' => $symbol->name(), '-f' => $from, '-t' => $to, '-a' => self::ACTION]);

        $this->tester->assertCommandIsSuccessful();
        self::seeStopsInDb(...$expectedStopsInDb);
    }

    private function removeStopsFromRangeDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $side = Side::Sell;
        $position = PositionFactory::short($symbol, 29000, 1.5);

        $fromPnl = '50%'; $toPnl = '70%';
        yield 'remove in PNL% range' => [
            [
                new Stop(10, 28710, 0.003, 10, $symbol, $side),
                new Stop(20, 28740, 0.003, 10, $symbol, $side),
                new Stop(30, 28760, 0.003, 10, $symbol, $side),
                new Stop(40, 28790, 0.003, 10, $symbol, $side),
                new Stop(50, 28810, 0.002, 10, $symbol, $side),
                new Stop(60, 28840, 0.004, 10, $symbol, $side),
                new Stop(61, 28840, 0.005, 10, $symbol, $side)->setExchangeOrderId('123456'),// don't remove because order executed and must remain
                new Stop(62, 28840, 0.006, 10, $symbol, $side)->setExchangeOrderId('1234567'),// remove because order pushed to exchange (exchange orders are removed on execution)
                new Stop(70, 28860, 0.003, 10, $symbol, $side),
                new Stop(80, 28710, 0.003, 10, $symbol, $side),
                new Stop(90, 28890, 0.003, 10, $symbol, $side),
                new Stop(100, 28920, 0.003, 10, $symbol, $side),
            ],
            [
                new ActiveStopOrder($symbol, $side, '1234567', 0.006, 28840, TriggerBy::IndexPrice->value)
            ],
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$from' => $fromPnl,
            '$to' => $toPnl,
            'expectedStopsInDb' => [
                new Stop(10, 28710, 0.003, 10, $symbol, $side),
                new Stop(20, 28740, 0.003, 10, $symbol, $side),
                new Stop(30, 28760, 0.003, 10, $symbol, $side),
                new Stop(40, 28790, 0.003, 10, $symbol, $side),
                new Stop(61, 28840, 0.005, 10, $symbol, $side)->setExchangeOrderId('123456'),
                new Stop(70, 28860, 0.003, 10, $symbol, $side),
                new Stop(80, 28710, 0.003, 10, $symbol, $side),
                new Stop(90, 28890, 0.003, 10, $symbol, $side),
                new Stop(100, 28920, 0.003, 10, $symbol, $side),
            ]
        ];
    }
}
