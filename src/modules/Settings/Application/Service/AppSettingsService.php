<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Helper\OutputHelper;
use App\Settings\Application\Attribute\SettingParametersAttributeReader;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Contract\SettingCacheTtlAware;
use App\Settings\Application\Storage\Dto\AssignedSettingValue;
use App\Settings\Application\Storage\SettingsStorageInterface;
use App\Settings\Application\Storage\StoredSettingsProviderInterface;
use DateInterval;
use Exception;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AppSettingsService implements AppSettingsProviderInterface
{
    private const CACHE_TTL = '5 minutes';

    /**
     * @throws Exception
     */
    public function set(SettingAccessor $settingAccessor, mixed $value): void
    {
        $this->storage->store($settingAccessor, $value);
        $this->settingsCache->clear();
    }

    /**
     * @throws Exception
     */
    public function disable(SettingAccessor $settingAccessor): void
    {
        if (!SettingParametersAttributeReader::isSettingNullable($settingAccessor->setting)) {
            throw new Exception(sprintf('Cannot disable: setting "%s" is not nullable', $settingAccessor->setting->getSettingKey()));
        }

        $this->storage->store($settingAccessor, null);
        $this->settingsCache->clear();
    }

    /**
     * @throws Exception
     */
    public function resetSetting(AppSettingInterface|string $setting): void
    {
        $settingKey = $setting instanceof AppSettingInterface ? $setting->getSettingKey() : $setting;

        $this->storage->removeAllByBaseKey($settingKey);
        $this->settingsCache->clear();
    }

    /**
     * @throws Exception
     */
    public function unset(SettingAccessor $settingAccessor): void
    {
        $this->storage->remove($settingAccessor);
        $this->settingsCache->clear();
    }

    public function optional(SettingAccessor|AppSettingInterface $setting): mixed
    {
        return $this->get($setting, false);
    }

    public function required(AppSettingInterface|SettingAccessor $setting): mixed
    {
        return $this->get($setting, true);
    }

    private function get(AppSettingInterface|SettingAccessor $setting, bool $required): mixed
    {
        $settingValueAccessor = $setting instanceof SettingAccessor ? $setting : SettingAccessor::withAlternativesAllowed($setting);
        $setting = $settingValueAccessor->setting;

        $ttl = $ttl ?? ($setting instanceof SettingCacheTtlAware ? $setting->cacheTtl() : self::CACHE_TTL);
        $cacheKey = md5(
            sprintf('settingValueAccessor_%s_%s_%s', $setting->getSettingKey(), $settingValueAccessor?->symbol->value ?? 'null', $settingValueAccessor?->side->value ?? 'null')
        );

        return $this->settingsCache->get($cacheKey, function (ItemInterface $item) use ($settingValueAccessor, $required, $ttl) {
            $item->expiresAfter(DateInterval::createFromDateString($ttl));

            $foundValue = $this->doGet($settingValueAccessor, $required);

            return $foundValue?->value;
        });
    }

    /**
     * @return AssignedSettingValue[]
     */
    public function getAllSettingAssignedValues(AppSettingInterface $setting): array
    {
        $result = [];
        foreach ($this->storedValuesProviders as $provider) {
            $storedValues = $provider->getSettingStoredValues($setting);
            foreach ($storedValues as $storedValue) {
                $result[$storedValue->fullKey] = $storedValue;
            }
        }

        return $result;
    }

    private function doGet(SettingAccessor $settingValueAccessor, bool $required): ?AssignedSettingValue
    {
        $assignedValues = $this->getAllSettingAssignedValues($settingValueAccessor->setting);

        $baseKey = $settingValueAccessor->setting->getSettingKey();
        $side = $settingValueAccessor->side;
        $symbol = $settingValueAccessor->symbol;

        $keys = [];

        $break = false;
        if ($side) {
            $keys[] = sprintf('%s[symbol=%s][side=%s]', $baseKey, $symbol->value, $side->value);
            if ($settingValueAccessor->exact) {
                $break = true;
            }
        }

        if ($symbol && !$break) {
            $keys[] = sprintf('%s[symbol=%s]', $baseKey, $symbol->value);
            if ($settingValueAccessor->exact) {
                $break = true;
            }
        }

        if (!$break) {
            $keys[] = $baseKey;
        }

        foreach ($keys as $key) {
            if ($value = $assignedValues[$key] ?? null) {
                return $value;
            }
        }

        if ($required) { // @todo what about disabled?
            $msg = sprintf('Cannot find value for setting "%s"', $keys[0] ?? $baseKey);
            $this->appErrorLogger->error($msg);
            OutputHelper::print($msg);
        }

        return null;
    }

    /**
     * @todo cache decorator
     * @see StoredSettingsProviderInterface
     */
//    private function fetch(string $key, string $ttl): mixed
//    {
//        $cacheKey = md5(sprintf('setting_%s', $key));
//
//        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($key, $ttl) {
//            $item->expiresAfter(DateInterval::createFromDateString($ttl));
//
//            try {
//                return $this->settingsBag->get($key);
//            } catch (ParameterNotFoundException) {
//                return null;
//            }
//        });
//    }

    /**
     * @param iterable<StoredSettingsProviderInterface> $storedValuesProviders
     */
    public function __construct(
        private readonly CacheInterface $settingsCache,
        private readonly AppErrorLoggerInterface $appErrorLogger,
        private readonly SettingsStorageInterface $storage,
        private readonly iterable $storedValuesProviders
    ) {
    }
}
