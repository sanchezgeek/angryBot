<?php

namespace App\Profiling\UI\Command;

use App\Command\AbstractCommand;
use App\Profiling\Application\Storage\ProfilingPointStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'profiling:reset')]
class ResetProfilingLogCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->storage->reset();

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ProfilingPointStorage $storage,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
