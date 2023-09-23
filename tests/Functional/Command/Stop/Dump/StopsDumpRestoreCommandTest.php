<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command\Stop\Dump;

use App\Bot\Domain\Entity\Stop;
use App\Command\Stop\Dump\StopsDumpRestoreCommand;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Tests\Stub\Bot\PositionServiceStub;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function sprintf;

/**
 * @covers \App\Command\Stop\Dump\StopsDumpRestoreCommand
 */
final class StopsDumpRestoreCommandTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;

    private const COMMAND_NAME = 'sl:dump:restore';

    private PositionServiceStub $positionServiceStub;

    private DateTimeImmutable $currentDatetime;

    protected function setUp(): void
    {
        self::truncateStops();
    }

    /**
     * @dataProvider restoreStopsTestDataProvider
     *
     * @todo add symbol in command args
     */
    public function testCanDumpStops(
        string $filepath,
        array $expectedStopsInDb,
    ): void {
        // Arrange
        $cmd = new CommandTester((new Application(self::$kernel))->find(self::COMMAND_NAME));

        // Act
        $cmd->execute([StopsDumpRestoreCommand::PATH_ARG => $filepath]);

        // Assert
        $cmd->assertCommandIsSuccessful();
        self::assertStringContainsString(sprintf('Qnt: %d', count($expectedStopsInDb)), $cmd->getDisplay());
        self::seeStopsInDb(...$expectedStopsInDb);
    }

    private function restoreStopsTestDataProvider(): iterable
    {
        yield 'without delete' => [
            __DIR__ . '/../../../../Mock/dump/sell.2023-09-07_23:32:32.json',
            [
                (new Stop(2, 28922.2, 0.003, 10, Side::Sell))->setExchangeOrderId('885e602a-93fa-4a06-90c7-ae9f0d3b3e36'),
                (new Stop(3, 28933.3, 0.002, 10, Side::Sell))->setIsWithoutOppositeOrder(),
                (new Stop(4, 28931.1, 0.002, 10, Side::Sell)),
                (new Stop(8, 28951.2, 0.001, 10, Side::Sell)),
                (new Stop(13, 28972.3, 0.01, 10, Side::Sell)),
            ]
        ];
    }
}
