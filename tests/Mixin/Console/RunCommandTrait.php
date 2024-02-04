<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_replace;
use function property_exists;

trait RunCommandTrait
{
    /**
     * @param array<string> $input
     * @param array<string, bool> $options
     */
    protected function runCommand(array $input, array $options = [], ?KernelInterface $kernel = null): ApplicationTester
    {
        $class = static::class;
        if (property_exists($class, 'defaultCommand')) {
            $input = array_replace(['command' => $class::$defaultCommand], $input);
        }

        $application = new Application($kernel ?: static::getKernel());
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run($input, $options);

        return $tester;
    }

    protected static function getKernel(): KernelInterface
    {
        if (static::$booted && static::$kernel) {
            return static::$kernel;
        }

        return static::bootKernel();
    }
}
