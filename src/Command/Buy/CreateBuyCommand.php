<?php

namespace App\Command\Buy;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\PositionDependentCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'buy:single')]
class CreateBuyCommand extends AbstractCommand implements PositionDependentCommand
{
    use PositionAwareCommand;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument('volume', InputArgument::REQUIRED, 'Buy volume')
            ->addArgument('price', InputArgument::REQUIRED, 'Trigger price')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $side = $this->getPositionSide();
            $volume = $input->getArgument('volume');
            $price = $input->getArgument('price');

            if (!(float)$price) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $price provided (%s)', $price),
                );
            }
            if (!(float)$volume) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $price provided (%s)', $volume),
                );
            }
            if (!(string)$volume) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $price provided (%s)', $volume),
                );
            }

            $this->createBuyOrderHandler->handle(
                new CreateBuyOrderEntryDto($this->getSymbol(), $side, $volume, $price)
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    public function __construct(
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
