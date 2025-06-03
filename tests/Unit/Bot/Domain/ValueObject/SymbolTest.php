<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bot\Domain\ValueObject;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use PHPUnit\Framework\TestCase;

class SymbolTest extends TestCase
{
    /**
     * @dataProvider roundVolumeTestCases
     */
    public function testRoundVolume(SymbolInterface $symbol, float $volume, float $expectedRoundedValue, float $expectedValueRoundedUp): void
    {
        self::assertSame($expectedRoundedValue, $symbol->roundVolume($volume));
        self::assertSame($expectedValueRoundedUp, $symbol->roundVolumeUp($volume));
    }

    public function roundVolumeTestCases(): array
    {
        return [
            [SymbolEnum::BTCUSDT, 0.000005, 0.001, 0.001],
            [SymbolEnum::BTCUSDT, 0.0013, 0.001, 0.002],
            [SymbolEnum::BTCUSDT, 0.0015, 0.002, 0.002],
            [SymbolEnum::BTCUSDT, 0.0025, 0.003, 0.003],
            [SymbolEnum::BTCUSDT, 0.0028, 0.003, 0.003],
            [SymbolEnum::BTCUSDT, 0.025, 0.025, 0.025],
            [SymbolEnum::BTCUSDT, 0.010, 0.01, 0.01],
            [SymbolEnum::BTCUSDT, 0.0101, 0.01, 0.011],

            [SymbolEnum::BTCUSDT, 0.0001, 0.001, 0.001],
            [SymbolEnum::BTCUSDT, 0.00002, 0.001, 0.001],
            [SymbolEnum::BTCUSDT, 0.001, 0.001, 0.001],
            [SymbolEnum::BTCUSDT, 0.01, 0.01, 0.01],
            [SymbolEnum::BTCUSDT, 0.1, 0.1, 0.1],
            [SymbolEnum::BTCUSDT, 0.101, 0.101, 0.101],
            [SymbolEnum::BTCUSDT, 0.1010, 0.101, 0.101],
            [SymbolEnum::BTCUSDT, 0.10149, 0.101, 0.102],
            [SymbolEnum::BTCUSDT, 0.10150, 0.102, 0.102],

            [SymbolEnum::ADAUSDT, 0.0001, 1, 1],
            [SymbolEnum::ADAUSDT, 0.02, 1, 1],
            [SymbolEnum::ADAUSDT, 0.1, 1, 1],
            [SymbolEnum::ADAUSDT, 1, 1, 1],
            [SymbolEnum::ADAUSDT, 1.1, 1, 2],
            [SymbolEnum::ADAUSDT, 1.49, 1, 2],
            [SymbolEnum::ADAUSDT, 1.5, 2, 2],

            [SymbolEnum::OPUSDT, 0.0001, 0.1, 0.1],
            [SymbolEnum::OPUSDT, 0.001, 0.1, 0.1],
            [SymbolEnum::OPUSDT, 0.01, 0.1, 0.1],
            [SymbolEnum::OPUSDT, 0.1, 0.1, 0.1],
            [SymbolEnum::OPUSDT, 1.1, 1.1, 1.1],
            [SymbolEnum::OPUSDT, 1.11, 1.1, 1.2],
            [SymbolEnum::OPUSDT, 1.149, 1.1, 1.2],
            [SymbolEnum::OPUSDT, 1.15, 1.2, 1.2],
            [SymbolEnum::OPUSDT, 1.24999, 1.2, 1.3],
            [SymbolEnum::OPUSDT, 1.25, 1.3, 1.3],

            [SymbolEnum::AAVEUSDT, 0.0001, 0.01, 0.01],
            [SymbolEnum::AAVEUSDT, 0.001, 0.01, 0.01],
            [SymbolEnum::AAVEUSDT, 0.01, 0.01, 0.01],
            [SymbolEnum::AAVEUSDT, 0.1, 0.1, 0.1],
            [SymbolEnum::AAVEUSDT, 1.11, 1.11, 1.12],
            [SymbolEnum::AAVEUSDT, 1.1449, 1.14, 1.15],
            [SymbolEnum::AAVEUSDT, 1.145, 1.15, 1.15],
            [SymbolEnum::AAVEUSDT, 1.15, 1.15, 1.15],
            [SymbolEnum::AAVEUSDT, 1.2449, 1.24, 1.25],
            [SymbolEnum::AAVEUSDT, 1.245, 1.25, 1.25],
            [SymbolEnum::AAVEUSDT, 1.24999, 1.25, 1.25],
            [SymbolEnum::AAVEUSDT, 1.25, 1.25, 1.25],
            [SymbolEnum::AAVEUSDT, 1.251, 1.25, 1.26],
        ];
    }

    /**
     * @dataProvider defaultTriggerDeltaTestCases
     */
    public function testDefaultTriggerDelta(SymbolInterface $symbol, float $expectedDistance): void
    {
        self::assertSame($expectedDistance, $symbol->stopDefaultTriggerDelta());
    }

    public function defaultTriggerDeltaTestCases(): array
    {
        return [
            [SymbolEnum::BTCUSDT, 25],
            [SymbolEnum::BTCUSD, 25],
            [SymbolEnum::LINKUSDT, 0.01],
            [SymbolEnum::ADAUSDT, 0.001],
            [SymbolEnum::TONUSDT, 0.001],
            [SymbolEnum::ETHUSDT, 0.1],
            [SymbolEnum::XRPUSDT, 0.001],
            [SymbolEnum::SOLUSDT, 0.01],
            [SymbolEnum::WIFUSDT, 0.001],
            [SymbolEnum::OPUSDT, 0.001],
            [SymbolEnum::DOGEUSDT, 0.0001],
            [SymbolEnum::SUIUSDT, 0.0001],
            [SymbolEnum::AAVEUSDT, 0.1],
            [SymbolEnum::AVAXUSDT, 0.1],
            [SymbolEnum::LTCUSDT, 0.1],
        ];
    }
}
