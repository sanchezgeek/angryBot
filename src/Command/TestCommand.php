<?php

namespace App\Command;

use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
        foreach ($this->positionService->getAllPositions() as $symbolRaw => $positions) {
            foreach ($positions as $position) {
                $this->orderService->closeByMarket($position, $position->size);
            }
        }

        return Command::SUCCESS;
//        $this->exchangeService->getTickers(Symbol::BTCUSDT, Symbol::ETHUSDT);
    }

    public function __construct(
        private readonly ByBitLinearPositionService $positionService,
        private readonly OrderServiceInterface $orderService,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
