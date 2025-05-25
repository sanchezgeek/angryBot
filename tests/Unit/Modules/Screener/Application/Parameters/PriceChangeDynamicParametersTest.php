<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Screener\Application\Parameters;

use App\Domain\Value\Percent\Percent;
use App\Screener\Application\Parameters\PriceChangeDynamicParameters;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers PriceChangeDynamicParameters
 *
 * @group parameters
 */
final class PriceChangeDynamicParametersTest extends KernelTestCase
{
    use SettingsAwareTest;

    private AppSettingsProviderInterface|MockObject $appSettingsProvider;

    protected function setUp(): void
    {
        $this->appSettingsProvider = self::getContainerSettingsProvider();
    }

    /**
     * @dataProvider defaultValueCases
     */
    public function testAlarmPriceChange(float $currentPrice, float $expectedPercent): void
    {
        $expectedPriceChange = $currentPrice * ($expectedPercent / 100);
        $parameters = new PriceChangeDynamicParameters($this->appSettingsProvider);

        self::assertEquals(Percent::notStrict($expectedPercent), $parameters->significantPricePercent($currentPrice, 1));
        self::assertEquals($expectedPriceChange, $parameters->significantPriceDelta($currentPrice, 1));
    }

    public function defaultValueCases(): iterable
    {
        $expectedPercent = self::defaultPercent($currentPrice = 94835.93);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 74835.93);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 54835.93);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 30000);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 20000);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 14835.93);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 5000);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 3824);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 2824);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 1824);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 1000);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 606);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 388);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 148);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 80.5112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 50.5112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 25.5112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 10.5112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 5.5112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 2.5112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 1.5112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 0.9112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 0.7112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 0.5112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 0.3112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 0.1112);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 0.09351);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 0.03351);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];

        $expectedPercent = self::defaultPercent($currentPrice = 0.01351);
        yield self::caseDescription($currentPrice, $expectedPercent) => [$currentPrice, $expectedPercent];
    }

    private static function defaultPercent(float $currentPrice): float
    {
        // попробовать поставить меньше, потом редактировать
        return match (true) {
//            $currentPrice >= 30000 => 1,
//            $currentPrice >= 20000 => 2,
            $currentPrice >= 15000 => 1,
            $currentPrice >= 5000 => 2,
            $currentPrice >= 3000 => 3,
            $currentPrice >= 2000 => 4,
            $currentPrice >= 1500 => 5,
            $currentPrice >= 1000 => 6,
            $currentPrice >= 500 => 7,
            $currentPrice >= 100 => 8,
            $currentPrice >= 50 => 9,
            $currentPrice >= 25 => 10,
            $currentPrice >= 10 => 13,
            $currentPrice >= 5 => 14,
            $currentPrice >= 2.5 => 16,
            $currentPrice >= 1 => 17,
            $currentPrice >= 0.7 => 18,
////            $currentPrice >= 0.3 => 200,
//            $currentPrice >= 0.1 => 100,
//            $currentPrice >= 0.05 => 200,
//            $currentPrice >= 0.03 => 300,
            default => 20,
        };
    }

    private function caseDescription(float $currentPrice, float $expectedPercent): string
    {
        $expectedPriceChange = $currentPrice * ($expectedPercent / 100);

        return sprintf('%s => %s (%s)', $currentPrice, $expectedPriceChange, Percent::notStrict($expectedPercent)->setOutputFloatPrecision(2));
    }
}
