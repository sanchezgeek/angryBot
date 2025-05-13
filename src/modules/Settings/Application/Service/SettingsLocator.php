<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\AppSettingsGroupInterface;
use InvalidArgumentException;

final class SettingsLocator
{
    /** @var AppSettingsGroupInterface[]  */
    private array $groups = [];

    public function registerGroup(string $settingsGroup): void
    {
        if (!in_array(AppSettingsGroupInterface::class, class_implements($settingsGroup), true)) {
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

    public function tryGetBySettingBaseKey(string $className): ?AppSettingInterface
    {
        foreach ($this->groups as $group) {
            foreach ($group::cases() as $case) {
                if ($case->getSettingKey() === $className) {
                    return $case;
                }
            }
        }

        return null;
    }
}
