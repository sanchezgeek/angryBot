<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_filter;
use function count;
use function sprintf;

#[AsCommand(name: 'sl:remove-stale')]
class RemoveStaleStopsCommand extends AbstractCommand
{
    use PositionAwareCommand;

    protected function configure(): void
    {
        $this->configurePositionArgs();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $positionSide = $this->getPositionSide();

        $stopsPushedToExchange = $this->stopRepository->findPushedToExchange($this->getSymbol(), $positionSide);
        $activeConditionalOrders = $this->exchangeService->activeConditionalOrders($this->getSymbol());

        $staleStops = array_filter($stopsPushedToExchange, static function(Stop $stop) use ($activeConditionalOrders) {
            return !isset($activeConditionalOrders[$stop->getExchangeOrderId()]);
        });

        if ($staleStops) {
            foreach ($staleStops as $stop) {
                $this->stopRepository->remove($stop);
            }

            $this->io->note(sprintf('Stops removed! Qnt: %d', count($staleStops)));
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
