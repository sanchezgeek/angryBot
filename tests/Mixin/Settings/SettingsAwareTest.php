<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Settings;

use App\Settings\Application\Service\AppSettingsProvider;
use App\Settings\Application\Service\SettingKeyAware;

trait SettingsAwareTest
{
    protected static function getContainerSettingsProvider(): AppSettingsProvider
    {
        return self::getContainer()->get(AppSettingsProvider::class);
    }

    protected static function getSettingValue(SettingKeyAware $setting): mixed
    {
        /** @var AppSettingsProvider $settings */
        $settings = self::getContainer()->get(AppSettingsProvider::class);

        return self::getContainerSettingsProvider()->get($setting);
    }

    protected function overrideSetting(SettingKeyAware $setting, mixed $value): void
    {
        self::getContainerSettingsProvider()->set($setting, $value);
    }
}
