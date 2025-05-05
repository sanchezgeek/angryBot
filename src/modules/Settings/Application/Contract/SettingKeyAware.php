<?php

declare(strict_types=1);

namespace App\Settings\Application\Contract;

interface SettingKeyAware
{
    public function getSettingKey(): string;

    /**
     * @return self[]
     */
    public static function cases(): array;
}
