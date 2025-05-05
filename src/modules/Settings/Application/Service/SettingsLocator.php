<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Settings\Application\Contract\SettingKeyAware;
use InvalidArgumentException;

final class SettingsLocator
{
    private array $settings = [];

    public function __construct()
    {
    }

    public function register(string $settingKeyAwareClass): void
    {
        if (!in_array(SettingKeyAware::class, class_implements($settingKeyAwareClass), true)) {
            throw new InvalidArgumentException('Provided class must be StringBackedEnum');
        }

        $this->settings[] = $settingKeyAwareClass;
    }

    /**
     * @return SettingKeyAware[]
     */
    public function getRegisteredSettingsGroups(): array
    {
        return $this->settings;
    }
}
