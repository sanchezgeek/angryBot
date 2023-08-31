<?php

namespace App\Command;

use App\Bot\Application\Command\Exchange\TryReleaseActiveOrders;
use App\Bot\Domain\ValueObject\Symbol;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'active:force-release')]
class ForceReleaseExchangeOrdersCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->messageBus->dispatch(
            new TryReleaseActiveOrders(symbol: Symbol::BTCUSDT, force: true)
        );

        $io->success('TryReleaseActiveOrders(force: true) dispatched successfully');

        return Command::SUCCESS;
    }
}
