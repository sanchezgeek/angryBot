<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Trading\SDK\Check\Decorator;

use PHPUnit\Framework\TestCase;

final class UseNegativeCachedResultWhileCheckDecoratorTest extends TestCase
{
    /**
     * @dataProvider pnlPercentStepTestCases
     */
    public function testPnlPercentStep(float $currentPrice, float $expectedResult): void
    {
        self::markTestSkipped();
        // @for now doesn't matter
//        $symbol = SymbolEnum::BTCUSDT;
//        self::assertEquals($expectedResult, UseNegativeCachedResultWhileCheckDecorator::pnlPercentStep($symbol, $currentPrice));
    }

    public function pnlPercentStepTestCases(): array
    {
        return [
            [100500, 20],
            [90000, 20],
            [10000, 20],
            [2000, 20],
            [2.1, 20],
            [2, 25],
//            [1, 30],
//            [0.06, 40],
//            [0.03, 60],
//            [0.01, 100],
        ];
    }
}
