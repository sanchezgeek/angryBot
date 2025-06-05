<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Storage;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Storage\AssignedSettingValueFactory;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use App\Settings\Application\Storage\StoredSettingsProviderInterface;
use App\Trading\Application\Symbol\SymbolProvider;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final readonly class SymfonyConfigStoredSettingsProvider implements StoredSettingsProviderInterface
{
    public function __construct(
        private ParameterBagInterface $settingsBag,
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function getSettingStoredValues(AppSettingInterface $setting): array
    {
        $result = [];

        $all = $this->settingsBag->all();
        foreach ($all as $key => $value) {
            if (!str_contains($key, $setting->getSettingKey())) continue;

            $result[] = $this->createAssignedValueByFullKeyAndValue($setting, $key, $value, 'default');
        }

        return $result;
    }

    public function createAssignedValueByFullKeyAndValue(AppSettingInterface $setting, string $fullKey, mixed $value, ?string $info = null): AssignedSettingValue
    {
        [$symbolRaw, $side] = AssignedSettingValueFactory::parseSymbolAndSide($fullKey);

        return new AssignedSettingValue(
            $setting,
            $symbolRaw ? $this->symbolProvider->getOrInitialize($symbolRaw) : null,
            $side,
            $fullKey,
            AssignedSettingValueFactory::castStoredValue($setting, $value),
            $info
        );
    }
}
