<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Orders\StopService;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'sl:single', description: 'Creates single stop with specified price.')]
class CreateStopCommand extends AbstractCommand
{
    use PositionAwareCommand;

    public function __construct(
        private readonly StopService $stopService,
        string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument('trigger_delta', InputArgument::REQUIRED, 'Trigger delta')
            ->addArgument('volume', InputArgument::REQUIRED, 'Stop volume')
            ->addArgument('price', InputArgument::REQUIRED, 'Trigger price')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        try {
            $price = $input->getArgument('price');
            $volume = $input->getArgument('volume');
            $triggerDelta = $input->getArgument('trigger_delta') ?? null;
            $positionSide = $this->getPositionSide();

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

            $this->stopService->create($positionSide, $price, $volume, $triggerDelta);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
