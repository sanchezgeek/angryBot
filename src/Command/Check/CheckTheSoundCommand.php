<?php

namespace App\Command\Check;

use App\Application\Logger\AppErrorLoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'c:sound')]
class CheckTheSoundCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->appErrorLogger->error('check-the-sound');

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly AppErrorLoggerInterface $appErrorLogger,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
