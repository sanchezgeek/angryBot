<?php

namespace App\Command\Orders;

use App\Application\UseCase\Trading\MarketBuy\Checks\MarketBuyCheckService;
use App\Application\UseCase\Trading\Sandbox\Enum\SandboxErrorsHandlingType;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionLiquidatedBeforeOrderPriceException;
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
use App\Bot\Domain\ValueObject\Symbol;
use App\Command\AbstractCommand;
use App\Command\Mixin\PositionAwareCommand;
use App\Command\Orders\OrdersInfoTable\Dto\InitialStateRow;
use App\Command\Orders\OrdersInfoTable\Dto\OrdersInfoTableRowAtPriceInterface;
use App\Command\Orders\OrdersInfoTable\Dto\SandboxExecStepRow;
use App\Command\Orders\OrdersInfoTable\Dto\SummaryRow;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Pnl\Helper\PnlFormatter;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceFormatter;
use App\Domain\Price\Price;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\CellAlign;
use App\Output\Table\Dto\Style\Enum\Color;
use App\Output\Table\Dto\Style\RowStyle;
use App\Output\Table\Formatter\ConsoleTableBuilder;
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

    private PriceFormatter $priceFormatter;
    private PnlFormatter $pnlFormatter;

    private SandboxState $initialSandboxState;
    private ?float $totalPnl = null;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption(self::TABLE_STYLE_OPTION, null, InputOption::VALUE_REQUIRED, 'Table style', self::DEFAULT_TABLE_STYLE)
            ->addOption(self::DIVIDE_TO_GROUPS, null, InputOption::VALUE_NEGATABLE, 'Divide orders to groups?')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $symbol = $this->getSymbol();
        $this->priceFormatter = new PriceFormatter($symbol);
        $this->pnlFormatter = PnlFormatter::bySymbol($symbol)->setPrecision(2)->setShowCurrency(false);
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
        };

        $marketBuyCheckService = new MarketBuyCheckService($positionServiceStub, $this->tradingSandboxFactory, new NullLogger());

        $tradingSandbox->setMarketBuyCheckService($marketBuyCheckService);

        return $tradingSandbox;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ticker = $this->exchangeService->ticker($this->getSymbol());
        $positionSide = $this->getPositionSide();

        $stops = $this->stopRepository->findActive($positionSide);
        $buyOrders = $this->buyOrderRepository->findActive($positionSide);
        if (!$stops && !$buyOrders) {
            $this->io->block('Orders not found.'); return Command::SUCCESS;
        }

        /** @var Stop[]|BuyOrder[] $orders */
        $orders = array_merge($stops, $buyOrders);

        $ordersAfterPositionLoss = [];
        $ordersAfterPositionProfit = [];

        foreach ($orders as $order) {
            Price::float($order->getPrice())->differenceWith($ticker->indexPrice)->isLossFor($positionSide) ? $ordersAfterPositionLoss[] = $order : $ordersAfterPositionProfit[] = $order;
        }

        usort($ordersAfterPositionLoss,   $positionSide->isLong() ? static fn ($a, $b) => $b->getPrice() <=> $a->getPrice() : static fn ($a, $b) => $a->getPrice() <=> $b->getPrice());
        usort($ordersAfterPositionProfit, $positionSide->isLong() ? static fn ($a, $b) => $a->getPrice() <=> $b->getPrice() : static fn ($a, $b) => $b->getPrice() <=> $a->getPrice());

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

        $middleRows = [new InitialStateRow($ticker, $this->initialSandboxState)];
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
        $pnl = 0;
        $rows = [];
        foreach ($orders as $order) {
            $stepResult = $sandbox->processOrders($order);

            if (!$this->isPositionLiquidationPrinted) {
                foreach ($stepResult->getItems() as $item) {
                    if ($item->failReason?->exception instanceof SandboxPositionLiquidatedBeforeOrderPriceException) {
                        $this->isPositionLiquidationPrinted = true;
                        $liquidatedAt = $this->priceFormatter->format($item->inputState->getPosition($this->getPositionSide())->liquidationPrice);
                        $rows[] = DataRow::separated(
                            [Cell::colspan(2, $liquidatedAt)->setAlign(CellAlign::RIGHT), Cell::restColumnsMerged('position liquidation')->setAlign(CellAlign::CENTER)]
                        )->addStyle(new RowStyle(fontColor: Color::RED));
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

    private function print(array $rows): void
    {
        $initialPosition = $this->initialSandboxState->getPosition($this->getPositionSide());
        $isPositionLocationPrinted = false;

        $sandboxExecutionResultRowBuilder = new ExecStepResultDefaultTableRowBuilder($this->getPositionSide(), $this->pnlFormatter, $this->priceFormatter);

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
                    Cell::colspan(4, sprintf('%s ticker: %s  %s  %s', $ticker->symbol->value, $ticker->lastPrice, $ticker->markPrice, $ticker->indexPrice)),
                    Cell::resetToDefaults('pos:'),
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

    private bool $isPositionLiquidationPrinted = false;

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly StopRepository           $stopRepository,
        private readonly BuyOrderRepository       $buyOrderRepository,
        PositionServiceInterface                  $positionService,
        private readonly TradingSandboxFactory    $tradingSandboxFactory,
        string                                    $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
