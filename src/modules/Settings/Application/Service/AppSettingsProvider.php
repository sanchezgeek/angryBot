<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Helper\OutputHelper;
use App\Settings\Application\Contract\SettingCacheTtlAware;
use App\Settings\Application\Contract\SettingKeyAware;
use App\Settings\Application\Service\Dto\SettingValueAccessor;
use DateInterval;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AppSettingsProvider implements AppSettingsProviderInterface
{
    private const CACHE_TTL = '1 minute';

    private array $overrides = [];

    public function get(SettingKeyAware|SettingValueAccessor $setting, bool $required = true, ?string $ttl = null): mixed
    {
        $settingValueAccessor = $setting instanceof SettingValueAccessor ? $setting : SettingValueAccessor::simple($setting);
        $setting = $settingValueAccessor->setting;

        $ttl = $ttl ?? ($setting instanceof SettingCacheTtlAware ? $setting->cacheTtl() : self::CACHE_TTL);
        $cacheKey = md5(
            sprintf('settingValueAccessor_%s_%s_%s', $setting->getSettingKey(), $settingValueAccessor?->symbol->value ?? 'null', $settingValueAccessor?->side->value ?? 'null')
        );

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($settingValueAccessor, $required, $ttl) {
            $item->expiresAfter(DateInterval::createFromDateString($ttl));

            return $this->doGet($settingValueAccessor, $required, $ttl);
        });
    }

    private function doGet(SettingValueAccessor $settingValueAccessor, bool $required, string $ttl): mixed
    {
        $baseKey = $settingValueAccessor->setting->getSettingKey();
        $side = $settingValueAccessor->side;
        $symbol = $settingValueAccessor->symbol;

        $keys = [];
        $side && $keys[] = sprintf('%s[symbol=%s][side=%s]', $baseKey, $symbol->value, $side->value);
        $symbol && $keys[] = sprintf('%s[symbol=%s]', $baseKey, $symbol->value);

        foreach ($keys as $key) {
            if ($value = $this->fetch($key, $ttl)) {
                return $value;
            }
        }

        $value = $this->fetch($baseKey, $ttl);
        if ($required && $value === null) {
            $msg = sprintf('Cannot find value for setting "%s"', $keys[0] ?? $baseKey);
            $this->appErrorLogger->error($msg);
            OutputHelper::print($msg);
        }

        return $value;
    }

    private function fetch(string $key, string $ttl): mixed
    {
        $cacheKey = md5(sprintf('setting_%s', $key));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($key, $ttl) {
            $item->expiresAfter(DateInterval::createFromDateString($ttl));

            try {
                return $this->overrides[$key] ?? $this->settingsBag->get($key);
            } catch (ParameterNotFoundException) {
                return null;
            }
        });
    }

    /**
     * @internal For tests
     */
    public function set(SettingKeyAware|SettingValueAccessor $setting, mixed $value): void
    {
        $settingValueAccessor = $setting instanceof SettingValueAccessor ? $setting : SettingValueAccessor::simple($setting);

        $baseKey = $settingValueAccessor->setting->getSettingKey();
        $side = $settingValueAccessor->side;
        $symbol = $settingValueAccessor->symbol;

        $key = match (true) {
            $side !== null => sprintf('%s[symbol=%s][side=%s]', $baseKey, $symbol->value, $side->value),
            $symbol !== null => sprintf('%s[symbol=%s]', $baseKey, $symbol->value),
            default => $baseKey
        };

        $this->overrides[$key] = $value;
    }

    public function __construct(
        private readonly ParameterBagInterface $settingsBag,
        private readonly CacheInterface $cache,
        private readonly AppErrorLoggerInterface $appErrorLogger,
    ) {
    }
}
