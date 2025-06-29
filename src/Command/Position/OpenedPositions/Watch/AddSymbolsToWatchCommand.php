<?php

namespace App\Command\Position\OpenedPositions\Watch;

use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\Position\OpenedPositions\Cache\OpenedPositionsCache;
use App\Command\PositionDependentCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:opened:watch:add')]
class AddSymbolsToWatchCommand extends AbstractCommand implements PositionDependentCommand
{
    use SymbolAwareCommand;
    use ConsoleInputAwareCommand;

    protected function configure(): void
    {
        $this->configureSymbolArgs(defaultValue: null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cache->addSymbolToWatch(...$this->getSymbols());

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly OpenedPositionsCache $cache,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
