<?php

namespace App\Command\Position;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\PositionDependentCommand;
use App\Domain\BuyOrder\BuyOrdersCollection;
use App\Domain\Price\PriceRange;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(name: 'p:move-info')]
class PositionMoveInfoCommand extends AbstractCommand implements PositionDependentCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption('to', '-t', InputOption::VALUE_REQUIRED, 'Move to price')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $position = $this->getPosition();

        $fromPrice = $this->exchangeService->ticker($position->symbol)->indexPrice;
        $toPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback('to');
        $buyOrders = $this->buyOrderRepository->findActive(
            symbol: $this->getSymbol(),
            side: $position->side,
            qbModifier: function (QueryBuilder $qb) use ($position) {
                QueryHelper::addOrder($qb, 'volume', 'ASC');
                QueryHelper::addOrder($qb, 'price', $position->isShort() ? 'ASC' : 'DESC');
            }
        );

        $buyOrders = (new BuyOrdersCollection(...$buyOrders))->grabFromRange(PriceRange::create($fromPrice, $toPrice, $this->getSymbol()));

        $valueSum = $position->size * $position->entryPrice;
        $qtySum = $position->size;
        foreach ($buyOrders as $buyOrder) {
            $valueSum += $buyOrder->getVolume() * $buyOrder->getPrice();
            $qtySum += $buyOrder->getVolume();
        }
        $newEntry = $valueSum / $qtySum;

        $this->io->note(sprintf('Price diff: %.2f', $newEntry - $position->entryPrice));

        if ($position->isSupportPosition()) {
            $this->io->note(sprintf('Result hedge support size: %.3f', $position->size + $buyOrders->totalVolume()));
        } else {
            $this->io->note(sprintf('Volume diff: %.3f', $buyOrders->totalVolume()));
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly BuyOrderRepository $buyOrderRepository,
        PositionServiceInterface $positionService,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
