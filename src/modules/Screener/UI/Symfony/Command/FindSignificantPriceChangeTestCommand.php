<?php

namespace App\Screener\UI\Symfony\Command;

use App\Command\AbstractCommand;
use App\Screener\Application\Contract\Query\FindSignificantPriceChangeHandlerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'screener:significant:test')]
class FindSignificantPriceChangeTestCommand extends AbstractCommand
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        die('test');
    }

    public function __construct(
        private readonly FindSignificantPriceChangeHandlerInterface $finder,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
