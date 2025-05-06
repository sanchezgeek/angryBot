<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Settings;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Application\Contract\SettingCacheTtlAware;

enum TestSetting: string implements AppSettingInterface, AppSettingsGroupInterface, SettingCacheTtlAware
{
    case Test = 'test.test';

    public function getSettingKey(): string
    {
        return $this->value;
    }

    public function cacheTtl(): string
    {
        return '1 second';
    }
}
