<?php

namespace App\Command\Position;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Order\OrdersGrid;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\VolumeHelper;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function explode;
use function preg_match;
use function random_int;
use function round;
use function sprintf;

#[AsCommand(name: 'p:open')]
class OpenCommand extends AbstractCommand
{
    use PositionAwareCommand;

    public const DEBUG_OPTION = 'deb';

    public const REOPEN_OPTION = 'reopen';
    public const SIZE_ARGUMENT = 'size';
    public const GRID_RANGE_OPTION = 'gridRange';
    public const STOPS_GRIDS_DEF_OPTION = 'stopGridsDef';
    public const TRIGGER_DELTA_OPTION = 'triggerDelta';

    private const DEFAULT_GRID_RANGE = '10%|15%';
    public const DEFAULT_BUY_GRID_QNT = 20;

    private const DEFAULT_STOPS_GRIDS_DEF = '-40|30%,-100|20%,-125|20%,-200|15%';
    public const DEFAULT_STOP_GRID_QNT = 2;
    public const DEFAULT_TRIGGER_DELTA = '37';


    private Symbol $symbol;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument(self::SIZE_ARGUMENT, InputArgument::REQUIRED, 'Position size or %')
            ->addOption(self::GRID_RANGE_OPTION, 'r', InputOption::VALUE_REQUIRED, 'Grid range', self::DEFAULT_GRID_RANGE)
            ->addOption(self::STOPS_GRIDS_DEF_OPTION, 's', InputOption::VALUE_REQUIRED, 'Stop grids def', self::DEFAULT_STOPS_GRIDS_DEF)
            ->addOption(self::TRIGGER_DELTA_OPTION, 'd', InputOption::VALUE_OPTIONAL, 'Stops trigger delta', self::DEFAULT_TRIGGER_DELTA)
            ->addOption(self::REOPEN_OPTION, null, InputOption::VALUE_NEGATABLE, 'Reopen position?')
            ->addOption(self::DEBUG_OPTION, null, InputOption::VALUE_NEGATABLE, 'Debug?')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->symbol = Symbol::BTCUSDT;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // @todo | reopen | remove all BO left from previous position (without `uniq` or just bigger than 0.005)

        $reopenPosition = $this->paramFetcher->getBoolOption(self::REOPEN_OPTION);
        if ($reopenPosition && ($closePositionError = $this->closeCurrentPosition()) !== null) {
            return $closePositionError;
        }

        $positionSide = $this->getPositionSide();
        $symbolTicker = $this->getTicker($this->symbol);

        if ($this->isDebugEnabled()) {
            $this->io->warning('Debug enabled. Terminate.');
            return Command::FAILURE;
        }

        $this->removeCurrentPositionStops();

        $size = $this->getSizeArgument();

        $expectedPosition = new Position($positionSide, $this->symbol, $symbolTicker->indexPrice, $size, $size * $symbolTicker->indexPrice, 0, 10, 100);

        $this->createStopsGrid($expectedPosition);

        ['range' => $buyGridRange, 'part' => $buyGridPart] = $this->getGridRangeOption($expectedPosition);
        $buyGridVolume = $buyGridPart->of($size);
        $buyGridOrdersVolumeSum = $this->createBuyOrdersGrid($buyGridRange, $buyGridVolume, self::DEFAULT_BUY_GRID_QNT);

        // market
        $marketBuyVolume = VolumeHelper::round($size - $buyGridOrdersVolumeSum); // $marketBuyPart = Percent::fromString('100%')->sub($gridPart); $marketBuyVolume = $marketBuyPart->of($size);
        $this->positionService->marketBuy($expectedPosition, $marketBuyVolume);

        return Command::SUCCESS;
    }

    private function closeCurrentPosition(): ?int
    {
        $position = $this->getPosition(false);
        if (!$position) {
            $this->io->warning('Position not found. Skip closeCurrentPosition.');
            return null;
        }

        $unrealizedPnl = $position->unrealizedPnl;
        if (!$this->io->confirm(sprintf('Current unrealized PNL: %.3f', $unrealizedPnl))) {
            return Command::FAILURE;
        }

        if ($unrealizedPnl >= 0) {
            $this->io->warning(sprintf('Current unrealized PNL: %.3f. Reopen denied.', $unrealizedPnl));
            return Command::FAILURE;
        }

        if ($this->isDebugEnabled()) {
            return null;
        }

        $this->tradeService->closeByMarket($position, $position->size);

        $currentLoss = PriceHelper::round(-$unrealizedPnl, 2);
        $contractBalance = $this->accountService->getContractWalletBalance($this->symbol->associatedCoin());
        if ($contractBalance->availableBalance > 2) {
            $this->accountService->interTransferFromSpotToContract(
                $this->symbol->associatedCoin(),
                min(PriceHelper::round($contractBalance->availableBalance - 1, 2), $currentLoss)
            );
        }

        return null;
    }

    public function removeCurrentPositionStops(): void
    {
        foreach ($this->stopRepository->findActive($this->getPositionSide()) as $stop) {
            $this->entityManager->remove($stop);
        }
        $this->entityManager->flush();
    }

    /**
     * @return float Created BuyOrders volume sum
     */
    private function createBuyOrdersGrid(PriceRange $gridPriceRange, float $forVolume, int $ordersQnt): float
    {
        $context = [BuyOrder::WITHOUT_OPPOSITE_ORDER_CONTEXT => true];
        $side = $this->getPositionSide();
        $buyOrdersGrid = new OrdersGrid($gridPriceRange);

        $volumeSum = 0;
        foreach ($buyOrdersGrid->ordersByQnt($forVolume, $ordersQnt) as $order) {
            $rand = round(random_int(-7, 8) * 0.4, 2);

            $this->createBuyOrderHandler->handle(
                new CreateBuyOrderEntryDto($side, $order->volume(), $order->price()->sub($rand)->value(), $context)
            );

            $volumeSum += $order->volume();
        }

        return $volumeSum;
    }

    private function createStopsGrid(Position $position, int $rangeOrdersQnt = self::DEFAULT_STOP_GRID_QNT): void
    {
        $stopsContext = [];
        $triggerDelta = $this->paramFetcher->requiredFloatOption(self::TRIGGER_DELTA_OPTION);

        $gridDefs = explode(',', $this->paramFetcher->getStringOption(self::STOPS_GRIDS_DEF_OPTION));
        foreach ($gridDefs as $item) {
            $parts = explode('|', $item);
            $fromPercent = (float)$parts[0];
            $toPercent = $fromPercent + 3;

            $fromPrice = PnlHelper::getTargetPriceByPnlPercent($position, $fromPercent);
            $toPrice = PnlHelper::getTargetPriceByPnlPercent($position, $toPercent);

            $priceRange = PriceRange::create($fromPrice, $toPrice);

            $stopsGrid = new OrdersGrid($priceRange);

            $volumePart = Percent::string($parts[1]);
            $forVolume = $volumePart->of($position->size);

            foreach ($stopsGrid->ordersByQnt($forVolume, $rangeOrdersQnt) as $order) {
                $this->stopService->create($position->side, $order->price()->value(), $order->volume(), $triggerDelta, $stopsContext);
            }
        }
    }

    private function getTicker(Symbol $symbol): Ticker
    {
         return $this->exchangeService->ticker($symbol);
    }

    private function getSizeArgument(): float
    {
        $availableBalancePart = new Percent($this->paramFetcher->getPercentArgument(self::SIZE_ARGUMENT));

        $contractBalance = $this->accountService->getContractWalletBalance($this->symbol->associatedCoin());
        if (!($contractBalance->availableBalance > 0)) {
            throw new RuntimeException('Insufficient contract balance');
        }

        $contractCost = $this->getCurrentContractCost($this->symbol);

        return $availableBalancePart->of($contractBalance->availableBalance / $contractCost);
    }

    private function getCurrentContractCost(Symbol $symbol): float
    {
        $symbolTicker = $this->getTicker($symbol);
        # price/<margin>?
        return $symbolTicker->indexPrice / 100 * (1+0.1);
    }

    /**
     * @return array{range: PriceRange, part: Percent}
     */
    private function getGridRangeOption(Position $expectedPosition): array
    {
        $gridRangesDef = $this->paramFetcher->getStringOption(self::GRID_RANGE_OPTION);

        $pattern = '/\d+%\|\d+%/';
        if (!preg_match($pattern, $gridRangesDef)) {
            throw new InvalidArgumentException(
                sprintf('Invalid `%s` def "%s" ("%s" expected)', self::GRID_RANGE_OPTION, $gridRangesDef, $pattern)
            );
        }

        $parts = explode('|', $gridRangesDef);
        $rangePnl = Percent::string($parts[0]); $sizePart = Percent::string($parts[1]);

        return [
            'range' => PriceRange::byPositionPnlRange($expectedPosition, -$rangePnl->value(), $rangePnl->value()),
            'part' => $sizePart
        ];
    }

    private function isDebugEnabled(): bool
    {
        return $this->paramFetcher->getBoolOption(self::DEBUG_OPTION);
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeAccountServiceInterface $accountService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly OrderServiceInterface $tradeService,
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly StopService $stopService,
        private readonly StopRepository $stopRepository,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
