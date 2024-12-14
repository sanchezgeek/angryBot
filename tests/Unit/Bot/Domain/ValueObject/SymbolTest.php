<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bot\Domain\ValueObject;

use App\Bot\Domain\ValueObject\Symbol;
use PHPUnit\Framework\TestCase;

class SymbolTest extends TestCase
{
    /**
     * @dataProvider roundVolumeTestCases
     */
    public function testRoundVolume(Symbol $symbol, float $volume, float $expectedRoundedValue, float $expectedValueRoundedUp): void
    {
        self::assertSame($expectedRoundedValue, $symbol->roundVolume($volume));
        self::assertSame($expectedValueRoundedUp, $symbol->roundVolumeUp($volume));
    }

    public function roundVolumeTestCases(): array
    {
        return [
            [Symbol::BTCUSDT, 0.0001, 0.001, 0.001],
            [Symbol::BTCUSDT, 0.00002, 0.001, 0.001],
            [Symbol::BTCUSDT, 0.001, 0.001, 0.001],
            [Symbol::BTCUSDT, 0.01, 0.01, 0.01],
            [Symbol::BTCUSDT, 0.1, 0.1, 0.1],
            [Symbol::BTCUSDT, 0.101, 0.101, 0.101],
            [Symbol::BTCUSDT, 0.1010, 0.101, 0.101],
            [Symbol::BTCUSDT, 0.10149, 0.101, 0.102],
            [Symbol::BTCUSDT, 0.10150, 0.102, 0.102],

            [Symbol::ADAUSDT, 0.0001, 1, 1],
            [Symbol::ADAUSDT, 0.02, 1, 1],
            [Symbol::ADAUSDT, 0.1, 1, 1],
            [Symbol::ADAUSDT, 1.1, 1, 2],
            [Symbol::ADAUSDT, 1.49, 1, 2],
            [Symbol::ADAUSDT, 1.5, 2, 2],

            [Symbol::OPUSDT, 0.0001, 0.1, 0.1],
            [Symbol::OPUSDT, 0.001, 0.1, 0.1],
            [Symbol::OPUSDT, 0.01, 0.1, 0.1],
            [Symbol::OPUSDT, 0.1, 0.1, 0.1],
            [Symbol::OPUSDT, 1.11, 1.1, 1.2],
            [Symbol::OPUSDT, 1.149, 1.1, 1.2],
            [Symbol::OPUSDT, 1.15, 1.2, 1.2],
            [Symbol::OPUSDT, 1.24999, 1.2, 1.3],
            [Symbol::OPUSDT, 1.25, 1.3, 1.3],

            [Symbol::AAVEUSDT, 0.0001, 0.01, 0.01],
            [Symbol::AAVEUSDT, 0.001, 0.01, 0.01],
            [Symbol::AAVEUSDT, 0.01, 0.01, 0.01],
            [Symbol::AAVEUSDT, 0.1, 0.1, 0.1],
            [Symbol::AAVEUSDT, 1.11, 1.11, 1.12],
            [Symbol::AAVEUSDT, 1.1449, 1.14, 1.15],
            [Symbol::AAVEUSDT, 1.145, 1.15, 1.15],
            [Symbol::AAVEUSDT, 1.15, 1.15, 1.15],
            [Symbol::AAVEUSDT, 1.2449, 1.24, 1.25],
            [Symbol::AAVEUSDT, 1.245, 1.25, 1.25],
            [Symbol::AAVEUSDT, 1.24999, 1.25, 1.25],
            [Symbol::AAVEUSDT, 1.25, 1.25, 1.25],
            [Symbol::AAVEUSDT, 1.251, 1.25, 1.26],
        ];
    }
}