<?php

declare(strict_types=1);

namespace App\Command\Mixin;

use Symfony\Component\Process\Process;

trait CommandRunnerCommand
{
    private static function cmd(string $cmd): void
    {
        echo '# ', $cmd, PHP_EOL;
        $process = Process::fromShellCommandline($cmd);
        $process->run(static fn ($type, $output) => print($output));
        echo \PHP_EOL;
    }
}
