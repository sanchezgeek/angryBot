<?php

namespace App\Command\Orders;

use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Command\AbstractCommand;
use App\Helper\OutputHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'o:not-executed:symbols')]
class NotExecutedOrdersSymbolsInfoCommand extends AbstractCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $notExecutedOrdersSymbols = $this->buyOrderRepository->getNotExecutedOrdersSymbolsMap();

        OutputHelper::print(
            json_encode(array_keys($notExecutedOrdersSymbols))
        );

        return Command::SUCCESS;
    }

    public function __construct(

        private readonly BuyOrderRepository $buyOrderRepository,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
