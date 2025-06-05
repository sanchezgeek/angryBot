<?php

namespace App\Command\Market;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Command\SymbolDependentCommand;
use App\Infrastructure\ByBit\Service\Market\ByBitLinearMarketService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function json_encode;
use function sprintf;

#[AsCommand(name: 'i:info')]
class ShowInstrumentInfoCommand extends AbstractCommand implements SymbolDependentCommand
{
    use SymbolAwareCommand;
    use PriceRangeAwareCommand;

    protected function configure(): void
    {
        $this->configureSymbolArgs();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbols = $this->getSymbols();

        foreach ($symbols as $symbol) {
            $this->io->info(sprintf('%s: %s', $symbol->name(), json_encode($this->marketService->getInstrumentInfo($symbol))));
        }

        return Command::SUCCESS;
    }


    public function __construct(
        private readonly ByBitLinearMarketService $marketService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
