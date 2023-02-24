<?php

namespace App\Command;

use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Application\Service\Orders\BuyOrderService;
use App\Bot\Infrastructure\ByBit\PositionService;
use App\Bot\Application\Service\Orders\StopService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CreateSellOrdersIncrementalGridCommand extends Command
{
    protected static $defaultName = 'stop-inc-grid';

    public function __construct(
        private readonly StopService $stopService,
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
            ->addArgument('volume', InputArgument::REQUIRED, 'Stop start volume')
            ->addArgument('from_price', InputArgument::REQUIRED, 'From price')
            ->addArgument('to_price', InputArgument::REQUIRED, 'To price')
            ->addArgument('step', InputArgument::REQUIRED, 'Step')
            ->addArgument('increment', InputArgument::OPTIONAL, 'Volume to add to each new SL')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = Symbol::BTCUSDT;

        try {
            $positionSide = $input->getArgument('position_side');
            $triggerDelta = $input->getArgument('trigger_delta');
            $volume = $input->getArgument('volume');
            $fromPrice = $input->getArgument('from_price');
            $toPrice = $input->getArgument('to_price');
            $step = $input->getArgument('step');
            $increment = $input->getArgument('increment');

            if (!$positionSide = Side::tryFrom($positionSide)) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $step),
                );
            }
            if (!(float)$step) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $step),
                );
            }
            if (!(float)$triggerDelta) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $triggerDelta provided (%s)', $step),
                );
            }
            if (!(float)$toPrice) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $toPrice provided (%s)', $toPrice),
                );
            }
            if (!(float)$fromPrice) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $fromPrice provided (%s)', $fromPrice),
                );
            }
            if (!(string)$volume) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $price provided (%s)', $volume),
                );
            }

            $context = ['uniqid' => \uniqid('inc-create', true)];


            if ($fromPrice > $toPrice) {
                for ($price = $fromPrice; $price > $toPrice; $price-=$step) {
                    $this->stopService->create(
                        $positionSide,
                        $price,
                        $volume,
                        $triggerDelta,
                        $context
                    );
                    $volume = round($volume+$increment, 3);
                }
            } else {
                for ($price = $fromPrice; $price < $toPrice; $price+=$step) {
                    $this->stopService->create(
                        $positionSide,
                        $price,
                        $volume,
                        $triggerDelta,
                        $context
                    );
                    $volume = round($volume+$increment, 3);
                }
            }










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
