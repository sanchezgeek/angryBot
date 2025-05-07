<?php

declare(strict_types=1);

namespace App\Settings\Application\Storage;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Domain\Entity\SettingValue;

interface SettingsStorageInterface
{
    public function get(AppSettingInterface|SettingAccessor $setting): ?SettingValue;
    // @todo | settings some other dto? or just not return
    public function store(SettingAccessor $settingAccessor, mixed $value): SettingValue;
    public function remove(SettingAccessor $settingAccessor): void;
    public function removeAllBySetting(AppSettingInterface $setting): void;
}
