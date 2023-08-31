<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Order\Order;
use App\Domain\Order\OrdersGrid;
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

    private const DEFAULT_TRIGGER_DELTA = 17;

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
            ->addOption('fromPnl', '-f', InputOption::VALUE_REQUIRED, 'fromPnl (%)')
            ->addOption('toPnl', '-t', InputOption::VALUE_REQUIRED, 'toPnl (%)')
            ->addOption('mode', '-m', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::MODES) . ')', self::BY_ORDERS_QNT)
            ->addOption('ordersQnt', '-c', InputOption::VALUE_OPTIONAL, 'Grid orders count')
            ->addOption('priceStep', '-s', InputOption::VALUE_OPTIONAL, 'Grid PriceStep')
            ->configureStopAdditionalContexts()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $this->withInput($input);

        // @todo | tD ?
        // @todo | For price value ?
        $fromPnl = $this->paramFetcher->getPercentOption('fromPnl');
        $toPnl = $this->paramFetcher->getPercentOption('toPnl');
        $forVolume = $this->getForVolumeParam();
        $mode = $this->getModeParam();
        $position = $this->getPosition();

        if ($forVolume >= $position->size) {
            throw new LogicException('$forVolume is greater than whole position size');
        }

        if (($forVolume > $position->size / 3) && !$io->confirm(sprintf('Are you sure?'))) {
            return Command::FAILURE;
        }

        $context = ['uniqid' => $uniqueId = uniqid('inc-create', true)];
        if ($additionalContext = $this->getAdditionalStopContext()) {
            $context = array_merge($context, $additionalContext);
        }

        $stopsGrid = OrdersGrid::byPositionPnlRange($position, $fromPnl, $toPnl);

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

        $io->success(
            sprintf('Stops grid created. uniqueID: %s', $uniqueId)
        );

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

    private function getForVolumeParam(): float
    {
        $position = $this->getPosition();

        try {
            $positionSizePart = $this->paramFetcher->getPercentArgument('forVolume');
            $forVolume = $position->getVolumePart($positionSizePart);
        } catch (InvalidArgumentException) {
            $forVolume = $this->paramFetcher->getFloatArgument('forVolume');
        }

        return $forVolume;
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
