<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command\Stop\Dump;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Command\Stop\Dump\StopsDumpCommand;
use App\Domain\Position\ValueObject\Side;
use App\Helper\Json;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Tests\Stub\Bot\PositionServiceStub;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function array_filter;
use function array_map;
use function array_values;
use function date_create_immutable;
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

    private const COMMAND_NAME = 'sl:dump';

    private PositionServiceStub $positionServiceStub;

    private DateTimeImmutable $currentDatetime;

    protected function setUp(): void
    {
        $this->currentDatetime = date_create_immutable();
        $clockMock = $this->createMock(ClockInterface::class);
        $clockMock->expects(self::once())->method('now')->willReturn($this->currentDatetime);
        self::getContainer()->set(ClockInterface::class, $clockMock);

        self::truncateStops();
    }

    /**
     * @dataProvider dumpStopsTestDataProvider
     *
     * @todo add symbol in command args
     */
    public function testCanDumpStops(
        Symbol $symbol,
        Side $side,
        array $initialStops,
        string $expectedContent,
        array $expectedStopsInDb,
        array $additionalParams = [],
    ): void {
        // Arrange
        $this->applyDbFixtures(...array_map(static fn(Stop $stop) => new StopFixture($stop), $initialStops));

        $dirPath = __DIR__ . '/../../../../../tests/_data/dumps';
        $filepath = sprintf('%s/%s.%s.json', $dirPath, $side->value, $this->currentDatetime->format('Y-m-d_H:i:s'));
        self::assertFileDoesNotExist($filepath);

        $cmd = new CommandTester((new Application(self::$kernel))->find(self::COMMAND_NAME));
        $params = ['position_side' => $side->value, '-m' => StopsDumpCommand::MODE_ALL, sprintf('--%s', StopsDumpCommand::DIR_PATH_OPTION) => $dirPath];
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
        $symbol = Symbol::BTCUSDT; $side = Side::Sell;

        $initialStops = [
            new Stop(1, 28891.1, 0.003, 10, $symbol, $side->getOpposite()),
            (new Stop(2, 28922.2, 0.003, 10, $symbol, $side))->setExchangeOrderId(uuid_create()),
            (new Stop(3, 28933.3, 0.002, 10, $symbol, $side))->setIsWithoutOppositeOrder(),
            (new Stop(4, 28931.1, 0.002, 10, $symbol, $side)),
            (new Stop(8, 28951.2, 0.001, 10, $symbol, $side)),
            (new Stop(13, 28972.3, 0.01, 10, $symbol, $side)),
            new Stop(14, 28972.4, 0.01, 10, $symbol, $side->getOpposite()),
        ];

        yield 'without deletion' => [
            $symbol, $side, $initialStops,
            Json::encode(array_map(
                static fn (Stop $stop) => $stop->toArray(),
                array_values(array_filter($initialStops, static fn(Stop $stop) => $stop->getPositionSide() === $side))
            )),
            $initialStops,
        ];

        yield 'with deletion' => [
            $symbol, $side, $initialStops,
            Json::encode(array_map(
                static fn (Stop $stop) => $stop->toArray(),
                array_values(array_filter($initialStops, static fn(Stop $stop) => $stop->getPositionSide() === $side))
            )),
            array_values(array_filter($initialStops, static fn(Stop $stop) => $stop->getPositionSide() === $side->getOpposite())),
            [StopsDumpCommand::DELETE_DUMPED_STOPS_OPTION => true]
        ];
    }
}
