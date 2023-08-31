<?php

namespace App\Command\Stop;

use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Infrastructure\ByBit\PositionService;
use App\Bot\Application\Service\Orders\StopService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'sl:single', description: 'Creates single stop with specified price.')]
class CreateStopCommand extends Command
{
    public function __construct(
        private readonly StopService $stopService,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('position_side', InputArgument::REQUIRED, 'Position side (sell|buy)')
            ->addArgument('trigger_delta', InputArgument::REQUIRED, 'Trigger delta')
            ->addArgument('volume', InputArgument::REQUIRED, 'Stop volume')
            ->addArgument('price', InputArgument::REQUIRED, 'Trigger price')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $price = $input->getArgument('price');
            $volume = $input->getArgument('volume');
            $triggerDelta = $input->getArgument('trigger_delta') ?? null;

            if (!$positionSide = Side::tryFrom($input->getArgument('position_side'))) {
                throw new \InvalidArgumentException(
                    \sprintf('Invalid $step provided (%s)', $input->getArgument('position_side')),
                );
            }
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

            $this->stopService->create(
                $positionSide,
                $price,
                $volume,
                $triggerDelta
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
