<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Settings;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\AppSettingsService;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Storage\SettingsStorageInterface;
use App\Settings\Domain\Entity\SettingValue;
use App\Tests\Mixin\TestWithDoctrineRepository;
use App\Trading\Application\Settings\SafePriceDistanceSettings;

trait SettingsAwareTest
{
    use TestWithDoctrineRepository;

    /**
     * @before
     */
    protected function before(): void
    {
        self::truncateStoredSettings();
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
        self::getSettingsService()->set($setting, $value);
    }

    protected function setMinimalSafePriceDistance(Symbol $symbol, Side $positionSide, float $pricePercent = 0.1): void
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
}
