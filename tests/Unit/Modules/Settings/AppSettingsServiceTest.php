<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Settings;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\Cache\SymfonyCacheWrapper;
use App\Infrastructure\Logger\SymfonyAppErrorLogger;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Service\SettingsCache;
use App\Settings\Application\Storage\AssignedSettingValueFactory;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use App\Settings\Application\Storage\SettingsStorageInterface;
use App\Settings\Application\Storage\StoredSettingsProviderInterface;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @group settings
 */
final class AppSettingsServiceTest extends TestCase
{
    private StoredSettingsProviderInterface|MockObject $storedParametersProvider;
    private AppSettingsProviderInterface|MockObject $settingsService;

    protected function setUp(): void
    {
        $this->storedParametersProvider = $this->createMock(StoredSettingsProviderInterface::class);
        $settingsStorage = $this->createMock(SettingsStorageInterface::class);

        $this->settingsService = new AppSettingsService(
            new SettingsCache(new SymfonyCacheWrapper(new ArrayAdapter())),
            new SymfonyAppErrorLogger($this->createMock(LoggerInterface::class)),
            $settingsStorage,
            [$this->storedParametersProvider],
        );
    }

    public function testWithCache(): void
    {
        $setting = TestSetting::Test;

        $settingValueAccessor = SettingAccessor::exact($setting, Symbol::ARCUSDT, Side::Sell);
        $values = ['test.test[symbol=ARCUSDT][side=sell]' => 10];
        $this->mockExistedSettings($setting, $values); # with &

        $result = $this->settingsService->required($settingValueAccessor);
        self::assertEquals(10, $result);

        $values = ['test.test[symbol=ARCUSDT][side=sell]' => 30];
        $result = $this->settingsService->required($settingValueAccessor);
        self::assertEquals(10, $result);

        sleep(1);
        $values = ['test.test[symbol=ARCUSDT][side=sell]' => 40];
        $result = $this->settingsService->required($settingValueAccessor);
        self::assertEquals(40, $result);
    }

    private function mockExistedSettings(AppSettingInterface $setting, array &$existentSettings): void
    {
        $this->storedParametersProvider->method('getSettingStoredValues')->with($setting)->willReturnCallback(static function (AppSettingInterface $providedSetting) use (&$existentSettings, $setting) {
            $storedValues = [];
            foreach ($existentSettings as $key => $value) {
                if (!str_contains($key, $providedSetting->getSettingKey())) continue;
                [$symbol, $side] = AssignedSettingValueFactory::parseSymbolAndSide($key);
                $storedValues[] = new AssignedSettingValue($setting, $symbol, $side, $key, $value);
            }

            return $storedValues;
        });
    }
}
