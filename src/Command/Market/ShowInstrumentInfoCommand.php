<?php

namespace App\Command\Market;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Helper\OutputHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function json_encode;
use function sprintf;

#[AsCommand(name: 'i:info')]
class ShowInstrumentInfoCommand extends AbstractCommand
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
            $this->io->info(sprintf('%s: %s', $symbol->value, json_encode($this->exchangeService->getInstrumentInfo($symbol))));
        }

        return Command::SUCCESS;
    }


    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
