<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Trading\Application\Parameters;

use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Application\Settings\TradingSettings;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Coin\Coin;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Tests\Mixin\Balance\ContractBalanceBalanceMocker;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\TA\TaToolsProviderMocker;
use App\Trading\Application\Parameters\TradingDynamicParameters;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use App\Trading\Contract\ContractBalanceProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers TradingDynamicParameters
 *
 * @group parameters
 */
final class TradingParametersProviderTest extends KernelTestCase
{
    use SettingsAwareTest;
    use TaToolsProviderMocker;
    use ContractBalanceBalanceMocker;

    const int LONG_ATR_PERIOD = TradingParametersProviderInterface::LONG_ATR_PERIOD;
    const int FAST_ATR_PERIOD = 2;

    private AppSettingsProviderInterface|MockObject $appSettingsProvider;
    private TradingDynamicParameters $parameters;

    protected function setUp(): void
    {
        $this->appSettingsProvider = self::getContainerSettingsProvider();
        $this->initializeTaProviderStub();
        $this->initializeContractBalanceProviderMock();

        $this->mockContractBalance(new ContractBalance(Coin::USDT, 100, 100, 100, 100));

        $this->parameters = self::getContainer()->get(TradingParametersProviderInterface::class);
    }

    /**
     * @dataProvider defaultValueCases
     */
    public function testSafeDistanceOnRefPriceDefault(
        SymbolInterface $symbol,
        Side $positionSide,
        float $refPrice,
        float $longAtrPercentChange,
        float $fastAtrPercentChange,
        float $expectedSafeDistance,
        ?float $k = null
    ): void {
        self::overrideSetting(SettingAccessor::exact(TradingSettings::Global_RiskLevel, $symbol, $positionSide), RiskLevel::Conservative);

        if ($k) {
            self::overrideSetting(SafePriceDistanceSettings::SafePriceDistance_Multiplier, $k);
        }

        $this->analysisToolsProviderStub->mockedTaTools($symbol, TimeFrame::D1)->addAtrResult(
            period: self::LONG_ATR_PERIOD,
            percentChange: $longAtrPercentChange,
            refPrice: $refPrice
        );

        $this->analysisToolsProviderStub->mockedTaTools($symbol, TimeFrame::D1)->addAtrResult(
            self::FAST_ATR_PERIOD,
            percentChange: $fastAtrPercentChange,
            refPrice: $refPrice
        );

        $result = $this->parameters->safeLiquidationPriceDelta($symbol, $positionSide, $refPrice);

        self::assertEquals($expectedSafeDistance, $result);
    }

    public function defaultValueCases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $side = Side::Sell;

        $refPrice = 100500;
        $longAtrPercentChange = 2;
        $fastAtrPercentChange = 4;

        $expectedSafeDistance = 8040;
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance) => [
            $symbol,
            $side,
            $refPrice,
            $longAtrPercentChange,
            $fastAtrPercentChange,
            $expectedSafeDistance,
        ];

        $k = 3;
        $expectedSafeDistance = 12060;
        yield self::caseDescription($symbol, $side, $refPrice, $expectedSafeDistance, $k) => [
            $symbol,
            $side,
            $refPrice,
            $longAtrPercentChange,
            $fastAtrPercentChange,
            $expectedSafeDistance,
            $k,
        ];
    }

    private function contractBalanceProviderMock(): ContractBalanceProviderInterface
    {
        $contractBalanceProvider = $this->createMock(ContractBalanceProviderInterface::class);
        $contractBalanceProvider->method('getContractWalletBalance')->willReturn(new ContractBalance(Coin::USDT, 100, 100, 100, 100));

        return $contractBalanceProvider;
    }

    /**
     * @dataProvider casesWithOverride
     */
    public function testSafeDistanceOnRefPriceWithOverride(float $overridePercent, SymbolInterface $symbol, Side $positionSide, float $refPrice, float $expectedSafeDistance): void
    {
        self::overrideSetting(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, $symbol, $positionSide), $overridePercent);

        $result = $this->parameters->safeLiquidationPriceDelta($symbol, $positionSide, $refPrice);

        self::assertEquals($expectedSafeDistance, $result);
    }

    public function casesWithOverride(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $side = Side::Sell;
        $overridePercent = 5;
        $refPrice = 100000;
        $expectedSafeDistance = 5000;
        yield self::caseDescriptionWithOverride($overridePercent, $symbol, $side, $refPrice, $expectedSafeDistance) => [$overridePercent, $symbol, $side, $refPrice, $expectedSafeDistance];
    }

    private function caseDescription(SymbolInterface $symbol, Side $positionSide, float $refPrice, float $expectedSafeDistance, ?float $k = null): string
    {
        $pct = Percent::fromPart($expectedSafeDistance / $refPrice, false);

        return sprintf('%s, %s, %s%s => %s (%s)', $symbol->name(), $positionSide->value, $refPrice, $k !== null ? sprintf(', k=%s', $k) : null, $expectedSafeDistance, $pct);
    }

    private function caseDescriptionWithOverride(float $overridePercent, SymbolInterface $symbol, Side $positionSide, float $refPrice, float $expectedSafeDistance): string
    {
        $pct = new Percent($overridePercent, false);

        return sprintf('[override percent with %s] %s, %s, %s => %s (%s)', $overridePercent, $symbol->name(), $positionSide->value, $refPrice, $expectedSafeDistance, $pct);
    }
}
