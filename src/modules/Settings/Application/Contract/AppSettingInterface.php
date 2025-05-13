<?php

declare(strict_types=1);

namespace App\Settings\Application\Contract;

interface AppSettingInterface
{
    public function getSettingKey(): string;
}
