<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Storage;

use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use App\Settings\Application\Storage\StoredSettingsProviderInterface;
use App\Settings\Domain\SettingValueCaster;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final readonly class SymfonyConfigStoredSettingsProvider implements StoredSettingsProviderInterface
{
    public function __construct(
        private ParameterBagInterface $settingsBag,
    ) {
    }

    public function getSettingStoredValues(SettingKeyAware $setting): array
    {
        $result = [];

        $all = $this->settingsBag->all();
        foreach ($all as $key => $value) {
            if (!str_contains($key, $setting->getSettingKey())) continue;

            // ?
//            $value = $settingValue->value === null ? null : SettingValueFormatter::format($setting, $settingValue->value);

            $result[] = new AssignedSettingValue(
                $setting,
                $key,
                SettingValueCaster::castToDeclaredType($setting, $value),
                'default from yaml'
            );
        }

        return $result;
    }
}
