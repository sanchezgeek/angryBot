<?php

declare(strict_types=1);

namespace App\Tests\PHPUnit\Extensions;

use PHPUnit\Runner\BeforeTestHook;
use Symfony\Component\Process\Process;

final class ClearCacheTestHook implements BeforeTestHook
{
    public function executeBeforeTest(string $test): void
    {
        $path = __DIR__ . '/../../../var/cache/test/pools/*';
        self::cmd("rm -rf $path");
    }

    private static function cmd(string $cmd): void
    {
        $process = Process::fromShellCommandline($cmd);
        $process->run(static fn ($type, $output) => print($output));
        echo \PHP_EOL;
    }
}
