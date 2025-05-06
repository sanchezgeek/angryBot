<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use InvalidArgumentException;

final class SettingsLocator
{
    private array $groups = [];

    public function register(string $settingsGroup): void
    {
        if (!in_array(AppSettingInterface::class, class_implements($settingsGroup), true)) {
            throw new InvalidArgumentException('Provided class must be of type AppSettingInterface');
        }

        $this->groups[] = $settingsGroup;
    }

    /**
     * @return AppSettingsGroupInterface[]
     */
    public function getRegisteredSettingsGroups(): array
    {
        return $this->groups;
    }
}
