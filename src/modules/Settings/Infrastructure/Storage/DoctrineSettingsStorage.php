<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Storage;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use App\Settings\Application\Storage\SettingsStorageInterface;
use App\Settings\Application\Storage\StoredSettingsProviderInterface;
use App\Settings\Domain\Entity\SettingValue;
use App\Settings\Domain\Repository\SettingValueRepository;
use App\Settings\Domain\SettingValueCaster;
use App\Settings\Domain\SettingValueValidator;
use InvalidArgumentException;

final readonly class DoctrineSettingsStorage implements StoredSettingsProviderInterface, SettingsStorageInterface
{
    public function __construct(private SettingValueRepository $repository)
    {
    }

    public function get(SettingAccessor|AppSettingInterface $setting): ?SettingValue
    {
        if ($setting instanceof SettingAccessor && !$setting->exact) {
            throw new InvalidArgumentException('Only exact accessor allowed here');
        }

        $settingAccessor = $setting instanceof SettingAccessor ? $setting : SettingAccessor::withAlternativesAllowed($setting);

        return $this->repository->findOneBy(['key' => $settingAccessor->setting->getSettingKey(), 'symbol' => $settingAccessor->symbol, 'positionSide' => $settingAccessor->side]);
    }

    public function getSettingStoredValues(AppSettingInterface $setting): array
    {
        $result = [];
        foreach ($this->repository->findBy(['key' => $setting->getSettingKey()]) as $settingValue) {
            $baseKey = $setting->getSettingKey();

            // @todo move somewhere
            $key = match (true) {
                $settingValue->positionSide !== null => sprintf('%s[symbol=%s][side=%s]', $baseKey, $settingValue->symbol->value, $settingValue->positionSide->value),
                $settingValue->symbol !== null => sprintf('%s[symbol=%s]', $baseKey, $settingValue->symbol->value),
                default => $baseKey,
            };

            $value = $settingValue->value === null ? null : SettingValueCaster::castToDeclaredType($setting, $settingValue->value);

            $result[] = new AssignedSettingValue($setting, $key, $value, 'from db');
        }

        return $result;
    }

    /**
     * @todo | settings | tests
     */
    public function store(SettingAccessor $settingAccessor, mixed $value): SettingValue
    {
        $setting = $settingAccessor->setting;
        $settingKey = $setting->getSettingKey();

        if ($value !== null && !SettingValueValidator::validate($setting, $value)) {
            $value = json_encode($value);
            throw new InvalidArgumentException(sprintf('Invalid value "%s" for setting "%s"', $value, $settingKey));
        }

        $symbol = $settingAccessor->symbol;
        $side = $settingAccessor->side;

        if (!$settingValue = $this->repository->findOneBy(['key' => $settingKey, 'symbol' => $symbol, 'positionSide' => $side])) {
            $settingValue = SettingValue::withValue($settingKey, $value, $symbol, $side);
        } else {
            $settingValue->value = $value;
        }

        $this->repository->save($settingValue);

        return $settingValue;
    }

    public function remove(SettingAccessor $settingAccessor): void
    {
        $symbol = $settingAccessor->symbol;
        $side = $settingAccessor->side;

        if (!$settingValue = $this->repository->findOneBy(['key' => $settingAccessor->setting->getSettingKey(), 'symbol' => $symbol, 'positionSide' => $side])) {
            return;
        }

        $this->repository->remove($settingValue);
    }

    public function removeAllBySetting(AppSettingInterface $setting): void
    {
        foreach ($this->repository->findBy(['key' => $setting->getSettingKey()]) as $settingValue) {
            $this->repository->remove($settingValue);
        }
    }
}
