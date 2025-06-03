<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command\Stop;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Command\Stop\CreateStopsGridCommand;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Tests\Factory\PositionFactory;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mock\UniqueIdGeneratorStub;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function get_class;
use function sprintf;

/**
 * @covers \App\Command\Stop\CreateStopsGridCommand
 */
final class CreateStopsGridCommandTest extends KernelTestCase
{
    use StopsTester;
    use ByBitV5ApiRequestsMocker;

    private const COMMAND_NAME = 'sl:grid';
    private const UNIQID_CONTEXT = 'awesome-unique-stops-grid';

    protected function setUp(): void
    {
        self::getContainer()->set(UniqueIdGeneratorInterface::class, new UniqueIdGeneratorStub(self::UNIQID_CONTEXT));

        self::truncateStops();
    }

    /**
     * @dataProvider createStopsGridDataProvider
     *
     * @todo add symbol in command args
     */
    public function testCanCreateStopsGrid(
        Position $position,
        SymbolInterface $symbol,
        Side $side,
        string $forVolume,
        string $from,
        string $to,
        array $commandParams,
        array $expectedStopsInDb
    ): void {
        // Arrange
        $this->havePosition($symbol, $position);
        $cmd = new CommandTester((new Application(self::$kernel))->find(self::COMMAND_NAME));
        $params = [
            'position_side' => $side->value,
            'forVolume' => $forVolume,
            '-f' => $from,
            '-t' => $to,
        ];
        if ($commandParams) {
            foreach ($commandParams as $name => $value) {
                $params[sprintf('--%s', $name)] = $value;
            }
        }

        // Act
        $cmd->execute($params);

        // Assert
        $cmd->assertCommandIsSuccessful();
        $uniq = self::UNIQID_CONTEXT;
        self::assertStringContainsString(sprintf('[OK] Stops grid created. uniqueID: %s', $uniq), $cmd->getDisplay());

        self::seeStopsInDb(...$expectedStopsInDb);
    }

    private function createStopsGridDataProvider(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $side = Side::Sell;
        $position = PositionFactory::short($symbol, 29000, 1.5);

        ## for part of the `BTCUSDT SHORT`-position volume (within PNL range)
        $volumePart = 2; $qnt = 10;
        $stopVolume = $symbol->roundVolume($position->size / $qnt / 100 * $volumePart);
        yield sprintf(
            '%d stops for %d%% part of `%s %s` position (in %s .. %s pnl range)',
            $qnt, $volumePart,
            $symbol->value, $side->title(),
            $fromPnl = '10%', $toPnl = '100%',
        ) => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => sprintf('%s%%', $volumePart),
            '$from' => $fromPnl,
            '$to' => $toPnl,
            '$commandParams' => [CreateStopsGridCommand::ORDERS_QNT_OPTION => (string)$qnt],
            'expectedStopsInDb' => [
                self::buildExpectedStop($symbol, $side, 1, $stopVolume, 28710),
                self::buildExpectedStop($symbol, $side, 2, $stopVolume, 28736.1),
                self::buildExpectedStop($symbol, $side, 3, $stopVolume, 28762.2),
                self::buildExpectedStop($symbol, $side, 4, $stopVolume, 28788.3),
                self::buildExpectedStop($symbol, $side, 5, $stopVolume, 28814.4),
                self::buildExpectedStop($symbol, $side, 6, $stopVolume, 28840.5),
                self::buildExpectedStop($symbol, $side, 7, $stopVolume, 28866.6),
                self::buildExpectedStop($symbol, $side, 8, $stopVolume, 28892.7),
                self::buildExpectedStop($symbol, $side, 9, $stopVolume, 28918.8),
                self::buildExpectedStop($symbol, $side, 10, $stopVolume, 28944.9),
            ]
        ];

        ## for specified volume of the `BTCUSDT SHORT`-position (within PNL range)
        $stopVolume = $symbol->roundVolume(($volume = 0.02) / ($qnt = 10));
        yield sprintf(
            '%d stops for %.3f of `%s %s` position (in %s .. %s pnl range)',
            $qnt, $volume,
            $symbol->value, $side->title(),
            $fromPnl = '10%', $toPnl = '100%',
        ) => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => (string)$volume,
            '$from' => $fromPnl,
            '$to' => $toPnl,
            '$commandParams' => [CreateStopsGridCommand::ORDERS_QNT_OPTION => (string)$qnt],
            'expectedStopsInDb' => [
                self::buildExpectedStop($symbol, $side, 1, $stopVolume, 28710),
                self::buildExpectedStop($symbol, $side, 2, $stopVolume, 28736.1),
                self::buildExpectedStop($symbol, $side, 3, $stopVolume, 28762.2),
                self::buildExpectedStop($symbol, $side, 4, $stopVolume, 28788.3),
                self::buildExpectedStop($symbol, $side, 5, $stopVolume, 28814.4),
                self::buildExpectedStop($symbol, $side, 6, $stopVolume, 28840.5),
                self::buildExpectedStop($symbol, $side, 7, $stopVolume, 28866.6),
                self::buildExpectedStop($symbol, $side, 8, $stopVolume, 28892.7),
                self::buildExpectedStop($symbol, $side, 9, $stopVolume, 28918.8),
                self::buildExpectedStop($symbol, $side, 10, $stopVolume, 28944.9),
            ]
        ];

        ## for specified volume of the `BTCUSDT SHORT`-position (within specified price range)
        $stopVolume = $symbol->roundVolume(($volume = 0.02) / ($qnt = 10));
        yield sprintf(
            '%d stops for %.3f of `%s %s` (from %d to %d)',
            $qnt, $volume,
            $symbol->value, $side->title(),
            $from = 28900, $to = 29100,
        ) => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => (string)$volume,
            '$from' => (string)$from,
            '$to' => (string)$to,
            '$commandParams' => [CreateStopsGridCommand::ORDERS_QNT_OPTION => (string)$qnt],
            'expectedStopsInDb' => [
                self::buildExpectedStop($symbol, $side, 1, $stopVolume, 28900),
                self::buildExpectedStop($symbol, $side, 2, $stopVolume, 28920),
                self::buildExpectedStop($symbol, $side, 3, $stopVolume, 28940),
                self::buildExpectedStop($symbol, $side, 4, $stopVolume, 28960),
                self::buildExpectedStop($symbol, $side, 5, $stopVolume, 28980),
                self::buildExpectedStop($symbol, $side, 6, $stopVolume, 29000),
                self::buildExpectedStop($symbol, $side, 7, $stopVolume, 29020),
                self::buildExpectedStop($symbol, $side, 8, $stopVolume, 29040),
                self::buildExpectedStop($symbol, $side, 9, $stopVolume, 29060),
                self::buildExpectedStop($symbol, $side, 10, $stopVolume, 29080),
            ]
        ];

        ## for part of the `BTCUSDT SHORT`-position volume (within mixed range)
        $volumePart = 2; $qnt = 10;
        $stopVolume = $symbol->roundVolume($position->size / $qnt / 100 * $volumePart);
        yield sprintf(
            '%d stops for %d%% part of `%s %s` (in mixed %s .. %d range)',
            $qnt, $volumePart,
            $symbol->value, $side->title(),
            $fromPnl = '0%', $to = 29300,
        ) => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => sprintf('%s%%', $volumePart),
            '$from' => $fromPnl,
            '$to' => (string)$to,
            '$commandParams' => [CreateStopsGridCommand::ORDERS_QNT_OPTION => (string)$qnt],
            'expectedStopsInDb' => [
                self::buildExpectedStop($symbol, $side, 1, $stopVolume, 29000),
                self::buildExpectedStop($symbol, $side, 2, $stopVolume, 29030),
                self::buildExpectedStop($symbol, $side, 3, $stopVolume, 29060),
                self::buildExpectedStop($symbol, $side, 4, $stopVolume, 29090),
                self::buildExpectedStop($symbol, $side, 5, $stopVolume, 29120),
                self::buildExpectedStop($symbol, $side, 6, $stopVolume, 29150),
                self::buildExpectedStop($symbol, $side, 7, $stopVolume, 29180),
                self::buildExpectedStop($symbol, $side, 8, $stopVolume, 29210),
                self::buildExpectedStop($symbol, $side, 9, $stopVolume, 29240),
                self::buildExpectedStop($symbol, $side, 10, $stopVolume, 29270),
            ]
        ];

        $volumePart = 5; $qnt = 12;
        yield sprintf(
            '[%d stops (but recalculated to 5)] for %d%% part of `%s %s` (in mixed %s .. %d range)',
            $qnt, $volumePart,
            $symbol->value, $side->title(),
            $fromPnl = '0%', $to = 29300,
        ) => [
            '$position' => $position = PositionFactory::short($symbol, 29000, 0.1), '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => sprintf('%s%%', $volumePart),
            '$from' => $fromPnl,
            '$to' => (string)$to,
            '$commandParams' => [CreateStopsGridCommand::ORDERS_QNT_OPTION => (string)$qnt],
            'expectedStopsInDb' => [
                self::buildExpectedStop($symbol, $side, 1, 0.001, 29000),
                self::buildExpectedStop($symbol, $side, 2, 0.001, 29060),
                self::buildExpectedStop($symbol, $side, 3, 0.001, 29120),
                self::buildExpectedStop($symbol, $side, 4, 0.001, 29180),
                self::buildExpectedStop($symbol, $side, 5, 0.001, 29240),
            ]
        ];

        $volumePart = 10;
        yield sprintf(
            '[without passing qnt option] for %d%% part of `%s %s` (in mixed %s .. %d range)',
            $volumePart,
            $symbol->value, $side->title(),
            $fromPnl = '0%', $to = 29300,
        ) => [
            '$position' => $position = PositionFactory::short($symbol, 29000, 0.1), '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => sprintf('%s%%', $volumePart),
            '$from' => $fromPnl,
            '$to' => (string)$to,
            '$commandParams' => [],
            'expectedStopsInDb' => [
                self::buildExpectedStop($symbol, $side, 1, 0.001, 29000),
                self::buildExpectedStop($symbol, $side, 2, 0.001, 29030),
                self::buildExpectedStop($symbol, $side, 3, 0.001, 29060),
                self::buildExpectedStop($symbol, $side, 4, 0.001, 29090),
                self::buildExpectedStop($symbol, $side, 5, 0.001, 29120),
                self::buildExpectedStop($symbol, $side, 6, 0.001, 29150),
                self::buildExpectedStop($symbol, $side, 7, 0.001, 29180),
                self::buildExpectedStop($symbol, $side, 8, 0.001, 29210),
                self::buildExpectedStop($symbol, $side, 9, 0.001, 29240),
                self::buildExpectedStop($symbol, $side, 10, 0.001, 29270),
            ]
        ];

        $volumePart = 20;
        yield sprintf(
            '[without passing qnt option] for %d%% part of `%s %s` (in mixed %s .. %d range)',
            $volumePart,
            $symbol->value, $side->title(),
            $fromPnl = '0%', $to = 29300,
        ) => [
            '$position' => $position = PositionFactory::short($symbol, 29000, 0.1), '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => sprintf('%s%%', $volumePart),
            '$from' => $fromPnl,
            '$to' => (string)$to,
            '$commandParams' => [],
            'expectedStopsInDb' => [
                self::buildExpectedStop($symbol, $side, 1, 0.002, 29000),
                self::buildExpectedStop($symbol, $side, 2, 0.002, 29030),
                self::buildExpectedStop($symbol, $side, 3, 0.002, 29060),
                self::buildExpectedStop($symbol, $side, 4, 0.002, 29090),
                self::buildExpectedStop($symbol, $side, 5, 0.002, 29120),
                self::buildExpectedStop($symbol, $side, 6, 0.002, 29150),
                self::buildExpectedStop($symbol, $side, 7, 0.002, 29180),
                self::buildExpectedStop($symbol, $side, 8, 0.002, 29210),
                self::buildExpectedStop($symbol, $side, 9, 0.002, 29240),
                self::buildExpectedStop($symbol, $side, 10, 0.002, 29270),
            ]
        ];
    }

    /**
     * @dataProvider failCases
     */
    public function testFailCreateStopsGrid(
        Position $position,
        SymbolInterface $symbol,
        ?Side $side,
        ?string $forVolume,
        ?string $from,
        ?string $to,
        $qnt,
        \Throwable $expectedException
    ): void {
        $this->havePosition($symbol, $position);
        $cmd = new CommandTester((new Application(self::$kernel))->find(self::COMMAND_NAME));

        $this->expectException(get_class($expectedException));
//        $this->expectExceptionMessage($expectedException->getMessage());

        $params = [];

        $side !== null && $params['position_side'] = $side->value;
        $forVolume !== null && $params['forVolume'] = $forVolume;
        $from !== null && $params['-f'] = $from;
        $to !== null && $params['-t'] = $to;
        $qnt !== null && $params['-c'] = (string)$qnt;

        $cmd->execute($params);
    }

    private function failCases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $side = Side::Sell;
        $position = PositionFactory::short($symbol, 29000, 0.5);

        yield '`volume` >= position size' => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => '0.5',
            '$from' => '-10%', '$to' => '28900', '$qnt' => 10,
            'expectedMessage' => new LogicException('$forVolume is greater than whole position size'),
        ];

        yield '`volume` >= position size (by %)' => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => '100%',
            '$from' => '-10%', '$to' => '28900', '$qnt' => 10,
            'expectedMessage' => new LogicException('$forVolume is greater than whole position size'),
        ];

        yield '`volume`(%) must be > 0' => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => '0%',
            '$from' => '-10%', '$to' => '28900', '$qnt' => 10,
            'expectedMessage' => new LogicException('Percent value must be in 0..100 range. "0.00" given.'),
        ];

        yield '`volume` must be > 0' => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => '0',
            '$from' => '-10%', '$to' => '28900', '$qnt' => 10,
            'expectedMessage' => new LogicException('$forVolume must be greater than zero ("0.00" given)'),
        ];

        yield '`ordersQnt` must be >= 1' => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => '0.1', '$from' => '-10%', '$to' => '28900',
            '$qnt' => 0,
            'expectedMessage' => new LogicException('$qnt must be >= 1 (0 given)'),
        ];

        yield '`from` must be ordersQnt must be >= 1' => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => '0.1',
            '$from' => '-1',
            '$to' => '28900', '$qnt' => 10,
            'expectedMessage' => new PriceCannotBeLessThanZero(123, $symbol),
        ];

        yield '`to` must be ordersQnt must be >= 1' => [
            '$position' => $position, '$symbol' => $symbol, '$side' => $side,
            '$forVolume' => '0.1',
            '$from' => '29000',
            '$to' => '-1', '$qnt' => 10,
            'expectedMessage' => new PriceCannotBeLessThanZero(123, $symbol),
        ];
    }

    private static function buildExpectedStop(SymbolInterface $symbol, Side $side, int $id, float $volume, float $price, ?float $tD = null): Stop
    {
        $tD = $tD ?: $symbol->stopDefaultTriggerDelta();

        return new Stop($id, $price, $volume, $tD, $symbol, $side, ['uniqid' => self::UNIQID_CONTEXT]);
    }
}
