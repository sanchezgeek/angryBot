<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Settings;

use App\Settings\Application\Service\AppSettingsProvider;
use App\Settings\Application\Service\SettingKeyAware;

trait SettingsAwareTest
{
    protected static function getSettingValue(SettingKeyAware $setting): mixed
    {
        /** @var AppSettingsProvider $settings */
        $settings = self::getContainer()->get(AppSettingsProvider::class);

        return $settings->get($setting);
    }
}
