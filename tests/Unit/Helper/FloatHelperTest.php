<?php

declare(strict_types=1);

namespace App\Tests\Unit\Helper;

use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Helper\FloatHelper
 */
class FloatHelperTest extends TestCase
{
    /**
     * @dataProvider roundTestDataProvider
     */
    public function testRound(float $raw, float $expectedResult, int $precision = null): void
    {
        $result = FloatHelper::round($raw, $precision);

        self::assertEquals($expectedResult, $result);
    }

    public function roundTestDataProvider(): array
    {
        return [
            [0.000000001, 0.001],
            [0.000000001, 0.001, 3],
            [0.000000001, 0.0001, 4],
            [0.000000001, 0.01, 2],
            [0.000000001, 0.1, 1],
            [0.000005, 0.001],
            [0.0013, 0.001],
            [0.0015, 0.002],
            [0.0025, 0.003],
            [0.0028, 0.003],
            [0.025, 0.025],
            [0.025, 0.03, 2],
            [1000.0259, 1000.026],
            [1000.025, 1000.03, 2],
            [1000.0259, 1000.03, 2],
            [0.01, 0.01],
            [0.0101, 0.01],
            [0.0109, 0.011],
            [0.0109, 0.01, 2],
            [0.0159, 0.02, 2],
            [0.0101, 0.01],
        ];
    }

    /**
     * @dataProvider modifyTestDataProvider
     */
    public function testModify(float $raw, float $subModifier, ?float $addModifier): void
    {
        $result = FloatHelper::modify($raw, $subModifier, $addModifier, force: true);

        OutputHelper::print('');
        var_dump(sprintf('%.6f, %.3f, , %.3f, %.8f', $raw, $subModifier, $addModifier, $result));

        self::assertTrue(true);
    }

    public function modifyTestDataProvider(): array
    {
        return [
            [0.0005, 0.8, 0.01],
            [0.00005, 0.8, 0.01],
            [0.000005, 0.8, 0.01],
            [0.05, 0.8, 0.01],
            [1.05, 0.1, 0.1],
            [0.001, 0.9, 0.1],
            [0.001, 0.9, null],
            [100, 0.9, 0.1],
        ];
    }
}
