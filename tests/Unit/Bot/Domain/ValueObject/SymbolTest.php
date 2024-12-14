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
            [Symbol::BTCUSDT, 0.000005, 0.001, 0.001],
            [Symbol::BTCUSDT, 0.0013, 0.001, 0.002],
            [Symbol::BTCUSDT, 0.0015, 0.002, 0.002],
            [Symbol::BTCUSDT, 0.0025, 0.003, 0.003],
            [Symbol::BTCUSDT, 0.0028, 0.003, 0.003],
            [Symbol::BTCUSDT, 0.025, 0.025, 0.025],
            [Symbol::BTCUSDT, 0.010, 0.01, 0.01],
            [Symbol::BTCUSDT, 0.0101, 0.01, 0.011],

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

    /**
     * @dataProvider defaultTriggerDeltaTestCases
     */
    public function testDefaultTriggerDelta(Symbol $symbol, float $expectedDistance): void
    {
        self::assertSame($expectedDistance, $symbol->stopDefaultTriggerDelta());
    }

    public function defaultTriggerDeltaTestCases(): array
    {
        return [
            [Symbol::BTCUSDT,   25],
            [Symbol::BTCUSD,    25],
            [Symbol::LINKUSDT,  0.01],
            [Symbol::ADAUSDT,   0.001],
            [Symbol::TONUSDT,   0.001],
            [Symbol::ETHUSDT,   0.1],
            [Symbol::XRPUSDT,   0.001],
            [Symbol::SOLUSDT,   0.01],
            [Symbol::WIFUSDT,   0.001],
            [Symbol::OPUSDT,    0.001],
            [Symbol::DOGEUSDT,  0.0001],
            [Symbol::SUIUSDT,   0.0001],
            [Symbol::AAVEUSDT,  0.1],
            [Symbol::AVAXUSDT,  0.1],
            [Symbol::LTCUSDT,   0.1],
        ];
    }

    /**
     * @dataProvider byMarketTdTestCases
     */
    public function testByMarketTd(Symbol $symbol, float $expectedDistance): void
    {
        self::assertSame($expectedDistance, $symbol->byMarketTd());
    }

    public function byMarketTdTestCases(): array
    {
        return [
            [Symbol::BTCUSDT,   0.01],
            [Symbol::BTCUSD,    0.01],
            [Symbol::LINKUSDT,  0.001],
            [Symbol::ADAUSDT,   0.0001],
            [Symbol::TONUSDT,   0.0001],
            [Symbol::ETHUSDT,   0.01],
            [Symbol::XRPUSDT,   0.0001],
            [Symbol::SOLUSDT,   0.001],
            [Symbol::WIFUSDT,   0.0001],
            [Symbol::OPUSDT,    0.0001],
            [Symbol::DOGEUSDT,  0.00001],
            [Symbol::SUIUSDT,   0.00001],
            [Symbol::AAVEUSDT,  0.01],
            [Symbol::AVAXUSDT,  0.01],
            [Symbol::LTCUSDT,   0.01],
        ];
    }
}