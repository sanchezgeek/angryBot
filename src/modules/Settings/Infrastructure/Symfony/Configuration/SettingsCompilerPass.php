<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Symfony\Configuration;

use App\Settings\Application\Contract\AppSettingsGroupInterface;
use App\Settings\Application\Service\SettingsLocator;
use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;


final class SettingsCompilerPass implements CompilerPassInterface
{
    private const LOCATOR_SERVICE_ID = SettingsLocator::class;

    private string $settingsDirPath;

    public function __construct(string $settingsDirPath, private readonly string $namespace)
    {
        if (!$realpath = realpath($settingsDirPath)) {
            throw new RuntimeException(sprintf('Cannot find %s', $settingsDirPath));
        }

        $this->settingsDirPath = $realpath;
    }

    public function process(ContainerBuilder $container): void
    {
        $locator = $container->getDefinition(self::LOCATOR_SERVICE_ID);

        $finder = new Finder();

        $finder->files()->in($this->settingsDirPath);
        if ($finder->hasResults()) {
            foreach ($finder as $file) {
                $subDirPath = str_replace($this->settingsDirPath, '', $file->getPath());
                $namespace = $subDirPath ? $this->namespace . str_replace('/', '\\', $subDirPath) :  $this->namespace;
                $className = $namespace . '\\' . str_replace('.php', '', $file->getFilename());

                if (!in_array(AppSettingsGroupInterface::class, class_implements($className), true)) {
                    continue;
                }

                /** @see SettingsLocator::registerGroup */
                // @todo use finder to find only implementations?
                $locator->addMethodCall('registerGroup', [$className]);
            }
        }
    }

}
