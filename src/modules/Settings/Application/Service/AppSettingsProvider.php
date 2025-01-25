<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Helper\OutputHelper;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class AppSettingsProvider
{
    private array $overrides = [];

    public function get(SettingKeyAware $setting): mixed
    {
        try {
            return $this->overrides[$setting->getSettingKey()] ?? $this->settingsBag->get($setting->getSettingKey());
        } catch (ParameterNotFoundException $e) {
            OutputHelper::print($e->getMessage());
            return null;
        }
    }

    /**
     * @internal For tests
     */
    public function set(SettingKeyAware $setting, mixed $value): void
    {
        $this->overrides[$setting->getSettingKey()] = $value;
    }

    public function __construct(
        private readonly ParameterBag $settingsBag,
    ) {
    }
}
