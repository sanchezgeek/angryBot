<?php

namespace App\Command\Market;

use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'market:funding:last')]
class FundingRateCommand extends AbstractCommand implements SymbolDependentCommand
{
    use ConsoleInputAwareCommand;
    use SymbolAwareCommand;

    protected function configure()
    {
        $this->configureSymbolArgs();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fundingRate = $this->marketService->getPreviousPeriodFundingRate($this->getSymbol());
        var_dump($fundingRate);

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly MarketServiceInterface $marketService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
