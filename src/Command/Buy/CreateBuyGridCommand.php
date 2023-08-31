<?php

namespace App\Command\Buy;

use App\Bot\Application\Service\Orders\BuyOrderService;
use App\Bot\Domain\ValueObject\Position\Side;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function rand;
use function random_int;
use function round;

#[AsCommand(name: 'buy:grid')]
class CreateBuyGridCommand extends Command
{
    public function __construct(
        private readonly BuyOrderService $buyOrderService,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
//            ->addArgument('trigger_delta', InputArgument::REQUIRED, 'Trigger delta')
            ->addArgument('volume', InputArgument::REQUIRED, 'Buy volume')
            ->addArgument('from_price', InputArgument::REQUIRED, 'From price')
            ->addArgument('to_price', InputArgument::REQUIRED, 'To price')
            ->addArgument('step', InputArgument::REQUIRED, 'Step')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $positionSide = $input->getArgument('position_side');
            $triggerDelta = 1;
//            $triggerDelta = $input->getArgument('trigger_delta') ?? null;
            $volume = $input->getArgument('volume');
            $fromPrice = $input->getArgument('from_price');
            $toPrice = $input->getArgument('to_price');
            $step = $input->getArgument('step');

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

            for ($price = $toPrice; $price > $fromPrice; $price-=$step) {
                $rand = round(random_int(-7, 8) * 0.6, 2);

                $price += $rand;

                $this->buyOrderService->create(
                    $positionSide,
                    $price,
                    $volume,
                    $triggerDelta
                );
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
