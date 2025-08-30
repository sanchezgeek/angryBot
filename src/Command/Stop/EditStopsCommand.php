<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\PositionDependentCommand;
use App\Command\TradingParametersDependentCommand;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\StopsCollection;
use App\Infrastructure\Doctrine\Helper\QueryHelper;
use App\Stop\Infrastructure\Cache\StopsCache;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use DomainException;
use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function sprintf;

// @todo | user-interaction | handlers

#[AsCommand(name: 'sl:edit')]
class EditStopsCommand extends AbstractCommand implements PositionDependentCommand, TradingParametersDependentCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use PriceRangeAwareCommand;
    use AdditionalStopContextAwareCommand;

    private const DEFAULT_TRIGGER_DELTA = 20;

    public const ACTION_OPTION = 'action';

    /** `move`-action options */
    public const MOVE_PART_OPTION = 'movePart';
    public const MOVE_TO_PRICE_OPTION = 'moveToPrice';

    /** `edit`-action options */
    public const EDIT_CALLBACK_OPTION = 'eC';

    /** Common options */
    public const FILTER_CALLBACKS_OPTION = 'fC';

    public const ACTION_MOVE = 'move';      /** @see MoveStopsInRangeTest */
    public const ACTION_REMOVE = 'remove';  /** @see RemoveStopsInRangeTest */
    public const ACTION_EDIT = 'edit';
    private const ACTIONS = [self::ACTION_MOVE, self::ACTION_REMOVE, self::ACTION_EDIT];

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs(defaultValue: null)
            ->configurePositionArgs(InputArgument::OPTIONAL)
            ->configurePriceRangeArgs()
            ->addOption(self::ACTION_OPTION, '-a', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::ACTIONS) . ')')
            ->addOption(self::MOVE_TO_PRICE_OPTION, null, InputOption::VALUE_REQUIRED, 'Move orders to price | pricePnl%')
            ->addOption(self::MOVE_PART_OPTION, null, InputOption::VALUE_REQUIRED, 'Range volume part (%)')
            ->addOption(self::EDIT_CALLBACK_OPTION, null, InputOption::VALUE_REQUIRED, 'Edit Stop entity callback')
            ->addOption(self::FILTER_CALLBACKS_OPTION, null, InputOption::VALUE_REQUIRED, 'Filter callbacks')
            ->addOption('all', null, InputOption::VALUE_NEGATABLE, 'To select all stops')
        ;
    }

    /**
     * @see \App\Tests\Functional\Command\Stop\CreateSLGridByPnlRangeCommandTest
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->stopsCache->clear();

        $symbol = $this->symbolIsSpecified() ? $this->getSymbol() : null;
        $priceRange = $this->getRange();
        $filterCallbacksOption = $this->paramFetcher->getStringOption(self::FILTER_CALLBACKS_OPTION, false);

        $action = $this->getAction();
        $positionSide = $this->getPositionSide(false);
        try {
            $position = $this->getPosition();
        } catch (Exception $e) {
            $this->io->error($e->getMessage());
            $position = null;
        }

        if ($action === self::ACTION_REMOVE) {
            $stops = $this->stopRepository->findAllByParams($symbol, $positionSide);
        } else {
            $stops = $this->stopRepository->findActive(
                symbol: $symbol,
                side: $positionSide,
                qbModifier: static function (QueryBuilder $qb) use ($positionSide) {
                    QueryHelper::addOrder($qb, 'volume', 'ASC');
                    QueryHelper::addOrder($qb, 'price', $positionSide->isShort() ? 'ASC' : 'DESC');
                    QueryHelper::addOrder($qb, 'triggerDelta', 'ASC');
                }
            );
        }
        $stopsCollection = new StopsCollection(...$stops);

        $stopsInSpecifiedRange = $priceRange ? $stopsCollection->grabFromRange($priceRange) : $stopsCollection;
        $filteredStops = $filterCallbacksOption ? $this->applyFilters($filterCallbacksOption, $stopsInSpecifiedRange) : $stopsInSpecifiedRange;
        $filteredStopsCount = $filteredStops->totalCount();

        if (!$filteredStopsCount) {
            $this->io->info('Stops by specified criteria not found!');
            return Command::SUCCESS;
        }

        if ($filteredStopsCount === count($stops) && !$this->io->confirm('All active stops matched provided conditions. Continue?')) {
            return Command::FAILURE;
        }

        if ($stopsInSpecifiedRange->totalCount() !== $filteredStopsCount) {
            $this->io->info(sprintf('Stops in specified range qnt: %d', $stopsInSpecifiedRange->totalCount()));
        }

        if ($action !== self::ACTION_REMOVE) {
            $this->io->info(sprintf('Filtered stops qnt: %d', $filteredStopsCount));
            if ($position && !$this->io->confirm(
                    sprintf(
                        'You\'re about to %s %d Stops (%.1f%% of specified range, %.1f%% of total, %.1f%% of position size). Continue?',
                        $action,
                        $filteredStopsCount,
                        $filteredStops->volumePart($stopsInSpecifiedRange->totalVolume()),
                        $filteredStops->volumePart($stopsCollection->totalVolume()),
                        $filteredStops->volumePart($position->size)
                    )
                )) {
                return Command::FAILURE;
            }
        }

        if ($action === self::ACTION_MOVE) {
            $price = $this->getPriceFromPnlPercentOptionWithFloatFallback(self::MOVE_TO_PRICE_OPTION);
            $movePart = $this->paramFetcher->requiredPercentOption(self::MOVE_PART_OPTION);
            if ($movePart <= 0 || $movePart > 100) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid \'%s\' PERCENT %s provided: value must be in 1%% .. 100%% range ("%s" given).',
                        self::MOVE_PART_OPTION,
                        'option',
                        $movePart,
                    )
                );
            }
            $needMoveVolume = $symbol->roundVolume($filteredStops->totalVolume() * $movePart / 100);

            $tD = $symbol->stopDefaultTriggerDelta();
            $movedVolume = 0;
            $removed = new StopsCollection();
            while ($needMoveVolume > 0) {
                foreach ($filteredStops as $stop) {
                    if ($stop->getVolume() <= $needMoveVolume) {
                        $removed->add($stop);
                        $filteredStops->remove($stop);
                        $needMoveVolume -= $stop->getVolume();
                        if ($stop->getTriggerDelta() > $tD) {
                            $tD = $stop->getTriggerDelta();
                        }
                    } else {
                        try {
                            $stop->subVolume($needMoveVolume);
                            $movedVolume += $needMoveVolume;
                            $needMoveVolume = 0;
                            if ($stop->getTriggerDelta() > $tD) {
                                $tD = $stop->getTriggerDelta();
                            }
                            continue 2;
                        } catch (DomainException) {
                            continue;
                        }
                    }
                }
            }

            $total = $removed->totalVolume() + $movedVolume;
            $this->entityManager->wrapInTransaction(function() use ($filteredStops, $removed, $positionSide, $price, $total, $tD) {
                foreach ($filteredStops as $stop) {
                    $this->entityManager->persist($stop);
                }

                foreach ($removed as $stopToRemove) {
                    $this->entityManager->remove($stopToRemove);
                }

                // @todo some uniqueid to context
                $this->stopService->create(
                    $this->getSymbol(),
                    $positionSide,
                    $price->value(),
                    $total,
                    $tD,
                );
            });

            $this->io->note(\sprintf('removed stops qnt: %d', $removed->totalCount()));
        }

        if ($action === self::ACTION_REMOVE) {
            $exchangeOrdersIds = array_map(static fn (Stop $stop) => $stop->getExchangeOrderId(), $stops);
            try {
                $pushedStops = $this->exchangeService->activeConditionalOrders($symbol);
            } catch (Exception $e) {
                $this->appErrorLogger->critical(sprintf('Error while get pushed orders: %s', $e->getMessage()));
                $pushedStops = [];
            }

            /** @var ActiveStopOrder[] $pushedStops */
            $pushedStops = array_filter($pushedStops, static fn(ActiveStopOrder $activeStopOrder) => in_array($activeStopOrder->orderId, $exchangeOrdersIds, true));
            $closeActiveCondOrder = $pushedStops && $this->io->confirm('Some orders pushed to exchange. Close?');

            $closedExchangeOrderIds = [];
            if ($closeActiveCondOrder) {
                foreach ($pushedStops as $activeCondOrder) {
                    $this->exchangeService->closeActiveConditionalOrder($activeCondOrder);
                    $closedExchangeOrderIds[] = $activeCondOrder->orderId;
                }
            }

            $filteredStops = $filteredStops->filterWithCallback(static fn(Stop $stop) => !$stop->isOrderPushedToExchange() || in_array($stop->getExchangeOrderId(), $closedExchangeOrderIds));
            $filteredStopsCount = $filteredStops->totalCount();

            $this->io->info(sprintf('Filtered stops qnt: %d', $filteredStopsCount));
            if (!$filteredStopsCount) {
                $this->io->info('Stops by specified criteria not found!');
                return Command::SUCCESS;
            }

            if ($position && !$this->io->confirm(
                    sprintf(
                        'You\'re about to %s %d Stops (%.1f%% of specified range, %.1f%% of total, %.1f%% of position size). Continue?',
                        $action,
                        $filteredStopsCount,
                        $filteredStops->volumePart($stopsInSpecifiedRange->totalVolume()),
                        $filteredStops->volumePart($stopsCollection->totalVolume()),
                        $filteredStops->volumePart($position->size)
                    )
                )) {
                return Command::FAILURE;
            }

            $this->entityManager->wrapInTransaction(function() use ($filteredStops) {
                foreach ($filteredStops as $stop) {
                    $this->entityManager->remove($stop);
                }
            });

            $this->io->note(\sprintf('removed stops qnt: %d', $filteredStops->totalCount()));
        }

        if ($action === self::ACTION_EDIT) {
            $editCallback = $this->paramFetcher->getStringOption(self::EDIT_CALLBACK_OPTION);
            $this->entityManager->wrapInTransaction(function() use ($filteredStops, $editCallback) {
                foreach ($filteredStops as $stop) {
                    eval('$stop->' . $editCallback . ';');
                    $this->entityManager->persist($stop);
                }
            });

            $this->io->note(\sprintf('modified stops qnt: %d', $filteredStopsCount));
        }

        return Command::SUCCESS;
    }

    protected function getRange(): ?PriceRange
    {
        try {
            return $this->getPriceRange(false);
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    private function getAction(): string
    {
        $action = $this->paramFetcher->getStringOption(self::ACTION_OPTION);
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $action provided (%s)', $action),
            );
        }

        return $action;
    }

    private function applyFilters(?string $filterCallbacksOption, StopsCollection $stops): StopsCollection
    {
        if ($filterCallbacksOption && $filterCallbacks = array_map('trim', explode(',', $filterCallbacksOption))) {
            return $stops->filterWithCallback(static function (Stop $stop) use ($filterCallbacks) {
                foreach ($filterCallbacks as $stopFilterCallback) {
                    eval('$callbackResult = $stop->' . $stopFilterCallback . ';');
                    if ($callbackResult !== true) {
                        return false;
                    }
                }

                return true;
            });
        }

        return $stops;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StopRepository $stopRepository,
        private readonly StopService $stopService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly LoggerInterface $appErrorLogger,
        private readonly StopsCache $stopsCache,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
