<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

trait CommandsTester
{
    public function createCommandTester(string $commandName): CommandTester
    {
        self::bootKernel();

        return new CommandTester((new Application(self::$kernel))->find($commandName));
    }
}
