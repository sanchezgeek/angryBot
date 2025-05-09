<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Storage;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Storage\AssignedSettingValueFactory;
use App\Settings\Application\Storage\StoredSettingsProviderInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final readonly class SymfonyConfigStoredSettingsProvider implements StoredSettingsProviderInterface
{
    public function __construct(
        private ParameterBagInterface $settingsBag,
    ) {
    }

    public function getSettingStoredValues(AppSettingInterface $setting): array
    {
        $result = [];

        $all = $this->settingsBag->all();
        foreach ($all as $key => $value) {
            if (!str_contains($key, $setting->getSettingKey())) continue;

            $result[] = AssignedSettingValueFactory::byKeyAndValue($setting, $key, $value, 'default');
        }

        return $result;
    }
}
