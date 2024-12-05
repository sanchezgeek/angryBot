<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Helper\OutputHelper;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final readonly class AppSettingsProvider
{
    public function get(SettingKeyAware $setting): mixed
    {
        try {
            return $this->settingsBag->get($setting->getSettingKey());
        } catch (ParameterNotFoundException $e) {
            OutputHelper::print($e->getMessage());
            return null;
        }
    }

    public function __construct(
        private ParameterBag $settingsBag,
    ) {
    }
}
