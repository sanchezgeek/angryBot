<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;

interface AppSettingsProviderInterface
{
    public function get(SettingKeyAware|SettingAccessor $setting, bool $required = true, ?string $ttl = null): mixed;

    /** @return AssignedSettingValue[] */
    public function getAllSettingAssignedValues(SettingKeyAware $setting): array;
}
