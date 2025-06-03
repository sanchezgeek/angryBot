<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Settings;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
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
use App\Settings\Domain\Entity\SettingValue;
use App\Tests\Mixin\TestWithDoctrineRepository;
use App\Trading\Application\Settings\SafePriceDistanceSettings;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

trait SettingsAwareTest
{
    use TestWithDoctrineRepository;

    /**
     * @before
     */
    protected function before(): void
    {
        if ($this instanceof KernelTestCase) {
            self::truncateStoredSettings();
        }
    }

    protected static function getSettingsService(): AppSettingsService
    {
        return self::getContainer()->get(AppSettingsService::class);
    }

    protected static function getSettingsStorage(): SettingsStorageInterface
    {
        return self::getContainer()->get(SettingsStorageInterface::class);
    }

    protected static function getContainerSettingsProvider(): AppSettingsProviderInterface
    {
        return self::getContainer()->get(AppSettingsProviderInterface::class);
    }

    protected static function getSettingValue(AppSettingInterface $setting): mixed
    {
        return self::getContainerSettingsProvider()->required($setting);
    }

    protected function overrideSetting(AppSettingInterface|SettingAccessor $setting, mixed $value): void
    {
        $settingAccessor = $setting instanceof SettingAccessor ? $setting : SettingAccessor::exact($setting);

        self::getSettingsService()->set($settingAccessor, $value);
    }

    protected function setMinimalSafePriceDistance(SymbolInterface $symbol, Side $positionSide, float $pricePercent = 0.1): void
    {
        # @todo | buyIsSafe | for now to prevent MarketBuyHandler "buyIsSafe" checks
        $this->overrideSetting(SettingAccessor::exact(SafePriceDistanceSettings::SafePriceDistance_Percent, $symbol, $positionSide), $pricePercent);
    }

    protected static function truncateStoredSettings(): int
    {
        $qnt = self::truncate(SettingValue::class);

        $entityManager = self::getEntityManager();
        $entityManager->getConnection()->executeQuery('SELECT setval(\'setting_value_id_seq\', 1, false);');

        return $qnt;
    }

    protected function settingsProviderMock(array $existentSettings): AppSettingsProviderInterface
    {
        $storedParametersProvider = $this->createMock(StoredSettingsProviderInterface::class);
        $settingsStorage = $this->createMock(SettingsStorageInterface::class);

        $settingsService = new AppSettingsService(
            new SettingsCache(new SymfonyCacheWrapper(new ArrayAdapter())),
            new SymfonyAppErrorLogger($this->createMock(LoggerInterface::class)),
            $settingsStorage,
            [$storedParametersProvider],
        );

        $storedParametersProvider->method('getSettingStoredValues')->willReturnCallback(static function (AppSettingInterface $providedSetting) use ($existentSettings) {
            $storedValues = [];
            foreach ($existentSettings as $key => $value) {
                if (!str_contains($key, $providedSetting->getSettingKey())) continue;
                [$symbol, $side] = AssignedSettingValueFactory::parseSymbolAndSide($key);
                $storedValues[] = new AssignedSettingValue($providedSetting, $symbol, $side, $key, $value);
            }

            return $storedValues;
        });

        return $settingsService;
    }
}
