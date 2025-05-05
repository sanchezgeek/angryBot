<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Settings;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\Logger\SymfonyAppErrorLogger;
use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use App\Settings\Application\Storage\SettingsStorageInterface;
use App\Settings\Application\Storage\StoredSettingsProviderInterface;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AppSettingsServiceTest extends TestCase
{
    private StoredSettingsProviderInterface|MockObject $storedParametersProvider;
    private AppSettingsProviderInterface|MockObject $settingsService;
    private SettingsStorageInterface|MockObject $settingsStorage;

    protected function setUp(): void
    {
        $this->storedParametersProvider = $this->createMock(StoredSettingsProviderInterface::class);
        $this->settingsStorage = $this->createMock(SettingsStorageInterface::class);

        $this->settingsService = new AppSettingsService(
            new ArrayAdapter(),
            new SymfonyAppErrorLogger($this->createMock(LoggerInterface::class)),
            $this->settingsStorage,
            [$this->storedParametersProvider],
        );
    }

    /**
     * @dataProvider getSettingValueByAccessorTestCases
     */
    public function testGetSettingValueByAccessor(array $existentSettings, SettingAccessor $providedAccessor, mixed $expectedValue): void
    {
        $this->mockExistedSettings($providedAccessor->setting, $existentSettings);

        $result = $this->settingsService->get($providedAccessor);

        self::assertEquals($expectedValue, $result);
    }

    public function getSettingValueByAccessorTestCases(): iterable
    {
        $existentSettings = [
            'safePriceDistance.percent[symbol=ARCUSDT][side=sell]' => 10,
            'safePriceDistance.percent[symbol=ARCUSDT]' => 20,
            'safePriceDistance.percent' => 30,
        ];

        yield [$existentSettings, SettingAccessor::bySide(SafePriceDistanceSettings::SafePriceDistance_Percent, Symbol::ARCUSDT, Side::Sell), 10];
        yield [$existentSettings, SettingAccessor::bySymbol(SafePriceDistanceSettings::SafePriceDistance_Percent, Symbol::ARCUSDT), 20];
        yield [$existentSettings, SettingAccessor::simple(SafePriceDistanceSettings::SafePriceDistance_Percent), 30];
    }

    public function testWithCache(): void
    {
        $setting = SafePriceDistanceSettings::SafePriceDistance_Percent;

        $settingValueAccessor = SettingAccessor::bySide($setting, Symbol::ARCUSDT, Side::Sell);
        $values = ['safePriceDistance.percent[symbol=ARCUSDT][side=sell]' => 10];
        $this->mockExistedSettings($setting, $values); # with &

        $result = $this->settingsService->get($settingValueAccessor, ttl: '1 second');
        self::assertEquals(10, $result);

        $values = ['safePriceDistance.percent[symbol=ARCUSDT][side=sell]' => 30];
        $result = $this->settingsService->get($settingValueAccessor);
        self::assertEquals(10, $result);

        sleep(1);
        $values = ['safePriceDistance.percent[symbol=ARCUSDT][side=sell]' => 40];
        $result = $this->settingsService->get($settingValueAccessor);
        self::assertEquals(40, $result);
    }

    public function testDisableSetting(): void
    {
        $setting = SafePriceDistanceSettings::SafePriceDistance_Percent;

        $settingValueAccessor = SettingAccessor::bySide($setting, Symbol::ARCUSDT, Side::Sell);

        $this->settingsStorage->expects(self::once())->method('store')->with(
            $settingValueAccessor, null
        );

        $this->settingsService->disable($settingValueAccessor);
    }

    private function mockExistedSettings(SettingKeyAware $setting, array &$existentSettings): void
    {
        $this->storedParametersProvider->method('getSettingStoredValues')->with($setting)->willReturnCallback(static function (SettingKeyAware $providedSetting) use (&$existentSettings, $setting) {
            $storedValues = [];
            foreach ($existentSettings as $key => $value) {
                if (!str_contains($key, $providedSetting->getSettingKey())) continue;
                $storedValues[] = new AssignedSettingValue($setting, $key, $value);
            }

            return $storedValues;
        });
    }
}
