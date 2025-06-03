<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bot\Application\Service\Hedge;

use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Tests\Factory\PositionFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Bot\Application\Service\Hedge\Hedge
 */
class HedgeTest extends TestCase
{
    /**
     * @dataProvider isProfitableHedgeTestDataProvider
     */
    public function testIsRightHedge(Position $a, Position $b, bool $expectedResult): void
    {
        self::assertSame($expectedResult, Hedge::create($a, $b)->isProfitableHedge());
    }

    public function isProfitableHedgeTestDataProvider(): array
    {
        return [
            [
                PositionFactory::short(SymbolEnum::BTCUSDT, 100501, 0.1),
                PositionFactory::long(SymbolEnum::BTCUSDT, 100500, 0.01),
                true
            ],
            [
                PositionFactory::short(SymbolEnum::BTCUSDT, 100500, 0.1),
                PositionFactory::long(SymbolEnum::BTCUSDT, 100500, 0.01),
                true
            ],
            [
                PositionFactory::short(SymbolEnum::BTCUSDT, 100500, 0.1),
                PositionFactory::long(SymbolEnum::BTCUSDT, 100501, 0.01),
                false
            ],
        ];
    }
}
