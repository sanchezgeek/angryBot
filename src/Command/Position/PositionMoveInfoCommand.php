<?php

namespace App\Command\Position;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Infrastructure\ByBit\ExchangeService;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\BuyOrder\BuyOrdersCollection;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(name: 'pos:p-info')]
class PositionMoveInfoCommand extends Command
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
        $io = new SymfonyStyle($input, $output); $this->withInput($input);
        $position = $this->getPosition();

        $fromPrice = Price::float($this->exchangeService->ticker(Symbol::BTCUSDT)->indexPrice);
        $toPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback('to');
        if ($fromPrice->greater($toPrice)) {
            [$fromPrice, $toPrice] = [$toPrice, $fromPrice];
        }
        $priceRange = new PriceRange($fromPrice, $toPrice);

        $buyOrders = $this->buyOrderRepository->findActive(
            side: $position->side,
            qbModifier: function (QueryBuilder $qb) use ($position) {
                QueryHelper::addOrder($qb, 'volume', 'ASC');
                QueryHelper::addOrder($qb, 'price', $position->isShort() ? 'ASC' : 'DESC');
            }
        );

        $buyOrders = new BuyOrdersCollection(...$buyOrders);
        $buyOrders = $buyOrders->grabFromRange($priceRange);

        $valueSum = $position->size * $position->entryPrice;
        $qtySum = $position->size;
        foreach ($buyOrders as $buyOrder) {
            $valueSum += $buyOrder->getVolume() * $buyOrder->getPrice();
            $qtySum += $buyOrder->getVolume();
        }
        $newEntry = $valueSum / $qtySum;

        $io->note(sprintf('Price diff: %.2f', $newEntry - $position->entryPrice));

        $isHedge = ($oppositePosition = $this->positionService->getOppositePosition($position)) !== null;
        if ($isHedge && Hedge::create($position, $oppositePosition)->isSupportPosition($position)) {
            $io->note(sprintf('Result hedge support size: %.3f', $position->size + $buyOrders->totalVolume()));
        } else {
            $io->note(sprintf('Volume diff: %.3f', $buyOrders->totalVolume()));
        }

        return Command::SUCCESS;
    }

    public function __construct(
        private readonly ExchangeService $exchangeService,
        private readonly BuyOrderRepository $buyOrderRepository,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
