<?php

namespace App\Command\Stop;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Order\Order;
use App\Domain\Order\OrdersGrid;
use App\Domain\Price\Price;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_merge;
use function implode;
use function in_array;
use function iterator_to_array;
use function sprintf;
use function uniqid;

#[AsCommand(name: 'sl:grid:by-pnl')]
class CreateSLGridByPnlRangeCommand extends Command
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use AdditionalStopContextAwareCommand;

    public const DEFAULT_TRIGGER_DELTA = 17;

    private const BY_PRICE_STEP = 'by_step';
    private const BY_ORDERS_QNT = 'by_qnt';

    private const MODES = [
        self::BY_PRICE_STEP,
        self::BY_ORDERS_QNT,
    ];

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument('forVolume', InputArgument::REQUIRED, 'Volume value || $ of position size')
            ->addOption('from', '-f', InputOption::VALUE_REQUIRED, '`from` price | PNL%')
            ->addOption('to', '-t', InputOption::VALUE_REQUIRED, '`to` price | PNL%')
            ->addOption('mode', '-m', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::MODES) . ')', self::BY_ORDERS_QNT)
            ->addOption('ordersQnt', '-c', InputOption::VALUE_OPTIONAL, 'Grid orders count')
            ->addOption('priceStep', '-s', InputOption::VALUE_OPTIONAL, 'Grid PriceStep')
            ->configureStopAdditionalContexts()
        ;
    }

    /**
     * @see \App\Tests\Functional\Command\Stop\CreateSLGridByPnlRangeCommandTest
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $this->withInput($input);

        // @todo | tD ?
        // @todo | For price value ?
        $priceRange = $this->getPriceRange();
        $forVolume = $this->getForVolumeParam();
        $mode = $this->getModeParam();
        $position = $this->getPosition();

        if ($forVolume >= $position->size) {
            throw new LogicException('$forVolume is greater than whole position size');
        }

        if (($forVolume > $position->size / 3) && !$io->confirm(sprintf('Are you sure?'))) {
            return Command::FAILURE;
        }

        $uniqueId = $this->uniqueIdGenerator->generateUniqueId('inc-sl-grid.');
        $context = ['uniqid' => $uniqueId];
        if ($additionalContext = $this->getAdditionalStopContext()) {
            $context = array_merge($context, $additionalContext);
        }

        $stopsGrid = new OrdersGrid($priceRange);

        if ($mode === self::BY_ORDERS_QNT) {
            if ($input->getOption('ordersQnt') === null) {
                throw new LogicException(sprintf('In \'%s\' mode param "%s" is required', $mode, 'ordersQnt'));
            }
            $qnt = $this->paramFetcher->getIntOption('ordersQnt');

            /** @var Order[] $orders */
            $orders = iterator_to_array($stopsGrid->ordersByQnt($forVolume, $qnt));
//            if (!$io->confirm(sprintf('Count: %d, ~Volume: %.3f. Are you sure?', $qnt, $orders[0]->volume()))) {
//                return Command::FAILURE;
//            }
        } else {
            throw new InvalidArgumentException('"by_step" mode not implemented yet.');
        }

        $alreadyStopped = 0;
        $stops = $this->stopRepository->findActive($position->side);
        foreach ($stops as $stop) {
            $alreadyStopped += $stop->getVolume();
        }

        if ($position->size <= $alreadyStopped) {
            if (!$io->confirm('All position volume already under SL\'ses. Want to continue? ')) {
                return Command::FAILURE;
            }
        }

        foreach ($orders as $order) {
            $this->stopService->create($position->side, $order->price()->value(), $order->volume(), self::DEFAULT_TRIGGER_DELTA, $context);
        }

        $io->success(sprintf('Stops grid created. uniqueID: %s', $uniqueId));

        return Command::SUCCESS;
    }

    private function getModeParam(): string
    {
        $mode = $this->paramFetcher->getStringOption('mode');
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $mode provided (%s)', $mode),
            );
        }

        return $mode;
    }

    private function getPriceFromPnlPercentOptionWithFloatFallback(string $name): Price
    {
        try {
            $pnlValue = $this->paramFetcher->getPercentOption($name);
            return PnlHelper::getTargetPriceByPnlPercent($this->getPosition(), $pnlValue);
        } catch (InvalidArgumentException) {
            return Price::float($this->paramFetcher->getFloatOption($name));
        }
    }

    private function getPriceRange(): PriceRange
    {
        $fromPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback('from');
        $toPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback('to');
        if ($fromPrice->greater($toPrice)) {
            [$fromPrice, $toPrice] = [$toPrice, $fromPrice];
        }
        return new PriceRange($fromPrice, $toPrice);
    }

    private function getForVolumeParam(): float
    {
        try {
            $positionSizePart = $this->paramFetcher->getPercentArgument('forVolume');
            $forVolume = $this->getPosition()->getVolumePart($positionSizePart);
        } catch (InvalidArgumentException) {
            $forVolume = $this->paramFetcher->getFloatArgument('forVolume');
        }

        return $forVolume;
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
