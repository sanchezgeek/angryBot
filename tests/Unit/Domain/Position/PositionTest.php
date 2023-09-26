<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Position;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\PositionFactory;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * @covers \App\Bot\Domain\Position
 */
final class PositionTest extends TestCase
{
    public function testIsShort(): void
    {
        $position = new Position(Side::Sell, Symbol::BTCUSDT, 100500, 1050, 100005000, 0, 1000, 100);

        self::assertTrue($position->isShort());
        self::assertFalse($position->isLong());
    }

    public function testIsLong(): void
    {
        $position = new Position(Side::Buy, Symbol::BTCUSDT, 100500, 1050, 100005000, 0, 1000, 100);

        self::assertTrue($position->isLong());
        self::assertFalse($position->isShort());
    }

    /**
     * @dataProvider successCasesProvider
     */
    public function testCanGetVolumePart(Position $position, float $volumePart, float $expectedVolume): void
    {
        self::assertEquals($expectedVolume, $position->getVolumePart($volumePart));
    }

    private function successCasesProvider(): array
    {
        return [
            [
                '$position' => PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100),
                '$volumePart' => 50,
                'expectedVolume' => 0.25,
            ],
            [
                '$position' => PositionFactory::short(Symbol::BTCUSDT, 30000, 0.1, 100),
                '$volumePart' => 10,
                'expectedVolume' => 0.01,
            ],
            [
                '$position' => PositionFactory::short(Symbol::BTCUSDT, 30000, 0.1, 100),
                '$volumePart' => 3,
                'expectedVolume' => 0.003,
            ],
            [
                '$position' => PositionFactory::short(Symbol::BTCUSDT, 29000, 0.5, 100),
                '$volumePart' => 10,
                'expectedVolume' => 0.05,
            ],
        ];
    }

    /**
     * @dataProvider wrongGetVolumeCasesProvider
     */
    public function testFailGetVolumePart(float $percent): void
    {
        $position = PositionFactory::short(Symbol::BTCUSDT, 30000, 0.5, 100);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('Percent value must be in 0..100 range. "%.2f" given.', $percent));

        $position->getVolumePart($percent);
    }

    private function wrongGetVolumeCasesProvider(): array
    {
        return [[-150], [-100], [0], [101], [150]];
    }
}
