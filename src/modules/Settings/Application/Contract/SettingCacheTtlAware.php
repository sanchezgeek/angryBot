<?php

declare(strict_types=1);

namespace App\Settings\Application\Contract;

interface SettingCacheTtlAware
{
    public function cacheTtl(): string;
}
