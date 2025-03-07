<?php

namespace App\Command\Stop;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\OppositeOrdersDistanceAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Order\Order;
use App\Domain\Order\OrdersGrid;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\StopsCollection;
use Exception;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Throwable;

use ValueError;

use function array_merge;
use function implode;
use function in_array;
use function iterator_to_array;
use function sprintf;

/** @see CreateStopsGridCommandTest */
#[AsCommand(name: 'sl:grid')]
class CreateStopsGridCommand extends AbstractCommand
{
    use PositionAwareCommand;
    use PriceRangeAwareCommand;
    use AdditionalStopContextAwareCommand;
    use OppositeOrdersDistanceAwareCommand;

    private const BY_PRICE_STEP = 'by_step';
    private const BY_ORDERS_QNT = 'by_qnt';

    private const MODES = [
        self::BY_PRICE_STEP,
        self::BY_ORDERS_QNT,
    ];

    public const FOR_VOLUME_OPTION = 'forVolume';
    public const MODE_OPTION = 'mode';
    public const ORDERS_QNT_OPTION = 'ordersQnt';
    public const TRIGGER_DELTA_OPTION = 'triggerDelta';

    private const DEFAULT_ORDERS_QNT = '10';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->configurePriceRangeArgs()
            ->configureOppositeOrdersDistanceOption(alias: 'o')
            ->addArgument(self::FOR_VOLUME_OPTION, InputArgument::REQUIRED, 'Volume value || $ of position size')
            ->addOption(self::MODE_OPTION, '-m', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::MODES) . ')', self::BY_ORDERS_QNT)
            ->addOption(self::ORDERS_QNT_OPTION, '-c', InputOption::VALUE_OPTIONAL, 'Grid orders count', self::DEFAULT_ORDERS_QNT)
            ->addOption(self::TRIGGER_DELTA_OPTION, '-d', InputOption::VALUE_OPTIONAL, 'Stop trigger delta')
            ->configureStopAdditionalContexts()
        ;
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

        $stopsGrid = new OrdersGrid($priceRange);

        if ($mode === self::BY_ORDERS_QNT) {
            $qnt = $this->paramFetcher->getIntOption(
                self::ORDERS_QNT_OPTION,
                sprintf('In \'%s\' mode param "%s" is required.', $mode, self::ORDERS_QNT_OPTION)
            );

            /** @var Order[] $orders */
            $orders = iterator_to_array($stopsGrid->ordersByQnt($forVolume, $qnt));
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
            sprintf('For delete them just run:' . PHP_EOL . './bin/console sl:edit --symbol=%s %s -aremove --fC="getContext(\'uniqid\')===\'%s\'"', $symbol->value, $positionSide->value, $uniqueId) . PHP_EOL . PHP_EOL
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
            $positionSizePart = $this->paramFetcher->getPercentArgument(self::FOR_VOLUME_OPTION);
            $forVolume = $this->getPosition()->getVolumePart($positionSizePart);
        } catch (InvalidArgumentException) {
            $forVolume = $this->paramFetcher->getFloatArgument(self::FOR_VOLUME_OPTION);
        }

        return $forVolume;
    }

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
