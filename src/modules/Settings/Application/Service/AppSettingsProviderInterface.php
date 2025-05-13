<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Storage\AssignedSettingValueCollection;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;

interface AppSettingsProviderInterface
{
    public function optional(AppSettingInterface|SettingAccessor $setting): mixed;
    public function required(AppSettingInterface|SettingAccessor $setting): mixed;
    public function getAllSettingAssignedValuesCollection(AppSettingInterface $setting): AssignedSettingValueCollection;
}
