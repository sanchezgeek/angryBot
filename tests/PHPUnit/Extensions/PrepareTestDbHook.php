<?php

declare(strict_types=1);

namespace App\Tests\PHPUnit\Extensions;

use App\Tests\PHPUnit\DbDependentTest;
use PHPUnit\Runner\BeforeTestHook;
use Symfony\Component\Process\Process;

final class PrepareTestDbHook implements BeforeTestHook
{
    private static $initialized = false;

    public function executeBeforeTest(string $test): void
    {
        if (self::$initialized) {
            return;
        }

        $class = \explode('::', $test)[0];

        if (!\is_subclass_of($class, DbDependentTest::class)) {
            return;
        }

        self::cmd('php bin/console doctrine:s:drop --force --env=test --no-interaction --full-database');
        self::cmd('php bin/console doctrine:database:create --env=test --if-not-exists --no-interaction');
        self::cmd('php bin/console doctrine:migrations:migrate --env=test --no-interaction');

        self::$initialized = true;
    }


    private static function cmd(string $cmd): void
    {
        echo '# ', $cmd, PHP_EOL;
        $process = Process::fromShellCommandline($cmd);
        $process->run(static fn ($type, $output) => print($output));
        echo \PHP_EOL;
    }
}
