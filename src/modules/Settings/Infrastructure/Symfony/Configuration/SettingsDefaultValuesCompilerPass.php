<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Symfony\Configuration;

use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;


final class SettingsDefaultValuesCompilerPass implements CompilerPassInterface
{
    /**
     * @see src/modules/Settings/Infrastructure/Symfony/config/settings.yaml
     */
    private const DEFAULT_SETTINGS_VALUES_BAG_SERVICE_ID = 'app.settings';

    private string $settingsYamlPath;

    public function __construct(string $settingsYamlPath)
    {
        if (!$realpath = realpath($settingsYamlPath)) {
            throw new RuntimeException(sprintf('Cannot find %s', $settingsYamlPath));
        }

        $this->settingsYamlPath = $realpath;
    }

    public function process(ContainerBuilder $container): void
    {
        $defaultSettingsValuesBag = $container->getDefinition(self::DEFAULT_SETTINGS_VALUES_BAG_SERVICE_ID);

        if (!$yaml = Yaml::parseFile($this->settingsYamlPath)) {
            return;
        }

        foreach ($yaml as $key => $value) {
            $defaultSettingsValuesBag->addMethodCall('set', [$key, $value]);
        }
    }
}
