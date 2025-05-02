<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Application\Service\Dto\SettingValueAccessor;

interface AppSettingsProviderInterface
{
    public function get(SettingKeyAware|SettingValueAccessor $setting, bool $required = true, ?string $ttl = null): mixed;
}
