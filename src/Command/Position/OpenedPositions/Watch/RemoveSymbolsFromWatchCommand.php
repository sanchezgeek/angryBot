<?php

namespace App\Command\Position\OpenedPositions\Watch;

use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Position\OpenedPositions\Cache\OpenedPositionsCache;
use App\Command\PositionDependentCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:opened:watch:remove')]
class RemoveSymbolsFromWatchCommand extends AbstractCommand implements PositionDependentCommand
{
    use PositionAwareCommand;
    use ConsoleInputAwareCommand;

    private const string ALL_OPTION = 'all';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->addOption(self::ALL_OPTION, null, InputOption::VALUE_NEGATABLE)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->paramFetcher->getBoolOption(self::ALL_OPTION)) {
            $this->cache->clearWatch();

            return Command::SUCCESS;
        }

        $this->cache->removeSymbolsFromWatch(...$this->getSymbols());

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly OpenedPositionsCache $cache,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
