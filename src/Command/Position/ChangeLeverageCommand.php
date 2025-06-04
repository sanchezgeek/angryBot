<?php

namespace App\Command\Position;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\PositionDependentCommand;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\Cache\PositionsCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'p:leverage:change')]
class ChangeLeverageCommand extends AbstractCommand implements PositionDependentCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addArgument('leverage', InputArgument::REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $leverage = $this->paramFetcher->getFloatArgument('leverage');

        $this->positionSvc->setLeverage($this->getSymbol(), $leverage, $leverage);

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionsCache $positionsCache,
        private readonly ByBitLinearPositionService $positionSvc,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
