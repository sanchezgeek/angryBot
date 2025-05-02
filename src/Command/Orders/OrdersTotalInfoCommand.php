<?php

namespace App\Command\Orders;

use App\Application\UseCase\Trading\MarketBuy\Checks\MarketBuyCheckService;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\OrderExecutionResult;
use App\Application\UseCase\Trading\Sandbox\Enum\SandboxErrorsHandlingType;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionLiquidatedBeforeOrderPriceException as PosLiquidatedException;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactory;
use App\Application\UseCase\Trading\Sandbox\Output\ExecStepResultDefaultTableRowBuilder;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Application\UseCase\Trading\Sandbox\TradingSandbox;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Pnl;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Orders\OrdersInfoTable\Dto\InitialStateRow;
use App\Command\Orders\OrdersInfoTable\Dto\OrdersInfoTableRowAtPriceInterface;
use App\Command\Orders\OrdersInfoTable\Dto\PositionLiquidationRow;
use App\Command\Orders\OrdersInfoTable\Dto\SandboxExecStepRow;
use App\Command\Orders\OrdersInfoTable\Dto\SummaryRow;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Pnl\Helper\PnlFormatter;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceFormatter;
use App\Domain\Stop\Helper\PnlHelper;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Dto\Style\Enum\Color;
use App\Output\Table\Dto\Style\RowStyle;
use App\Output\Table\Formatter\ConsoleTableBuilder;
use App\Settings\Application\Service\AppSettingsProvider;
use App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation\FurtherMainPositionLiquidationCheckParametersInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_merge;
use function array_reverse;
use function count;
use function sprintf;

/**
 * @todo
 *   1) what if there is no position?
 *   2) $this->io->info(sprintf('volume stopped: %.2f%%', ($totalStopsVolume / $position->size) * 100));
 *   3) handle case when position became support position from main
 *   4) add BuyOrders created after get SL to this table?
 *   6) [classic] also check result liquidation after transfer available funds from spot?
 *   7) скорее всего такого не будет, но вдруг. Да и как пример. TP на пути следования => варнинг в случае, если результирующего объёма также недостаточно
 *
 *  background
 *   1) [hedge] Добавить проверку того, что на пути следования цены хватает орддеров для того, чтобы в итоге size был достаточен для обеспечения определённой liqDelta
 *   2) добавить какой-то фоновый обработчик, который будет кидать оповещение, если что-то идёт не так?
 */
#[AsCommand(name: 'o:t')]
class OrdersTotalInfoCommand extends AbstractCommand
{
    use PositionAwareCommand;

    private const TABLE_STYLE_OPTION = 'style';
//    private const DEFAULT_TABLE_STYLE = 'box';
    private const DEFAULT_TABLE_STYLE = 'default';

    private const DIVIDE_TO_GROUPS = 'divide';
    private const DIVIDE_BY_ORDER_TYPES = 'divide-by-order-type';
    private const DIVIDE_STEP_PNL_PERCENT = 50;

    private const FORCE_EXPAND_EVERY_SINGLE_ORDER = 'force-expand';

    private const USE_LAST_PRICE_AS_ESTIMAATED_FOR_EXEC = 'use-last-price';

    private PriceFormatter $priceFormatter;
    private PnlFormatter $pnlFormatter;

    private SandboxState $initialSandboxState;

    private ?float $totalPnl = null;
    private bool $liquidationRowPrinted = false;

    private ?bool $divideToGroups = null;
    private ?bool $divideByOrderType = true;

    private Ticker $currentTicker;
    private float $lastPriceDiff;
    private bool $useLastPriceAsEstimatedForExec;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption(self::TABLE_STYLE_OPTION, null, InputOption::VALUE_REQUIRED, 'Table style', self::DEFAULT_TABLE_STYLE)
            ->addOption(self::DIVIDE_TO_GROUPS, null, InputOption::VALUE_NEGATABLE, 'Divide orders to groups?')
            ->addOption(self::DIVIDE_BY_ORDER_TYPES, null, InputOption::VALUE_OPTIONAL, 'Divide by order type?', true)
            ->addOption(self::FORCE_EXPAND_EVERY_SINGLE_ORDER, 'f', InputOption::VALUE_NEGATABLE, 'Force expand every single order?')
            ->addOption(self::USE_LAST_PRICE_AS_ESTIMAATED_FOR_EXEC, 'l', InputOption::VALUE_OPTIONAL, 'Use ticker.lastPrice as actual order price when exec orders?', true)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $symbol = $this->getSymbol();
        $this->priceFormatter = new PriceFormatter($symbol);
        $this->pnlFormatter = PnlFormatter::bySymbol($symbol)->setPrecision(2)->setShowCurrency(false);
        $this->currentTicker = $this->exchangeService->ticker($this->getSymbol());
        $this->lastPriceDiff = $this->currentTicker->indexPrice->value() - $this->currentTicker->lastPrice->value();
        $this->useLastPriceAsEstimatedForExec = $this->paramFetcher->getBoolOption(self::USE_LAST_PRICE_AS_ESTIMAATED_FOR_EXEC);
        if ($this->useLastPriceAsEstimatedForExec) {
            $pp10 = PnlHelper::convertPnlPercentOnPriceToAbsDelta(10, $this->currentTicker->indexPrice);
            if ($this->currentTicker->indexPrice->differenceWith($this->currentTicker->lastPrice)->absDelta() < $pp10) {
                $this->useLastPriceAsEstimatedForExec = false;
            }
        }
    }

    private function createSandbox(): TradingSandbox
    {
        /** @var TradingSandbox $tradingSandbox */
        $tradingSandbox = $this->tradingSandboxFactory->byCurrentState($this->getSymbol());
        $tradingSandbox->setErrorsHandlingType(SandboxErrorsHandlingType::CollectAndContinue);

        $positionServiceStub = new class implements PositionServiceInterface
        {
            public function getPosition(Symbol $symbol, Side $side): ?Position {throw new RuntimeException(sprintf('Stub method %s must not be called', __METHOD__));}
            public function getPositions(Symbol $symbol): array {throw new RuntimeException(sprintf('Stub method %s must not be called', __METHOD__));}
            public function addConditionalStop(Position $position, float $price, float $qty, TriggerBy $triggerBy): string { throw new RuntimeException(sprintf('Stub method %s must not be called', __METHOD__));}
            public function getOpenedPositionsSymbols(array $except = []): array {throw new RuntimeException(sprintf('Stub method %s must not be called', __METHOD__));}
            public function getOpenedPositionsRawSymbols(): array {throw new RuntimeException(sprintf('Stub method %s must not be called', __METHOD__));}
        };

        $tradingSandbox->setMarketBuyCheckService(
            new MarketBuyCheckService($positionServiceStub, $this->tradingSandboxFactory, new NullLogger(), $this->furtherMainPositionLiquidationCheckParameters)
        );

        return $tradingSandbox;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $positionSide = $this->getPositionSide();
        $symbol = $this->getSymbol();

        $pushedStops = $this->exchangeService->activeConditionalOrders($symbol);

        $stops = array_filter(
            $this->stopRepository->findAllByPositionSide($symbol, $positionSide),
            static fn(Stop $stop):bool => !$stop->isOrderPushedToExchange() || isset($pushedStops[$stop->getExchangeOrderId()])
        );

        $buyOrders = $this->buyOrderRepository->findActive($symbol, $positionSide);
        if (!$stops && !$buyOrders) {
            $this->io->block('Orders not found.'); return Command::SUCCESS;
        }

        foreach ($buyOrders as $buyOrder) {
            if (!$buyOrder->isOppositeBuyOrderAfterStopLoss()) {
                continue;
            }

            $stopExchangeOrderId = $buyOrder->getOnlyAfterExchangeOrderExecutedContext();
            if (!isset($pushedStops[$stopExchangeOrderId])) {
                $buyOrder->setIsOppositeStopExecuted();
            }
        }

        /** @var Stop[]|BuyOrder[] $orders */
        $orders = array_merge($stops, $buyOrders);

        if (count($orders) > 50) {
            $this->divideToGroups = match (true) {
                $this->paramFetcher->getBoolOption(self::DIVIDE_TO_GROUPS) => true,
                $this->paramFetcher->getBoolOption(self::FORCE_EXPAND_EVERY_SINGLE_ORDER) => false,
                default => $this->paramFetcher->getBoolOption(self::DIVIDE_TO_GROUPS) ?: $this->io->ask('Orders qnt is more than 50. Divide to groups?', true)
            };
            $this->divideByOrderType = $this->paramFetcher->getBoolOption(self::DIVIDE_BY_ORDER_TYPES) ?: $this->io->ask('Also divide by OrderType?', true);
        }

        $ordersAfterPositionLoss = [];
        $ordersAfterPositionProfit = [];

        foreach ($orders as $order) {
            $symbol->makePrice($order->getPrice())->differenceWith($this->currentTicker->indexPrice)->isLossFor($positionSide)
                ? $ordersAfterPositionLoss[] = $order
                : $ordersAfterPositionProfit[] = $order;
        }

        usort($ordersAfterPositionLoss,   static fn ($a, $b) => $positionSide->isLong() ? $b->getPrice() <=> $a->getPrice() : $a->getPrice() <=> $b->getPrice());
        usort($ordersAfterPositionProfit, static fn ($a, $b) => $positionSide->isLong() ? $a->getPrice() <=> $b->getPrice() : $b->getPrice() <=> $a->getPrice());

        $sandbox = $this->createSandbox();
        $this->initialSandboxState = $sandbox->getCurrentState();

        $rowsAfterPositionProfit = $this->processOrdersFromInitialPositionState($sandbox, $ordersAfterPositionProfit);
        if ($positionSide->isLong()) {
            $rowsAfterPositionProfit = array_reverse($rowsAfterPositionProfit, true);
        }

        # set initial state before go other direction
        $sandbox->setState($this->initialSandboxState);
        $rowsAfterPositionLoss = $this->processOrdersFromInitialPositionState($sandbox, $ordersAfterPositionLoss);
        if ($positionSide->isShort()) {
            $rowsAfterPositionLoss = array_reverse($rowsAfterPositionLoss, true);
        }

        $middleRows = [new InitialStateRow($this->currentTicker, $this->initialSandboxState)];
        $rows = $positionSide->isShort()
            ? array_merge($rowsAfterPositionLoss, $middleRows, $rowsAfterPositionProfit)
            : array_merge($rowsAfterPositionProfit, $middleRows, $rowsAfterPositionLoss);

        if ($this->totalPnl !== null) {
            $rows[] = new SummaryRow('total PNL:', new Pnl($this->totalPnl));
        }

        $this->print($rows);

        return Command::SUCCESS;
    }

    /**
     * @param Stop[]|BuyOrder[] $orders
     */
    private function processOrdersFromInitialPositionState(TradingSandbox $sandbox, array $orders): array
    {
        $position = $this->initialSandboxState->getPosition($this->getPositionSide());
        $entryPrice = $position->entryPrice();
        $divideStep = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::DIVIDE_STEP_PNL_PERCENT, $entryPrice);
        $groups = [];

        $divideByPositionEntryWasMade = false;
        # @todo Divide also by side of executed orders?
        if ($this->divideToGroups) {
            $key = 0;
            foreach ($orders as $order) {
                $lastType = $groups ? end($groups)['type'] : null;
                $class = get_class($order);
                $price = $order->getPrice();

                $orderType = match ($class) {BuyOrder::class => OrderType::Add, Stop::class => OrderType::Stop};
                if ($this->divideByOrderType) {
                    if ($orderType !== $lastType) {
                        $key++;
                    }
                }

                $priceOfFirstOrderInGroup = ($groups[$key]['items'][0] ?? null)?->getPrice();
                if ($priceOfFirstOrderInGroup && abs($priceOfFirstOrderInGroup - $price) > $divideStep) {
                    $key++;
                }

                if (!$divideByPositionEntryWasMade && ($position->isShort() && $price > $entryPrice->value() || $position->isLong()  && $price < $entryPrice->value())) {
                    $key++; $divideByPositionEntryWasMade = true;
                }

                $groups[$key] ??= ['type' => $orderType, 'items' => []];
                $groups[$key]['items'][] = $order;
            }
        } else {
            foreach ($orders as $order) {
                $groups[] = ['items' => [$order]];
            }
        }

        $pnl = 0;
        $rows = [];
        $dividedByPosLiq = false;
        while ($group = array_shift($groups)) {
            $items = $group['items'];

            if ($this->useLastPriceAsEstimatedForExec) {
                foreach ($items as $key => $item) {
                    $items[$key] = match (true) {
                        $item instanceof BuyOrder => SandboxBuyOrder::fromBuyOrder($item, $item->getPrice() - $this->lastPriceDiff),
                        $item instanceof Stop => SandboxStopOrder::fromStop($item, $item->getPrice() - $this->lastPriceDiff),
                    };
                }
            }

            $stepResult = $sandbox->processOrders(...$items);

            if (!$this->liquidationRowPrinted) {
                foreach ($stepResult->getItems() as $key => $item) {
                    if ($item->failReason?->exception instanceof PosLiquidatedException) {
                        if ($this->divideToGroups && !$dividedByPosLiq) {
                            $ordersBeforeLiq = self::extractSourceOrdersFromOrderExecResult(...array_slice($stepResult->getItems(), 0, $key));
                            $ordersAfterLiq = self::extractSourceOrdersFromOrderExecResult(...array_slice($stepResult->getItems(), $key));

                            $ordersAfterLiq && array_unshift($groups, ['items' => $ordersAfterLiq]);
                            $ordersBeforeLiq && array_unshift($groups, ['items' => $ordersBeforeLiq]);

                            $sandbox->setState($stepResult->getStateBefore());
                            $dividedByPosLiq = true;
                            continue 2;
                        }
                    }
                }

                foreach ($stepResult->getItems() as $item) {
                    if ($item->failReason?->exception instanceof PosLiquidatedException) {
                        $rows[] = new PositionLiquidationRow($item->inputState->getPosition($this->getPositionSide()));
                        $this->liquidationRowPrinted = true;
                        break;
                    }
                }
            }

            $rows[] = new SandboxExecStepRow($stepResult);

            if ($stepResult->getTotalPnl()) {
                $pnl += $stepResult->getTotalPnl();
            }
        }

        if ($pnl) {
            $rows[] = new SummaryRow('PNL:', new Pnl($pnl));
            $this->totalPnl += $pnl;
        }

        return $rows;
    }

    /**
     * @return Stop[]|BuyOrder[]
     */
    private static function extractSourceOrdersFromOrderExecResult(OrderExecutionResult ...$items): array
    {
        return array_map(static fn(OrderExecutionResult $orderExecutionResult) => $orderExecutionResult->order->sourceOrder, $items);
    }

    private function print(array $rows): void
    {
        $initialPosition = $this->initialSandboxState->getPosition($this->getPositionSide());
        $isPositionLocationPrinted = false;

        $sandboxExecutionResultRowBuilder = new ExecStepResultDefaultTableRowBuilder(
            currentTicker: $this->currentTicker,
            targetPositionSide: $this->getPositionSide(),
            pnlFormatter: $this->pnlFormatter,
            priceFormatter: $this->priceFormatter,
            showEstimatedRealExecPrice: $this->useLastPriceAsEstimatedForExec
        );

        $headers = [
            'id' => 'id',
            'price' => 'price',
            'volume' => 'volume',
            'pnl' => sprintf('PNL (%s)',$this->pnlFormatter->getCurrency()),
            'wrapperBetweenOldAndNewState' => '',
            'pos.size' => 'pos.size',
            'pos.entry' => 'pos.entry',
            'pos.liquidation' => 'pos.liquidation',
        ];
        $columnsCount = count($headers);
        $tableRows = [];
        $tableRows[] = DataRow::empty();

        foreach ($rows as $row) {
            if (!$isPositionLocationPrinted && $row instanceof OrdersInfoTableRowAtPriceInterface) {
                if ($initialPosition->entryPrice()->greaterThan($row->getRowUpperPrice())) {
                    $isPositionLocationPrinted = true;
                    $tableRows[] = DataRow::separated([Cell::colspan(2, $initialPosition->entryPrice), Cell::restColumnsMerged('(position located here)')])
                        ->addStyle(RowStyle::yellowFont());
                }
            }

            if ($row instanceof SummaryRow) {
                $row = DataRow::separated([new Cell($row->caption, new CellStyle(colspan: $columnsCount - 1, align: CellAlign::RIGHT)), new Cell($row->content)]);
            } elseif ($row instanceof SandboxExecStepRow) {
                $row = $sandboxExecutionResultRowBuilder->build($row->stepResult);
            } elseif ($row instanceof InitialStateRow) {
                $ticker = $row->ticker;
                $position = $row->initialSandboxState->getPosition($this->getPositionSide());
                $cells = array_merge([
                    Cell::colspan(4, sprintf('%s ticker: %s[l], %s[m], %s[i]', $ticker->symbol->value, $ticker->lastPrice, $ticker->markPrice, $ticker->indexPrice)),
                    Cell::resetToDefaults(sprintf('pos:%s', $position ? sprintf(' %s', $position->side->title()) : '')),
                    # probably some default table behaviour instead of $columnsCount - $tickerColspan
                ], !$position ? [Cell::restColumnsMerged('No position found')] : [
                    $position->size,
                    $position->entryPrice,
                    (!$position->isSupportPosition() && $position->liquidationPrice) ? $position->liquidationPrice : '-',
                    Cell::resetToDefaults(
                        (!$position->isSupportPosition() && !$position->isShortWithoutLiquidation()) ? sprintf('liquidationDistance: %s with position entry, %s with ticker', $position->liquidationDistance(), $position->priceDistanceWithLiquidation($ticker)) : '',
                    )
                ]);

                $row = DataRow::separated($cells)->addStyle(RowStyle::yellowFont());
            } elseif ($row instanceof PositionLiquidationRow) {
                $liquidatedAt = $this->priceFormatter->format($row->liquidatedPosition->liquidationPrice);
                $row = DataRow::separated(
                    [Cell::colspan(2, $liquidatedAt)->setAlign(CellAlign::RIGHT), Cell::restColumnsMerged('position liquidation')->setAlign(CellAlign::CENTER)]
                )->addStyle(new RowStyle(fontColor: Color::RED));
            }

            $tableRows[] = $row;
        }

        ConsoleTableBuilder::withOutput($this->output)
            ->withHeader($headers)
            ->withRows(...$tableRows)
            ->build()
            ->setStyle($this->paramFetcher->getStringOption(self::TABLE_STYLE_OPTION))
            ->render();
    }

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderRepository $buyOrderRepository,
        PositionServiceInterface $positionService,
        private readonly TradingSandboxFactory $tradingSandboxFactory,
        private readonly FurtherMainPositionLiquidationCheckParametersInterface $furtherMainPositionLiquidationCheckParameters,
        string $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
