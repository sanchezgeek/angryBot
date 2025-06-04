<?php

namespace App\Command\Stop;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\OppositeOrdersDistanceAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\PositionDependentCommand;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\OrdersGrid;
use App\Domain\Stop\StopsCollection;
use App\Trading\Application\Symbol\Exception\SymbolNotFoundException;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_merge;
use function implode;
use function in_array;
use function iterator_to_array;
use function sprintf;

/** @see CreateStopsGridCommandTest */
#[AsCommand(name: 'sl:grid')]
class CreateStopsGridCommand extends AbstractCommand implements PositionDependentCommand
{
    use PositionAwareCommand;
    use PriceRangeAwareCommand;
    use AdditionalStopContextAwareCommand;
    use OppositeOrdersDistanceAwareCommand;

    public const NAME = 'sl:grid';

    private const BY_PRICE_STEP = 'by_step';
    private const BY_ORDERS_QNT = 'by_qnt';

    private const MODES = [
        self::BY_PRICE_STEP,
        self::BY_ORDERS_QNT,
    ];

    public const FOR_VOLUME_ARGUMENT = 'forVolume';
    public const MODE_OPTION = 'mode';
    public const ORDERS_QNT_OPTION = 'ordersQnt';
    public const TRIGGER_DELTA_OPTION = 'triggerDelta';

    private const DEFAULT_ORDERS_QNT = '10';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument(self::FOR_VOLUME_ARGUMENT, InputArgument::REQUIRED, 'Volume value || $ of position size')
            ->addOption(self::MODE_OPTION, '-m', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::MODES) . ')', self::BY_ORDERS_QNT)
        ;

        self::configureStopsGridArguments($this);
    }

    /**
     * @param AbstractCommand&PositionAwareCommand&PriceRangeAwareCommand&OppositeOrdersDistanceAwareCommand&AdditionalStopContextAwareCommand $cmd
     */
    public static function configureStopsGridArguments(AbstractCommand $cmd): void
    {
        $cmd->configureWithConfigurators([
            static fn(Command $cmd) => /* @var $cmd PositionAwareCommand */ $cmd->configurePositionArgs(),
            static fn(Command $cmd) => /* @var $cmd OppositeOrdersDistanceAwareCommand */ $cmd->configureOppositeOrdersDistanceOption(alias: 'o'),
            static fn(Command $cmd) => /* @var $cmd PriceRangeAwareCommand */ $cmd->configurePriceRangeArgs(),
            static fn(Command $cmd) => $cmd->addOption(self::ORDERS_QNT_OPTION, '-c', InputOption::VALUE_OPTIONAL, 'Grid orders count', self::DEFAULT_ORDERS_QNT),
            static fn(Command $cmd) => $cmd->addOption(self::TRIGGER_DELTA_OPTION, '-d', InputOption::VALUE_OPTIONAL, 'Stop trigger delta'),
            static fn(Command $cmd) => /* @var $cmd AdditionalStopContextAwareCommand */ $cmd->configureStopAdditionalContexts(),
        ]);
    }

//    protected function getPositionSide(): Side|string
//    {
//        $argName = self::POSITION_SIDE_ARGUMENT_NAME;
//        $providedPositionSideValue = $this->paramFetcher->getStringArgument($argName);
//        try {
//            $positionSide = Side::from($providedPositionSideValue);
//        } catch (ValueError $e) {
//            if (!in_array($providedPositionSideValue, ['hedge'], true)) {
//                throw new InvalidArgumentException($e->getMessage());
//            }
//        }
//
//        return $positionSide;
//    }

    /**
     * @throws SymbolNotFoundException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // @todo | tD ?
        $symbol = $this->getSymbol();
        $priceRange = $this->getPriceRange();
        $forVolume = $this->getForVolumeParam();
        $mode = $this->getModeParam();
        $triggerDelta = $this->paramFetcher->floatOption(self::TRIGGER_DELTA_OPTION);
        $positionSide = $this->getPositionSide();

        $position = null;
        try {
            $position = $this->getPosition();
        } catch (Throwable $e) {
            $this->io->warning($e->getMessage());
        }

        if ($position && $forVolume >= $position->size) {
            throw new LogicException('$forVolume is greater than whole position size');
        }

        if ($position && ($forVolume > $position->size / 3 && !$this->io->confirm('Are you sure?'))) {
            return Command::FAILURE;
        }

        $context = ['uniqid' => $uniqueId = $this->uniqueIdGenerator->generateUniqueId('sl-grid')];
        if ($additionalContext = $this->getAdditionalStopContext()) {
            $context = array_merge($context, $additionalContext);
        }

        if ($oppositeBuyOrdersDistance = $this->getOppositeOrdersDistanceOption($symbol)) {
            $context[Stop::OPPOSITE_ORDERS_DISTANCE_CONTEXT] = $oppositeBuyOrdersDistance;
        }

        $stopsGrid = new OrdersGrid($priceRange, $positionSide);

        if ($mode === self::BY_ORDERS_QNT) {
            $qnt = $this->paramFetcher->getIntOption(
                self::ORDERS_QNT_OPTION,
                sprintf('In \'%s\' mode param "%s" is required.', $mode, self::ORDERS_QNT_OPTION)
            );

            /** @todo | Context =( */
            $stopsHasOppositeBuyOrders = ($context[Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT] ?? false) === false;

            /** @todo | DRY | with OpenPositionHandler */
            $roundVolumeToMin = $stopsHasOppositeBuyOrders;

            $orders = iterator_to_array($stopsGrid->ordersByQnt($forVolume, $qnt));
            $orders = new OrdersCollection(...$orders);
            if ($roundVolumeToMin) {
                $orders = new OrdersWithMinExchangeVolume($symbol, $orders);
            }

            $volumeSum = 0;
            foreach ($orders as $order) {
                $volumeSum += $order->volume();
            }
            $volumeSum = $symbol->roundVolume($volumeSum);

            if ($volumeSum > $forVolume) {
                if ($this->io->confirm(
                    sprintf(
                        'Result volume (%f) of all %d orders is greater than initially expected (%f). Do you wish to merge orders to fit initially expected volume?',
                        $volumeSum, count($orders), $forVolume
                    )
                )) {
                    # strict
                    $orders = new OrdersLimitedWithMaxVolume($orders, $forVolume);
                }
            }
//            if (!$this->io->confirm(sprintf('Count: %d, ~Volume: %.3f. Are you sure?', $qnt, $orders[0]->volume()))) {
//                return Command::FAILURE;
//            }
        } else {
            throw new InvalidArgumentException('"by_step" mode not implemented yet.');
        }

        $alreadyStopped = 0;
        $stops = new StopsCollection(...$this->stopRepository->findActive($symbol, $positionSide));
        $stops = $stops->filterWithCallback(static fn (Stop $stop) => !$stop->isTakeProfitOrder());
        foreach ($stops as $stop) {
            $alreadyStopped += $stop->getVolume();
        }

        if ($position && $position->size <= $alreadyStopped) {
            if (!$this->io->confirm('All position volume already under SL\'ses. Want to continue? ')) {
                return Command::FAILURE;
            }
        }

        foreach ($orders as $order) {
            $this->stopService->create($symbol, $positionSide, $order->price()->value(), $order->volume(), $triggerDelta, $context);
        }

        $this->io->success(sprintf('Stops grid created. uniqueID: %s', $uniqueId));
        $this->io->writeln(
            sprintf('For delete them just run:' . PHP_EOL . './bin/console sl:edit --symbol=%s %s -aremove --fC="getContext(\'uniqid\')===\'%s\'"', $symbol->name(), $positionSide->value, $uniqueId) . PHP_EOL . PHP_EOL
        );

        return Command::SUCCESS;
    }

    private function getModeParam(): string
    {
        $mode = $this->paramFetcher->getStringOption(self::MODE_OPTION);
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $mode provided (%s)', $mode),
            );
        }

        return $mode;
    }

    private function getForVolumeParam(): float
    {
        try {
            $positionSizePart = $this->paramFetcher->getPercentArgument(self::FOR_VOLUME_ARGUMENT);
            $forVolume = $this->getPosition()->getVolumePart($positionSizePart);
        } catch (InvalidArgumentException) {
            $forVolume = $this->paramFetcher->getFloatArgument(self::FOR_VOLUME_ARGUMENT);
        }

        return $forVolume;
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
