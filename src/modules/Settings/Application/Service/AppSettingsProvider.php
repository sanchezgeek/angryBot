<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Helper\OutputHelper;
use App\Settings\Application\Enum\SettingsMap;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final readonly class AppSettingsProvider
{
    public function get(SettingsMap $setting): mixed
    {
        try {
            return $this->settingsBag->get($setting->value);
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
