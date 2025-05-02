<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Settings;

use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Application\Service\AppSettingsProvider;

trait SettingsAwareTest
{
    protected static function getContainerSettingsProvider(): AppSettingsProvider
    {
        return self::getContainer()->get(AppSettingsProvider::class);
    }

    protected static function getSettingValue(SettingKeyAware $setting): mixed
    {
        return self::getContainerSettingsProvider()->get($setting);
    }

    protected function overrideSetting(SettingKeyAware $setting, mixed $value): void
    {
        self::getContainerSettingsProvider()->set($setting, $value);
    }
}
