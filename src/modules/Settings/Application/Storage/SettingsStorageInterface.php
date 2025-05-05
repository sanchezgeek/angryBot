<?php

declare(strict_types=1);

namespace App\Settings\Application\Storage;

use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Domain\Entity\SettingValue;

interface SettingsStorageInterface
{
    public function get(SettingKeyAware|SettingAccessor $setting): ?SettingValue;
    // @todo some dto? or just not return
    public function store(SettingKeyAware|SettingAccessor $setting, mixed $value): SettingValue;
    public function remove(SettingKeyAware|SettingAccessor $setting): void;
}
