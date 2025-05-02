<?php

namespace App\Command\Service;

use App\Command\AbstractCommand;
use App\Service\Infrastructure\Job\GenerateSupervisorConfigs\GenerateSupervisorConfigs;
use App\Service\Infrastructure\Job\GenerateSupervisorConfigs\GenerateSupervisorConfigsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'supervisor:generate-configs')]
class GenerateSupervisorConfigsCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->handler->__invoke(new GenerateSupervisorConfigs());

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly GenerateSupervisorConfigsHandler $handler,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
