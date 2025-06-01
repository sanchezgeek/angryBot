<?php

declare(strict_types=1);

namespace App\Command\Hedge;

use App\Application\UniqueIdGeneratorInterface;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Command\AbstractCommand;
use App\Command\Buy\EditBuyOrdersCommand;
use App\Command\Mixin\CommandRunnerCommand;
use App\Command\Mixin\OppositeOrdersDistanceAwareCommand;
use App\Command\Mixin\OrderContext\AdditionalBuyOrderContextAwareCommand;
use App\Command\Mixin\PriceRangeAwareCommand;
use App\Command\Mixin\SymbolAwareCommand;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\Order;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Price\PriceRange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use Exception;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(name: 'p:hedge:open')]
class HedgePositionCommand extends AbstractCommand
{
    use SymbolAwareCommand;
    use PriceRangeAwareCommand;
    use CommandRunnerCommand;
    use AdditionalBuyOrderContextAwareCommand;
    use OppositeOrdersDistanceAwareCommand;

    public const MAIN_POSITION_SIZE_PART_OPTION = 'part';
    public const AS_GRID_OPTION = 'as-grid';

    public const ORDERS_QNT_OPTION = 'ordersQnt';
    private const DEFAULT_ORDERS_QNT = '50';

    protected function configure(): void
    {
        $this
            ->configureSymbolArgs()
            ->addOption(self::MAIN_POSITION_SIZE_PART_OPTION, 'p', InputOption::VALUE_OPTIONAL, 'Percent of main position size to hedge')
            ->addOption(self::AS_GRID_OPTION, null, InputOption::VALUE_NEGATABLE, 'Add as BuyOrders grid insteadof marketBuy? (alias for `buy:grid` command)')
            ->configurePriceRangeArgs(desc: 'PNL% (relative to ticker)')
            ->configureOppositeOrdersDistanceOption(alias: 's')
            ->configureBuyOrderAdditionalContexts()
            ->addOption(self::ORDERS_QNT_OPTION, '-c', InputOption::VALUE_OPTIONAL, 'Grid orders count', self::DEFAULT_ORDERS_QNT)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $this->getSymbol();

        $percentToHedge = $this->paramFetcher->requiredPercentOption(name: self::MAIN_POSITION_SIZE_PART_OPTION, asPercent: true);
        if (!($positions = $this->positionService->getPositions($symbol))) {
            throw new Exception(sprintf('No opened positions on %s', $symbol->value));
        }

        $hedge = $positions[0]->getHedge();
        $positionToHedge = $hedge?->mainPosition ?? $positions[0];

        $hedgedSize = $hedge?->supportPosition->size ?? 0;
        $hedgedPart = Percent::fromPart(FloatHelper::round($hedgedSize / $positionToHedge->size), false);
        if ($hedgedPart->value() > 0) {
            $this->io->info(sprintf('%s of %s already hedged', $hedgedPart, $positionToHedge->getCaption()));
        }

        $needToHedge = $percentToHedge->sub($hedgedPart);
        if ($needToHedge->value() <= 0) {
            return Command::FAILURE;
        }

        if (!$this->io->confirm(sprintf('You\'re about to hedge %s of %s', $needToHedge, $positionToHedge->getCaption()))) {
            return self::FAILURE;
        }

        $qtyToOpenOnSupportSide = $symbol->roundVolume($needToHedge->of($positionToHedge->size));
        $positionSide = $positionToHedge->side->getOpposite();

        if ($this->asGrid()) {
            $priceRange = $this->getPriceRange($positionSide);
            $ordersCount = $this->paramFetcher->getIntOption(self::ORDERS_QNT_OPTION);

            $context = ['uniqid' => $uniqueId = $this->uniqueIdGenerator->generateUniqueId('hedge-buy-grid')];
            if ($additionalContext = $this->getAdditionalBuyOrderContext()) {
                $context = array_merge($context, $additionalContext);
            }

            // @todo | calc real distance for each order in handler (or maybe in cmd, but for each BO)
            if ($stopDistance = $this->getOppositeOrdersDistanceOption($symbol)) {
                $context[BuyOrder::OPPOSITE_ORDERS_DISTANCE_CONTEXT] = $stopDistance;
            }

            $context[BuyOrder::FORCE_BUY_CONTEXT] = true;

            $orders = [];
            $volume = $qtyToOpenOnSupportSide / $ordersCount;
            foreach ($priceRange->byQntIterator($ordersCount, $positionSide) as $price) {
                $orders[] = new Order($symbol->makePrice($price->value()), $volume);
            }

            $orders = new OrdersLimitedWithMaxVolume(new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$orders)), $qtyToOpenOnSupportSide);

            foreach ($orders as $order) {
                $result = $this->createBuyOrderHandler->handle(
                    new CreateBuyOrderEntryDto($symbol, $positionSide, $order->volume(), $order->price()->value(), $context)
                );
            }

            $this->io->info(sprintf('%d orders has been created', $ordersCount));
            $this->io->info(sprintf('Orders have been created with volume ~= %s', $result->buyOrder->getVolume()));

            $output = [EditBuyOrdersCommand::formatRemoveCmdByUniqueId($symbol, $positionSide, $uniqueId, $this->io->isQuiet())];
            if (!$this->io->isQuiet()) {
                $this->io->success(sprintf('BuyOrders uniqueID: %s', $uniqueId));
                array_unshift($output, 'For delete them just run:');
            }

            $this->io->writeln($output, OutputInterface::VERBOSITY_QUIET);
        } else {
            $this->tradeService->marketBuy($symbol, $positionSide, $qtyToOpenOnSupportSide);
        }

        return Command::SUCCESS;
    }

    protected function getPriceFromPnlPercentOption(string $name, Side $positionSide): ?SymbolPrice
    {
        $ticker = $this->exchangeService->ticker($this->getSymbol());

        try {
            $pnlValue = $this->paramFetcher->requiredPercentOption($name);
            return PnlHelper::targetPriceByPnlPercent($ticker->markPrice, $pnlValue, $positionSide);
        } catch (InvalidArgumentException) {
            return $this->getSymbol()->makePrice($this->paramFetcher->requiredFloatOption($name));
        }
    }

    protected function getPriceRange(Side $positionSide): PriceRange
    {
        $fromPrice = $this->getPriceFromPnlPercentOption($this->fromOptionName, $positionSide);
        $toPrice = $this->getPriceFromPnlPercentOption($this->toOptionName, $positionSide);

        return PriceRange::create($fromPrice, $toPrice, $this->getSymbol());
    }

    private function asGrid(): bool
    {
        return $this->paramFetcher->getBoolOption(self::AS_GRID_OPTION);
    }

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly OrderServiceInterface $tradeService,
        private readonly UniqueIdGeneratorInterface $uniqueIdGenerator,
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }
}
