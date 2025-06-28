<?php

namespace App\Command\Stop;

use App\Application\UniqueIdGeneratorInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\PositionDependentCommand;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_merge;
use function sprintf;

/** @see CreateStopsGridCommandTest */
#[AsCommand(name: 'sl:stop')]
class CreatePosStopCommand extends AbstractCommand implements PositionDependentCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use AdditionalStopContextAwareCommand;

    public const FOR_VOLUME_OPTION = 'forVolume';
    public const ORDERS_QNT_OPTION = 'ordersQnt';
    public const TRIGGER_DELTA_OPTION = 'triggerDelta';

    private const DEFAULT_ORDERS_QNT = '2';
    private const DELTA = 50;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addArgument(self::FOR_VOLUME_OPTION, InputArgument::REQUIRED, 'Volume value || $ of position size')
            ->addOption(self::ORDERS_QNT_OPTION, '-c', InputOption::VALUE_OPTIONAL, 'Grid orders count', self::DEFAULT_ORDERS_QNT)
            ->addOption(self::TRIGGER_DELTA_OPTION, '-d', InputOption::VALUE_OPTIONAL, 'Stop trigger delta')
            ->configureStopAdditionalContexts()
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $forVolume = $this->getForVolumeParam();
        $position = $this->getPosition();
        $triggerDelta = $this->paramFetcher->floatOption(self::TRIGGER_DELTA_OPTION);

        if (($forVolume > $position->size / 3) && !$this->io->confirm(sprintf('Are you sure?'))) {
            return Command::FAILURE;
        }

        $context = ['uniqid' => $uniqueId = $this->uniqueIdGenerator->generateUniqueId('sl-grid')];
        if ($additionalContext = $this->getAdditionalStopContext()) {
            $context = array_merge($context, $additionalContext);
        }

        if (!$position->liquidationPrice) {
            throw new InvalidArgumentException(
                sprintf('Position on %s side has no liquidationPrice', $position->side->title())
            );
        }
//        $fromPrice = Price::float($position->liquidationPrice);

        $ticker = $this->exchangeService->ticker($this->getSymbol());
        $price = ($position->liquidationPrice + $ticker->indexPrice->value()) / 2;

//        $toPrice = $position->isShort() ? $fromPrice->sub(self::DELTA) : $fromPrice->add(self::DELTA);
//        $stopsGrid = new OrdersGrid(PriceRange::create($fromPrice, $toPrice));
//        $qnt = $this->paramFetcher->getIntOption(self::ORDERS_QNT_OPTION, sprintf('Param "%s" is required.', self::ORDERS_QNT_OPTION));
//        /** @var Order[] $orders */
//        $orders = iterator_to_array($stopsGrid->ordersByQnt($forVolume, $qnt));

        $alreadyStopped = 0;
        $stops = $this->stopRepository->findActive($position->symbol, $position->side);
        foreach ($stops as $stop) {
            $alreadyStopped += $stop->getVolume();
        }

        if ($position->size <= $alreadyStopped) {
            if (!$this->io->confirm('All position volume already under SL\'ses. Want to continue? ')) {
                return Command::FAILURE;
            }
        }

        $this->stopService->create($position->symbol, $position->side, $price, $forVolume, $triggerDelta, $context);

//        foreach ($orders as $order) {
//            $this->stopService->create($position->side, $order->price()->value(), $order->volume(), $triggerDelta, $context);
//        }

        $this->io->success(sprintf('Stops grid created. uniqueID: %s', $uniqueId));

        return Command::SUCCESS;
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
        private readonly ExchangeServiceInterface $exchangeService,
        PositionServiceInterface $positionService,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
