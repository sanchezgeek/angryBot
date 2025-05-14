<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Trading\Application\Parameters;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Trading\Application\Parameters\TradingDynamicParameters;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers TradingDynamicParameters
 *
 * @group parameters
 */
final class TradingParametersProviderTest extends KernelTestCase
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
    public function testSafeDistanceOnRefPriceDefault(Symbol $symbol, Side $positionSide, float $refPrice, float $expectedSafeDistance, ?float $k = null): void
    {
        if ($k) {
            self::overrideSetting(SafePriceDistanceSettings::SafePriceDistance_Multiplier, $k);
        }

        $parameters = new TradingDynamicParameters($this->appSettingsProvider);

        $result = $parameters->safeLiquidationPriceDelta($symbol, $positionSide, $refPrice);

        self::assertEquals($expectedSafeDistance, $result);
    }

    public function defaultValueCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 94835.93);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 74835.93);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 54835.93);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 34835.93);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 24835.93);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 14835.93);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 3824);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 2824);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 1824);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 606);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 388);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 148);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 80.5112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 50.5112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 10.5112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 5.5112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 2.5112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 1.5112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 0.9112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 0.7112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 0.5112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 0.3112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 0.1112);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 0.09351);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 0.03351);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 0.01351);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [$symbol, $side, $refPrice, $expectedSafeDistance];

        $k = 1.5;
        $expectedSafeDistance = self::getExpectedSafeDistance($symbol, $side, $refPrice = 0.01351, $k);
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance, $k) => [$symbol, $side, $refPrice, $expectedSafeDistance, $k];
    }

    /**
     * @dataProvider casesWithOverride
     */
    public function testSafeDistanceOnRefPriceWithOverride(float $overridePercent, Symbol $symbol, Side $positionSide, float $refPrice, float $expectedSafeDistance): void
    {
        self::overrideSetting(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, $symbol, $positionSide), $overridePercent);

        $parameters = new TradingDynamicParameters($this->appSettingsProvider);

        $result = $parameters->safeLiquidationPriceDelta($symbol, $positionSide, $refPrice);

        self::assertEquals($expectedSafeDistance, $result);
    }

    public function casesWithOverride(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $overridePercent = 5;
        $refPrice = 100000;
        $expectedSafeDistance = 5000;
        yield self::caseDescriptionWithOverride($overridePercent, $symbol, $side, $refPrice, $expectedSafeDistance) => [$overridePercent, $symbol, $side, $refPrice, $expectedSafeDistance];
    }

    private static function getExpectedSafeDistance(Symbol $symbol, Side $side, float $refPrice, float $k = 1): float
    {
        $base = match (true) {
            $refPrice >= 10000 => $refPrice / 12,
            $refPrice >= 5000 => $refPrice / 10,
            $refPrice >= 2000 => $refPrice / 9,
            $refPrice >= 1500 => $refPrice / 8,
            $refPrice >= 1000 => $refPrice / 6,
            $refPrice >= 100 => $refPrice / 4,
            $refPrice >= 1 => $refPrice / 3,
            $refPrice >= 0.1 => $refPrice / 2.5,
            $refPrice >= 0.05 => $refPrice / 2,
            $refPrice >= 0.03 => $refPrice,
            default => $refPrice * 1.4,
        };

        return $base * $k;
    }

    private function caseDescription(Symbol $symbol, Side $positionSide, float $refPrice, float $expectedSafeDistance, ?float $k = null): string
    {
        $pct = Percent::fromPart($expectedSafeDistance / $refPrice, false);

        return sprintf('%s, %s, %s%s => %s (%s)', $symbol->value, $positionSide->value, $refPrice, $k !== null ? sprintf(', k=%s', $k) : null, $expectedSafeDistance, $pct);
    }

    private function caseDescriptionWithOverride(float $overridePercent, Symbol $symbol, Side $positionSide, float $refPrice, float $expectedSafeDistance): string
    {
        $pct = new Percent($overridePercent, false);

        return sprintf('[override percent with %s] %s, %s, %s => %s (%s)', $overridePercent, $symbol->value, $positionSide->value, $refPrice, $expectedSafeDistance, $pct);
    }
}
