<?php

namespace App\Info\Console;

use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Info\Application\Service\DependencyInfoCollector;
use App\Info\Contract\DependencyInfoProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(name: 'info:get')]
class GetCollectedInfoCommand extends AbstractCommand
{
    use PositionAwareCommand;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->services as $service) {
            $this->collector->addInfo($service->getDependencyInfo());
        }

        $info = $this->collector->getInfo();
        var_dump($info);die;

        return Command::SUCCESS;
    }

    /**
     * @param iterable<DependencyInfoProviderInterface> $services
     */
    public function __construct(
        private readonly DependencyInfoCollector $collector,
        #[AutowireIterator('info.info_provider')]
        private iterable $services,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
