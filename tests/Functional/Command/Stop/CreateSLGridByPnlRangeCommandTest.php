<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command\Stop;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\Stop\CreateSLGridByPnlRangeCommand;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\PositionFactory;
use App\Tests\Mixin\StopTest;
use App\Tests\Mock\UniqueIdGeneratorStub;
use App\Tests\Stub\Bot\PositionServiceStub;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function sprintf;

/**
 * @covers CreateSLGridByPnlRangeCommand
 */
final class CreateSLGridByPnlRangeCommandTest extends KernelTestCase
{
    use StopTest;

    private const COMMAND_NAME = 'sl:grid:by-pnl';
    private const TRIGGER_DELTA = CreateSLGridByPnlRangeCommand::DEFAULT_TRIGGER_DELTA;
    private const UNIQID_CONTEXT = 'awesome-unique-stops-grid';

    private PositionServiceStub $positionServiceStub;

    protected function setUp(): void
    {
        $this->positionServiceStub = self::getContainer()->get(PositionServiceInterface::class);
        self::getContainer()->set(UniqueIdGeneratorInterface::class, new UniqueIdGeneratorStub(self::UNIQID_CONTEXT));

        self::truncateStops();
    }

    /**
     * @dataProvider createStopsGridDataProvider
     */
    public function testCanCreateStopsGrid(
        Position $position,
        Symbol $symbol,
        Side $side,
        string $forVolume,
        string $fromPnl,
        string $toPnl,
        int $qnt,
        array $expectedStopsInDb
    ): void {
        $this->positionServiceStub->havePosition($position);
        $cmd = new CommandTester((new Application(self::$kernel))->find(self::COMMAND_NAME));
        $cmd->execute([
            'position_side' => $side->value,
            'forVolume' => $forVolume,
            '-f' => $fromPnl,
            '-t' => $toPnl,
            '-c' => (string)$qnt
        ]);

        $cmd->assertCommandIsSuccessful();
        $uniq = self::UNIQID_CONTEXT;
        self::assertStringContainsString(sprintf('[OK] Stops grid created. uniqueID: %s', $uniq), $cmd->getDisplay());

        self::seeStopsInDb(...$expectedStopsInDb);
    }

    private function createStopsGridDataProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;

        ## for part of the `BTCUSDT SHORT` volume
        $side = Side::Sell;
        $position = PositionFactory::short($symbol, 29000, 1.5);
        $volumePart = 2; $qnt = 10;
        $stopVolume = $position->size / $qnt / 100 * 2;
        $fromPnl = '10%'; $toPnl = '100%';
        yield sprintf(
            'For %d%% of %s %s (in %s .. %s pnl range)',
            $volumePart,
            $symbol->value,
            $side->title(),
            $fromPnl,
            $toPnl
        ) => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => sprintf('%s%%', $volumePart),
            '$fromPnl' => $fromPnl,
            '$toPnl' => $toPnl,
            '$qnt' => $qnt,
            'expectedStopsInDb' => [
                self::buildExpectedStop($side, 1, $stopVolume, 28710),
                self::buildExpectedStop($side, 2, $stopVolume, 28736.1),
                self::buildExpectedStop($side, 3, $stopVolume, 28762.2),
                self::buildExpectedStop($side, 4, $stopVolume, 28788.3),
                self::buildExpectedStop($side, 5, $stopVolume, 28814.4),
                self::buildExpectedStop($side, 6, $stopVolume, 28840.5),
                self::buildExpectedStop($side, 7, $stopVolume, 28866.6),
                self::buildExpectedStop($side, 8, $stopVolume, 28892.7),
                self::buildExpectedStop($side, 9, $stopVolume, 28918.8),
                self::buildExpectedStop($side, 10, $stopVolume, 28944.9),
                self::buildExpectedStop($side, 11, $stopVolume, 28971),
            ]
        ];
    }

    private static function buildExpectedStop(Side $side, int $id, float $volume, float $price, float $tD = self::TRIGGER_DELTA): Stop
    {
        return new Stop($id, $price, $volume, $tD, $side, ['uniqid' => self::UNIQID_CONTEXT]);
    }
}
