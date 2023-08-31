<?php

namespace App\Command\Buy;

use App\Bot\Application\Service\Orders\BuyOrderService;
use App\Bot\Infrastructure\ByBit\PositionService;
use App\Domain\Position\ValueObject\Side;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'buy:single')]
class CreateBuyCommand extends Command
{
    public function __construct(
        private readonly BuyOrderService $buyOrderService,
        private readonly PositionService $positionService,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
            ->addArgument('trigger_delta', InputArgument::REQUIRED, 'Trigger delta')
            ->addArgument('volume', InputArgument::REQUIRED, 'Buy volume')
            ->addArgument('price', InputArgument::REQUIRED, 'Trigger price')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $positionSide = $input->getArgument('position_side');
            $triggerDelta = $input->getArgument('trigger_delta') ?? null;
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

            $this->buyOrderService->create(
                Side::tryFrom($positionSide),
                $price,
                $volume,
                $triggerDelta
            );

//            $io->success(
//                \sprintf('Result transfer cost: %d', $cost),
//            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
