<?php

declare(strict_types=1);

namespace App\Settings\Application\Storage;

use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;

interface StoredSettingsProviderInterface
{
    /**
     * @return AssignedSettingValue[]
     */
    public function getSettingStoredValues(SettingKeyAware $setting): array;
}
