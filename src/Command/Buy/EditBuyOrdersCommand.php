<?php

namespace App\Command\Buy;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Command\AbstractCommand;
use App\Command\Mixin\ConsoleInputAwareCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Domain\BuyOrder\BuyOrdersCollection;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function sprintf;

#[AsCommand(name: 'buy:edit')]
class EditBuyOrdersCommand extends AbstractCommand
{
    use ConsoleInputAwareCommand;
    use PositionAwareCommand;
    use PriceRangeAwareCommand;

    public const NAME = 'buy:edit';
    public const ACTION_OPTION = 'action';

    public const FILTER_CALLBACKS_OPTION = 'fC';

    /** `edit`-action options */
    public const EDIT_CALLBACK_OPTION = 'eC';

    public const ACTION_REMOVE = 'remove';
    public const ACTION_EDIT = 'edit';
    private const ACTIONS = [self::ACTION_REMOVE, self::ACTION_EDIT];

    public const WITHOUT_CONFIRMATION_OPTION = 'y';

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->configurePriceRangeArgs()
            ->addOption(self::ACTION_OPTION, '-a', InputOption::VALUE_REQUIRED, 'Mode (' . implode(', ', self::ACTIONS) . ')')
            ->addOption(self::FILTER_CALLBACKS_OPTION, null, InputOption::VALUE_REQUIRED, 'Filter callbacks')
            ->addOption(self::EDIT_CALLBACK_OPTION, null, InputOption::VALUE_REQUIRED, 'Edit Stop entity callback')
            ->addOption(self::WITHOUT_CONFIRMATION_OPTION, null, InputOption::VALUE_NEGATABLE, 'Without confirm')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $withoutConfirm = $this->paramFetcher->getBoolOption(self::WITHOUT_CONFIRMATION_OPTION);
        $action = $this->getAction();

        $orders = $this->buyOrderRepository->findActive(
            symbol: $this->getSymbol(),
            side: $this->getPositionSide(),
            qbModifier: function (QueryBuilder $qb) {
                $qb->orderBy($qb->getRootAliases()[0] . '.volume', 'ASC'); //$qb->orderBy($qb->getRootAliases()[0] . '.price', $this->getPositionSide()->isShort() ? 'ASC' : 'DESC');
                $alias = $qb->getRootAliases()[0];
                $qb->orderBy($alias . '.volume', 'ASC')->addOrderBy($alias . '.price', $this->getPositionSide()->isShort() ? 'ASC' : 'DESC');
            }
        );

        if (!$orders) {
            $this->io->info('BuyOrders not found!'); return Command::SUCCESS;
        }

        $buyOrdersCollection = new BuyOrdersCollection(...$orders);

        $fromPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback($this->fromOptionName, false);
        $toPrice = $this->getPriceFromPnlPercentOptionWithFloatFallback($this->toOptionName, false);
        if ($toPrice && $fromPrice) {
            $buyOrdersCollection = $buyOrdersCollection->grabFromRange(PriceRange::create($fromPrice, $toPrice, $this->getSymbol()));
        }

        $buyOrders = $this->applyFilters($buyOrdersCollection);
        $totalCount = $buyOrders->totalCount();
        $totalVolume = $buyOrders->totalVolume();

        if (!$totalCount) {
            $this->io->info('BuyOrders not found by provided conditions.'); return Command::SUCCESS;
        }

        if ($totalCount === count($orders) && !$withoutConfirm && !$this->io->confirm('All orders matched provided conditions. Continue?')) {
            return Command::FAILURE;
        }

        if (!$withoutConfirm && !$this->io->confirm(sprintf('You\'re about to %s %d BuyOrders (%.3f). Continue?', $action, $totalCount, $totalVolume))) {
            return Command::FAILURE;
        }

        if ($action === self::ACTION_REMOVE) {
            $this->entityManager->wrapInTransaction(function() use ($buyOrders) {
                foreach ($buyOrders as $buyOrder) {
                    $this->entityManager->remove($buyOrder);
                }
            });

            $this->io->note(\sprintf('removed BuyOrders qnt: %d (%.3f)', $totalCount, $totalVolume));
        }

        if ($action === self::ACTION_EDIT) {
            $editCallback = $this->paramFetcher->getStringOption(self::EDIT_CALLBACK_OPTION);
            $this->entityManager->wrapInTransaction(function() use ($buyOrders, $editCallback) {
                foreach ($buyOrders as $buyOrder) {
                    eval('$buyOrder->' . $editCallback . ';');
                    $this->entityManager->persist($buyOrder);
                }
            });

            $this->io->note(\sprintf('modified BuyOrders qnt: %d', $buyOrders->totalCount()));
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

    private function applyFilters(BuyOrdersCollection $buyOrders): BuyOrdersCollection
    {
        $filterCallbacksOption = $this->paramFetcher->getStringOption(self::FILTER_CALLBACKS_OPTION, false);
        if ($filterCallbacksOption && $filterCallbacks = array_map('trim', explode(',', $filterCallbacksOption))) {
            return $buyOrders->filterWithCallback(static function (BuyOrder $buyOrder) use ($filterCallbacks) {
                foreach ($filterCallbacks as $filterCallback) {
                    eval('$callbackResult = $buyOrder->' . $filterCallback . ';');
                    if ($callbackResult !== true) {
                        return false;
                    }
                }

                return true;
            });
        }

        return $buyOrders;
    }

    public static function formatRemoveCmdByUniqueId(
        SymbolInterface $symbol,
        Side $positionSide,
        string $uniqueId,
        bool $quiet = false
    ): string {
        return sprintf(
            './bin/console %s%s --symbol=%s %s -a%s --fC="getContext(\'uniqid\')===\'%s\'"',
            self::NAME,
            $quiet ? ' --' . self::WITHOUT_CONFIRMATION_OPTION : '',
            $symbol->value,
            $positionSide->value,
            self::ACTION_REMOVE,
            $uniqueId
        );
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BuyOrderRepository $buyOrderRepository,
        PositionServiceInterface $positionService,
        ?string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
