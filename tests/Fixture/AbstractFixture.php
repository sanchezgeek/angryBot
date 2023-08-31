<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Psr\Container\ContainerInterface;

abstract class AbstractFixture
{
    abstract public function clear(ContainerInterface $container): void;

    abstract public function apply(ContainerInterface $container): void;
}
