<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command\Stop\Dump;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Command\Stop\Dump\StopsDumpCommand;
use App\Domain\Position\ValueObject\Side;
use App\Helper\Json;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\Clock\ClockTimeAwareTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Tests\Stub\Bot\PositionServiceStub;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function array_filter;
use function array_map;
use function array_values;
use function file_get_contents;
use function sprintf;
use function uuid_create;

/**
 * @covers \App\Command\Stop\Dump\StopsDumpCommand
 */
final class StopsDumpCommandTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;
    use ClockTimeAwareTester;
    use ByBitV5ApiRequestsMocker;

    private const string COMMAND_NAME = 'sl:dump';

    private PositionServiceStub $positionServiceStub;

    /**
     * @dataProvider dumpStopsTestDataProvider
     *
     * @todo add symbol in command args
     */
    public function testCanDumpStops(
        SymbolInterface $symbol,
        array $allOpenedPositions,
        array $initialStops,
        string $expectedContent,
        array $expectedStopsInDb,
        array $additionalParams = [],
    ): void {
        // Arrange
        $this->haveAllOpenedPositionsWithLastMarkPrices($allOpenedPositions);
        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $initialStops));

        $dirPath = __DIR__ . '/../../../../../tests/_data/dumps';
        $filepath = sprintf('%s/stops_%s.json', $dirPath, self::getCurrentClockTime()->format('Y-m-d_H:i:s'));
        self::assertFileDoesNotExist($filepath);

        $cmd = new CommandTester((new Application(self::$kernel))->find(self::COMMAND_NAME));
        $params = ['-m' => StopsDumpCommand::MODE_ALL, sprintf('--%s', StopsDumpCommand::DIR_PATH_OPTION) => $dirPath];
        foreach ($additionalParams as $name => $value) {
            $params[sprintf('--%s', $name)] = $value;
        }

        // Act
        $cmd->execute($params);

        // Assert
        $cmd->assertCommandIsSuccessful();
        self::assertStringContainsString($filepath, $cmd->getDisplay());

        self::assertSame($expectedContent, file_get_contents($filepath));

        self::seeStopsInDb(...$expectedStopsInDb);
        unlink($filepath);
    }

    private function dumpStopsTestDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT; $side = Side::Sell;
        $btcUsdtPosition = PositionBuilder::bySide($side)->symbol($symbol)->entry(35000)->size(0.5)->liq(40000)->build();

        $initialStops = [
            new Stop(1, 28891.1, 0.003, 10, $symbol, $side->getOpposite()),
            (new Stop(2, 28922.2, 0.003, 10, $symbol, $side))->setExchangeOrderId(uuid_create()),
            (new Stop(3, 28933.3, 0.002, 10, $symbol, $side))->setIsWithoutOppositeOrder(),
            (new Stop(4, 28931.1, 0.002, 10, $symbol, $side)),
            (new Stop(8, 28951.2, 0.001, 10, $symbol, $side)),
            (new Stop(13, 28972.3, 0.01, 10, $symbol, $side)),
            new Stop(14, 28972.4, 0.01, 10, $symbol, $side->getOpposite()),
        ];

        $allOpenedPositions = [
            (string)$btcUsdtPosition->entryPrice()->value() =>  $btcUsdtPosition,
        ];

        yield 'without deletion' => [
            $symbol, $allOpenedPositions, $initialStops,
            Json::encode(array_map(
                static fn (Stop $stop) => $stop->toArray(),
                array_values(array_filter($initialStops, static fn(Stop $stop) => $stop->getPositionSide() === $side))
            )),
            $initialStops,
        ];

        yield 'with deletion' => [
            $symbol, $allOpenedPositions, $initialStops,
            Json::encode(array_map(
                static fn (Stop $stop) => $stop->toArray(),
                array_values(array_filter($initialStops, static fn(Stop $stop) => $stop->getPositionSide() === $side))
            )),
            array_values(array_filter($initialStops, static fn(Stop $stop) => $stop->getPositionSide() === $side->getOpposite())),
            [StopsDumpCommand::DELETE_DUMPED_STOPS_OPTION => true]
        ];
    }
}
