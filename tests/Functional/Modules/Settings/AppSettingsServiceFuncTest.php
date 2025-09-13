<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Settings;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingAccessor;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Unit\Modules\Settings\TestSetting;
use App\Trading\Application\Settings\CoverLossSettings;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group settings
 *
 * @covers AppSettingsService
 */
final class AppSettingsServiceFuncTest extends KernelTestCase
{
    use SettingsAwareTest;

    private readonly AppSettingsService $settingsService;

    protected function setUp(): void
    {
        $this->settingsService = self::getContainerSettingsProvider();
    }

    /**
     * @dataProvider fullSet
     */
    public function testOnFullSet(SettingAccessor $providedAccessor, mixed $expectedValue): void
    {
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300);
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200);
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), 100);

        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(CoverLossSettings::Cover_Loss_By_OtherSymbols_AdditionalStop_Distance, null, Side::Sell), PriceDistanceSelector::VeryVeryLong);

        self::assertEquals($expectedValue, $this->settingsService->optional($providedAccessor));
    }

    public function fullSet(): iterable
    {
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent), 100];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), 100];

        yield [SettingAccessor::exact(                  CoverLossSettings::Cover_Loss_By_OtherSymbols_AdditionalStop_Distance, null, Side::Sell), PriceDistanceSelector::VeryVeryLong];


        // default from yaml
        yield [SettingAccessor::withAlternativesAllowed(CoverLossSettings::Cover_Loss_By_OtherSymbols_AdditionalStop_Distance, null, Side::Buy), PriceDistanceSelector::Standard];
        yield [SettingAccessor::exact(                  CoverLossSettings::Cover_Loss_By_OtherSymbols_AdditionalStop_Distance, null, Side::Buy), PriceDistanceSelector::Standard];
        yield [SettingAccessor::withAlternativesAllowed(CoverLossSettings::Cover_Loss_Enabled, null, Side::Buy), true];
        yield [SettingAccessor::exact(                  CoverLossSettings::Cover_Loss_Enabled, null, Side::Buy), null];
        yield [SettingAccessor::exact(                  CoverLossSettings::Cover_Loss_Enabled, null, Side::Sell), null];
        yield [SettingAccessor::withAlternativesAllowed(CoverLossSettings::Cover_Loss_Enabled, SymbolEnum::A8USDT, Side::Sell), false];
        yield [SettingAccessor::exact(                  CoverLossSettings::Cover_Loss_Enabled, SymbolEnum::A8USDT, Side::Sell), false];
        yield [SettingAccessor::withAlternativesAllowed(CoverLossSettings::Cover_Loss_Enabled, SymbolEnum::A8USDT, Side::Buy), true];

        # no values at all
        yield [SettingAccessor::withAlternativesAllowed(TestSetting::Test, SymbolEnum::A8USDT, Side::Buy), null];
        yield [SettingAccessor::exact(                  TestSetting::Test, SymbolEnum::A8USDT, Side::Buy), null];
        yield [SettingAccessor::withAlternativesAllowed(TestSetting::Test, SymbolEnum::A8USDT), null];
        yield [SettingAccessor::exact(                  TestSetting::Test, SymbolEnum::A8USDT), null];
        yield [SettingAccessor::withAlternativesAllowed(TestSetting::Test, null, Side::Buy), null];
        yield [SettingAccessor::exact(                  TestSetting::Test, null, Side::Buy), null];
        yield [SettingAccessor::withAlternativesAllowed(TestSetting::Test), null];
        yield [SettingAccessor::exact(                  TestSetting::Test), null];
    }

    /**
     * @dataProvider onlySymbolAndSideSet
     */
    public function testWhenOnlySymbolAndSideSet(SettingAccessor $providedAccessor, mixed $expectedValue): void
    {
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300);
        self::assertEquals($expectedValue, $this->settingsService->optional($providedAccessor));
    }

    public function onlySymbolAndSideSet(): iterable
    {
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), null];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), null];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent), null];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), null];
    }

    /**
     * @dataProvider onlySymbol
     */
    public function testWhenOnlySymbolSet(SettingAccessor $providedAccessor, mixed $expectedValue): void
    {
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200);
        self::assertEquals($expectedValue, $this->settingsService->optional($providedAccessor));
    }

    public function onlySymbol(): iterable
    {
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), null];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 200];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent), null];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), null];
    }

    /**
     * @dataProvider onlyRoot
     */
    public function testWhenOnlyRootSet(SettingAccessor $providedAccessor, mixed $expectedValue): void
    {
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), 100);
        self::assertEquals($expectedValue, $this->settingsService->optional($providedAccessor));
    }

    public function onlyRoot(): iterable
    {
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), null];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 100];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), null];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 100];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent), 100];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), 100];
    }

    /**
     * @dataProvider topAndRoot
     */
    public function testWhenTopAndRootSet(SettingAccessor $providedAccessor, mixed $expectedValue): void
    {
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300);
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), 100);
        self::assertEquals($expectedValue, $this->settingsService->optional($providedAccessor));
    }

    public function topAndRoot(): iterable
    {
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), null];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 100];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent), 100];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), 100];
    }

    /**
     * @dataProvider middleAndRoot
     */
    public function testWhenMiddleAndRootSet(SettingAccessor $providedAccessor, mixed $expectedValue): void
    {
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200);
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), 100);
        self::assertEquals($expectedValue, $this->settingsService->optional($providedAccessor));
    }

    public function middleAndRoot(): iterable
    {
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), null];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 200];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent), 100];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), 100];
    }

    /**
     * @dataProvider middleAndTop
     */
    public function testWhenMiddleAndTopSet(SettingAccessor $providedAccessor, mixed $expectedValue): void
    {
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300);
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200);
        self::assertEquals($expectedValue, $this->settingsService->optional($providedAccessor));
    }

    public function middleAndTop(): iterable
    {
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200];
        yield [SettingAccessor::exact(                  SafePriceDistanceSettings::SafePriceDistance_Percent), null];
        yield [SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), null];
    }

    public function testDisableSetting(): void
    {
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell), 300);
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT), 200);
        $this->overrideSetting(SettingAccessor::withAlternativesAllowed(SafePriceDistanceSettings::SafePriceDistance_Percent), 100);

        self::assertEquals(300, $this->settingsService->optional(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell)));
        self::assertEquals(200, $this->settingsService->optional(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT)));
        self::assertEquals(100, $this->settingsService->optional(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent)));

        $this->settingsService->disable(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT));

        self::assertEquals(300, $this->settingsService->optional(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT, Side::Sell)));
        self::assertEquals(null, $this->settingsService->optional(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, SymbolEnum::BTCUSDT)));
        self::assertEquals(100, $this->settingsService->optional(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent)));
    }
}
