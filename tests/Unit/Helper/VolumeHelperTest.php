<?php

declare(strict_types=1);

namespace App\Tests\Unit\Helper;

use App\Helper\VolumeHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Helper\VolumeHelper
 */
class VolumeHelperTest extends TestCase
{
    /**
     * @dataProvider roundTestDataProvider
     */
    public function testRound(float $raw, float $expectedResult): void
    {
        $result = VolumeHelper::round($raw);

        self::assertEquals($expectedResult, $result);
    }

    public function roundTestDataProvider(): array
    {
        return [
            [0.000005, 0.001],
            [0.0013, 0.001],
            [0.0015, 0.002],
            [0.0025, 0.003],
            [0.0028, 0.003],
            [0.025, 0.025],
            [0.01, 0.01],
            [0.010, 0.01],
            [0.0101, 0.01],
        ];
    }

    /**
     * @dataProvider forceRoundUpTestDataProvider
     */
    public function testForceRoundUp(float $raw, float $expectedResult): void
    {
        $result = VolumeHelper::forceRoundUp($raw);

        self::assertEquals($expectedResult, $result);
    }

    public function forceRoundUpTestDataProvider(): array
    {
        return [
            [0.000005, 0.001],
            [0.0013, 0.002],
            [0.0015, 0.002],
            [0.0025, 0.003],
            [0.0028, 0.003],
            [0.025, 0.025],
            [0.01, 0.01],
            [0.010, 0.01],
            [0.0101, 0.011],
        ];
    }
}