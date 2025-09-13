<?php

declare(strict_types=1);

namespace App\Infrastructure\DependencyInjection;

use Psr\Container\ContainerInterface;

final class GetServiceHelper
{
    private static ?ContainerInterface $container = null;

    public static function getService(string $id): object
    {
        return self::getContainer()->get($id);
    }

    public static function setContainer(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    private static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new \RuntimeException('Container has not been set yet.');
        }

        return self::$container;
    }
}
