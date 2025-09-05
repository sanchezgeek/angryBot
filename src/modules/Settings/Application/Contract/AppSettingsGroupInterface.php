<?php

declare(strict_types=1);

namespace App\Settings\Application\Contract;

interface AppSettingsGroupInterface
{
    /**
     * @return AppSettingInterface[]
     */
    public static function cases(): array;

    public static function category(): string;
}
