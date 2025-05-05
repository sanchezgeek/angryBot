<?php

declare(strict_types=1);

namespace App\Settings\Infrastructure\Symfony\Configuration;

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
        if (!$this->settingsDirPath = realpath($settingsDirPath)) {
            throw new RuntimeException(sprintf('Cannot find %s', $this->settingsDirPath));
        }
    }

    public function process(ContainerBuilder $container): void
    {
        $locator = $container->getDefinition(self::LOCATOR_SERVICE_ID);

        $finder = new Finder();

        $finder->files()->in($this->settingsDirPath);
        if ($finder->hasResults()) {
            foreach ($finder as $file) {
                $className = $this->namespace . '\\' . str_replace('.php', '', $file->getFilename());
                $locator->addMethodCall('register', [$className]);
            }
        }
    }

}
