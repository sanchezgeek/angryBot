<?php

namespace App\Command;

use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cmd:test')]
class TestCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
//        $this->exchangeService->getTickers(Symbol::BTCUSDT, Symbol::ETHUSDT);
    }

    public function __construct(
        private readonly ByBitLinearExchangeService $exchangeService,
        string $name = null,
    ) {
        parent::__construct($name);
    }
}
