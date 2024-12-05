<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

interface SettingKeyAware
{
    public function getSettingKey(): string;
}