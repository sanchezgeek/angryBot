<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalStopContextAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\Stop\StopsCollection;
use App\Helper\VolumeHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use DomainException;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function explode;
use function implode;
use function in_array;
use function sprintf;

#[AsCommand(name: 'sl:range-edit')]
class EditStopsCommand extends Command
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
    public const EDIT_CALLBACK_OPTION = 'editCallback';

    /** Common options */
    public const FILTER_CALLBACKS_OPTION = 'filterCallbacks';

    public const ACTION_MOVE = 'move';      /** @see MoveStopsInRangeTest */
    public const ACTION_REMOVE = 'remove';  /** @see RemoveStopsInRangeTest */
    public const ACTION_EDIT = 'edit';
    private const ACTIONS = [self::ACTION_MOVE, self::ACTION_REMOVE, self::ACTION_EDIT];

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->configurePriceRangeArgs()
            ->addOption(self::ACTION_OPTION, '-a', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::ACTIONS) . ')')
            ->addOption(self::MOVE_TO_PRICE_OPTION, null, InputOption::VALUE_REQUIRED, 'Move orders to price | pricePnl%')
            ->addOption(self::MOVE_PART_OPTION, null, InputOption::VALUE_REQUIRED, 'Range volume part (%)')
            ->addOption(self::EDIT_CALLBACK_OPTION, null, InputOption::VALUE_REQUIRED, 'Edit Stop entity callback')
            ->addOption(self::FILTER_CALLBACKS_OPTION, null, InputOption::VALUE_REQUIRED, 'Filter callbacks')
        ;
    }

    /**
     * @see \App\Tests\Functional\Command\Stop\CreateSLGridByPnlRangeCommandTest
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $this->withInput($input);

        $priceRange = $this->getPriceRange();
        $action = $this->getAction();

        $stops = $this->stopRepository->findActive(
            side: $this->getPosition()->side,
            qbModifier: function (QueryBuilder $qb) {
                $qb->orderBy($qb->getRootAliases()[0] . '.volume', 'ASC'); //$qb->orderBy($qb->getRootAliases()[0] . '.price', $this->getPosition()->side->isShort() ? 'ASC' : 'DESC');
                $alias = $qb->getRootAliases()[0];
                $qb->orderBy($alias . '.volume', 'ASC')->addOrderBy($alias . '.price', $this->getPosition()->side->isShort() ? 'ASC' : 'DESC');
            }
        );
        $stopsCollection = new StopsCollection(...$stops);
        $stopsInSpecifiedRange = ($stopsCollection)->grabFromRange($priceRange);
        $filteredStops = $this->applyFilters($stopsInSpecifiedRange);

        if (!$io->confirm(
            sprintf(
                'You\'re about to %s %d Stops (%.1f%% of specified range, %.1f%% of total, %.1f%% of position size). Continue?',
                $action,
                $filteredStops->totalCount(),
                $filteredStops->volumePart($stopsInSpecifiedRange->totalVolume()),
                $filteredStops->volumePart($stopsCollection->totalVolume()),
                $filteredStops->volumePart($this->getPosition()->size)
            )
        )) {
            return Command::FAILURE;
        }

        if ($action === self::ACTION_MOVE) {
            $toPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback(self::MOVE_TO_PRICE_OPTION);
            $movePart = $this->paramFetcher->getPercentOption(self::MOVE_PART_OPTION);
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
            $needMoveVolume = VolumeHelper::round($filteredStops->totalVolume() * $movePart / 100);

            $movedVolume = 0;
            $stopsToRemove = new StopsCollection();
            while ($needMoveVolume > 0) {
                foreach ($filteredStops as $stop) {
                    if ($stop->getVolume() <= $needMoveVolume) {
                        $stopsToRemove->add($stop);
                        $filteredStops->remove($stop);
                        $needMoveVolume -= $stop->getVolume();
                    } else {
                        try {
                            $stop->subVolume($needMoveVolume);
                            $movedVolume += $needMoveVolume;
                            $needMoveVolume = 0;
                            continue 2;
                        } catch (DomainException) {
                            continue;
                        }
                    }
                }
            }

            $this->entityManager->wrapInTransaction(function() use ($stopsToRemove, $movedVolume, $toPrice) {
                foreach ($stopsToRemove as $stopToRemove) {
                    $this->entityManager->remove($stopToRemove);
                }

                // @todo some uniqueid to context
                $this->stopService->create(
                    $this->getPosition()->side,
                    $toPrice->value(),
                    $stopsToRemove->totalVolume() + $movedVolume,
                    self::DEFAULT_TRIGGER_DELTA,
                );
            });

            $io->note(\sprintf('removed stops qnt: %d', $stopsToRemove->totalCount()));
        }

        if ($action === self::ACTION_REMOVE) {
            $this->entityManager->wrapInTransaction(function() use ($filteredStops) {
                foreach ($filteredStops as $stop) {
                    $this->entityManager->remove($stop);
                }
            });

            $io->note(\sprintf('removed stops qnt: %d', $stopsInSpecifiedRange->totalCount()));
        }

        if ($action === self::ACTION_EDIT) {
            $editCallback = $this->paramFetcher->getStringOption(self::EDIT_CALLBACK_OPTION);
            $this->entityManager->wrapInTransaction(function() use ($filteredStops, $editCallback) {
                foreach ($filteredStops as $stop) {
                    eval('$stop->' . $editCallback . ';');
                    $this->entityManager->persist($stop);
                }
            });

            $io->note(\sprintf('modified stops qnt: %d', $stopsInSpecifiedRange->totalCount()));
        }

        return Command::SUCCESS;
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

    private function applyFilters(StopsCollection $stops): StopsCollection
    {
        $filterCallbacksOption = $this->paramFetcher->getStringOption(self::FILTER_CALLBACKS_OPTION, false);
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
//        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        PositionServiceInterface $positionService,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
