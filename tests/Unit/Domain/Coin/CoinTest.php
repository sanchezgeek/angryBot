<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Coin;

use App\Domain\Coin\Coin;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Coin\Coin
 */
final class CoinTest extends TestCase
{
    /**
     * @dataProvider precisionProvider
     */
    public function testPrecision(Coin $coin, int $expectedPrecision): void
    {
        self::assertEquals($expectedPrecision, $coin->coinCostPrecision());
    }

    private function precisionProvider(): array
    {
        return [
            [Coin::USDT, 3],
            [Coin::BTC, 8],
        ];
    }
}
