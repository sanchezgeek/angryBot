<?php

namespace App\Command\Stop;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
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

    /** @see MoveStopsInRangeTest */
    public const ACTION_MOVE = 'move';
    /** @see RemoveStopsInRangeTest */
    public const ACTION_REMOVE = 'remove';

    public const ACTION_EDIT = 'edit';

    private const ACTIONS = [
        self::ACTION_MOVE,
        self::ACTION_REMOVE,
        self::ACTION_EDIT,
    ];

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->configurePriceRangeArgs()
            ->addOption('action', '-a', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::ACTIONS) . ')')
            ->addOption('moveToPrice', '-m.t', InputOption::VALUE_REQUIRED, 'Move orders to price | pricePnl%')
            ->addOption('movePart', '-m.p', InputOption::VALUE_REQUIRED, 'Range volume part (%)')
            ->addOption('editCallback', '-e.c', InputOption::VALUE_REQUIRED, 'Edit Stop entity callback')
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
        $stopsInSpecifiedRange = (new StopsCollection(...$stops))->grabFromRange($priceRange);

        if ($action === self::ACTION_MOVE) {
            $toPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback('moveToPrice');
            $movePart = $this->paramFetcher->getPercentOption('movePart');
            if ($movePart <= 0 || $movePart > 100) {
                throw new InvalidArgumentException(
                    sprintf('Invalid \'%s\' PERCENT %s provided: value must be in 1%% .. 100%% range ("%s" given).', 'movePart', 'option', $movePart)
                );
            }
            $needMoveVolume = VolumeHelper::round($stopsInSpecifiedRange->totalVolume() * $movePart / 100);

            $movedVolume = 0;
            $stopsToRemove = new StopsCollection();
            while ($needMoveVolume > 0) {
                foreach ($stopsInSpecifiedRange as $stop) {
                    if ($stop->getVolume() <= $needMoveVolume) {
                        $stopsToRemove->add($stop);
                        $stopsInSpecifiedRange->remove($stop);
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

                $this->stopService->create(
                    $this->getPosition()->side,
                    $toPrice->value(),
                    $stopsToRemove->totalVolume() + $movedVolume,
                    self::DEFAULT_TRIGGER_DELTA,
                );
            });

            // @todo some uniqueid to context
            $io->note(\sprintf('removed stops qnt: %d', $stopsToRemove->totalCount()));
        }

        if ($action === self::ACTION_REMOVE) {
            $this->entityManager->wrapInTransaction(function() use ($stopsInSpecifiedRange) {
                foreach ($stopsInSpecifiedRange as $stop) {
                    $this->entityManager->remove($stop);
                }
            });

            $io->note(\sprintf('removed stops qnt: %d', $stopsInSpecifiedRange->totalCount()));
        }

        if ($action === self::ACTION_EDIT) {
            $callback = $this->paramFetcher->getStringOption('editCallback');
            $this->entityManager->wrapInTransaction(function() use ($stopsInSpecifiedRange, $callback) {
                foreach ($stopsInSpecifiedRange as $stop) {
                    eval($callback . ';');
                    $this->entityManager->persist($stop);
                }
            });
        }

        return Command::SUCCESS;
    }

    private function getAction(): string
    {
        $action = $this->paramFetcher->getStringOption('action');
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid $action provided (%s)', $action),
            );
        }

        return $action;
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
