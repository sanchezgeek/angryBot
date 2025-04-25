<?php

namespace App\Command\Position;

use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Infrastructure\ByBit\API\V5\Enum\Position\PositionMode;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\Cache\PositionsCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:mode:change')]
class ChangePositionModeCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addArgument('mode', InputArgument::REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->positionSvc->switchPositionMode(
            $this->getSymbol(),
            PositionMode::from($this->paramFetcher->getStringArgument('mode'))
        );

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly PositionsCache $positionsCache,
        private readonly ByBitLinearPositionService $positionSvc,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
