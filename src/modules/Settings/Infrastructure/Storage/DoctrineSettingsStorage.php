<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Storage;

use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Settings\Application\Storage\AssignedSettingValueFactory;
use App\Settings\Application\Storage\SettingsStorageInterface;
use App\Settings\Application\Storage\StoredSettingsProviderInterface;
use App\Settings\Domain\Entity\SettingValue;
use App\Settings\Domain\Repository\SettingValueRepository;
use App\Settings\Domain\SettingValueValidator;
use DateInterval;
use InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class DoctrineSettingsStorage implements StoredSettingsProviderInterface, SettingsStorageInterface
{
    private const CACHE_TTL = '1 minute';

    public function __construct(
        private SettingValueRepository $repository,
        private CacheInterface $settingsCache,
    ) {
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
        $cacheKey = md5(sprintf('doctrineAllSettingValues_for_%s', $setting->getSettingKey()));

        return $this->settingsCache->get($cacheKey, function (ItemInterface $item) use ($setting) {
            $item->expiresAfter(DateInterval::createFromDateString(self::CACHE_TTL));

            $result = [];
            foreach ($this->repository->findBy(['key' => $setting->getSettingKey()]) as $settingValue) {
                $result[] = AssignedSettingValueFactory::fromEntity($setting, $settingValue, 'from db');
            }

            return $result;
        });
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

    public function removeAllByBaseKey(string $baseKey): void
    {
        foreach ($this->repository->findBy(['key' => $baseKey]) as $settingValue) {
            $this->repository->remove($settingValue);
        }
    }
}
