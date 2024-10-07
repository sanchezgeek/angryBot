<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Mixin\ConsoleInputAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractCommand extends Command
{
    use ConsoleInputAwareCommand;

    protected SymfonyStyle $io;
    protected OutputInterface $output;

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->withInput($input);
        $this->output = $output;
        $this->io = new SymfonyStyle($input, $output);
    }
}
