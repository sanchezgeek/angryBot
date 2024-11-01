<?php

namespace App\Command\Orders;

use App\Application\UseCase\Trading\MarketBuy\Checks\MarketBuyCheckService;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\Sandbox\Assertion\PositionLiquidationIsAfterOrderPriceAssertion;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionLiquidatedBeforeOrderPriceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionNotFoundException;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactory;
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
use App\Command\AbstractCommand;
use App\Command\Helper\ConsoleTableHelper as TH;
use App\Command\Mixin\PositionAwareCommand;
use App\Domain\Pnl\Helper\PnlFormatter;
use App\Domain\Price\Helper\PriceFormatter;
use App\Domain\Price\Price;
use App\Domain\Price\PriceMovement;
use App\Domain\Stop\StopsCollection;
use Error;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Throwable;

use function array_filter;
use function array_map;
use function array_merge;
use function array_reverse;
use function count;
use function implode;
use function sprintf;

/**
 * @todo if position not opened yet
 */
#[AsCommand(name: 'o:o')]
class OrdersTotalInfoOldCommand extends AbstractCommand
{
    use PositionAwareCommand;

    private const SHOW_STATE_CHANGES = 'showState';
    private const SHOW_REASON = 'showReason';
    private const SHOW_CUMULATIVE_STATE_CHANGES = 'showCum';

    private const ORDER_ROW_DEF = 'order-row';
    private const INFO_ROW_DEF = 'info-row';

    private Position $position;
    private PriceFormatter $priceFormatter;
    private PnlFormatter $pnlFormatter;

    protected function configure(): void
    {
        $this
            ->configurePositionArgs()
            ->addOption(self::SHOW_STATE_CHANGES, null, InputOption::VALUE_NEGATABLE, 'Show state changes?')
            ->addOption(self::SHOW_CUMULATIVE_STATE_CHANGES, null, InputOption::VALUE_NEGATABLE, 'Show cumulative state changes?')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        //
        $this->position = $this->getPosition();

        $symbol = $this->getSymbol();
        $this->priceFormatter = new PriceFormatter($symbol);
        $this->pnlFormatter = PnlFormatter::bySymbol($symbol)->setPrecision(2)->setShowCurrency(false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // @todo
        // for both sides
        // what if there is no position?
        // $format .= ' => position not found => position closed?';
        // $totalStopsVolume += $rangeStops->totalVolume();
        // $args = [$rangeDesc, $rangeStops->totalCount(), $rangeStops->volumePart($position->size)];
        // $this->io->info(sprintf('volume stopped: %.2f%%', ($totalStopsVolume / $position->size) * 100));

        $ticker = $this->exchangeService->ticker($this->getSymbol());
        $position = $this->getPosition();
        $positionSide = $this->getPositionSide();

        $stops = $this->stopRepository->findActive($positionSide);
        $buyOrders = $this->buyOrderRepository->findActive($positionSide);
        if (!$stops && !$buyOrders) {
            $this->io->block('Orders not found.'); return Command::SUCCESS;
        }

        /** @var Stop[]|BuyOrder[] $orders */
        $orders = array_merge($stops, $buyOrders);

        /** @var array{toProfit: Stop[]|BuyOrder[], toLoss: Stop[]|BuyOrder[]} $parts */
        $parts = ['toProfit' => [], 'toLoss' => []];
        $ordersAfterPositionLoss = $parts['toLoss'];
        $ordersAfterPositionProfit = $parts['toProfit'];

        foreach ($orders as $order) {
            Price::float($order->getPrice())->differenceWith($ticker->indexPrice)->isLossFor($positionSide) ? $ordersAfterPositionLoss[] = $order : $ordersAfterPositionProfit[] = $order;
        }

        usort($ordersAfterPositionLoss,   $positionSide->isLong() ? static fn ($a, $b) => $b->getPrice() <=> $a->getPrice() : static fn ($a, $b) => $a->getPrice() <=> $b->getPrice());
        usort($ordersAfterPositionProfit, $positionSide->isLong() ? static fn ($a, $b) => $a->getPrice() <=> $b->getPrice() : static fn ($a, $b) => $b->getPrice() <=> $a->getPrice());

        //
        $tradingSandbox = $this->tradingSandboxFactory->byCurrentState($this->getSymbol());
        $initialSandboxState = clone $tradingSandbox->getCurrentState();

        $table = new Table($output);
        $table->setHeaders(['id', 'price', 'volume', sprintf('PNL (%s)', $this->pnlFormatter->getCurrency()), '', 'pos.size', 'pos.entry', 'pos.liquidation', 'comment']);

        # toProfit
        $rowsAfterPositionProfit = $this->processOrdersFromInitialPositionState($tradingSandbox, $ordersAfterPositionProfit);
        $stopsAfterPositionProfit = new StopsCollection(...array_filter($ordersAfterPositionProfit, static fn($order) => $order instanceof Stop));
        //
        $totalPositionProfit = $stopsAfterPositionProfit->totalUsdPnL($position);

        $rowsAfterPositionProfit = array_merge($rowsAfterPositionProfit, [
            self::infoRow(new TableSeparator()),
            self::infoRow([TH::cell(content: 'total PROFIT:', col: 8, align: 'right'), new Pnl($totalPositionProfit)]),
        ]);

        if ($positionSide->isLong()) {
            $rowsAfterPositionProfit = array_reverse($rowsAfterPositionProfit);
        }

        # set initial state before go other direction
        $tradingSandbox->setState($initialSandboxState);

        # toLoss
        $rowsAfterPositionLoss = $this->processOrdersFromInitialPositionState($tradingSandbox, $ordersAfterPositionLoss);

        $stopsAfterPositionLoss = new StopsCollection(...array_filter($ordersAfterPositionLoss, static fn($order) => $order instanceof Stop));
        $totalPositionLoss = $stopsAfterPositionLoss->totalUsdPnL($position);
        $lastRow = end($rowsAfterPositionLoss)['content'] ?? null;
        $rowsAfterPositionLoss = array_merge($rowsAfterPositionLoss,
            !$lastRow instanceof TableSeparator ? [self::infoRow(new TableSeparator())] : [],
            [self::infoRow([TH::cell(content: 'total LOSS:', col: 8, align: 'right'), new Pnl($totalPositionLoss)])]
        );
        if ($positionSide->isShort()) {
            $rowsAfterPositionLoss = array_reverse($rowsAfterPositionLoss);
        }

        $middleRows = [
            self::infoRow(new TableSeparator()),
            self::infoRow([
                TH::cell(sprintf('%s ticker: %s', $ticker->symbol->value, $ticker->indexPrice->value()), 4, [], 'yellow'),
                'pos:',
                TH::cell(content: $position->size, fontColor: 'yellow'),
                TH::cell(content: $position->entryPrice, fontColor: 'yellow'),
                !$position->isSupportPosition() ? TH::cell(content: $position->liquidationPrice, fontColor: 'yellow') : '',
                !$position->isSupportPosition() ? sprintf('liquidationDistance: %s with position entry, %s with ticker', $position->liquidationDistance(), $position->priceDistanceWithLiquidation($ticker)) : '',
            ], $ticker->indexPrice->value()),
            self::infoRow(new TableSeparator()),
        ];

        if ($positionSide->isShort()) {
            $rows = array_merge($rowsAfterPositionLoss, $middleRows, $rowsAfterPositionProfit);
        } else {
            $rows = array_merge($rowsAfterPositionProfit, $middleRows, $rowsAfterPositionLoss);
        }

        $rows[count($rows) - 1] = array_merge($rows[count($rows) - 1], ['atPrice' => 0]);

        $rows = array_merge($rows, [self::infoRow(new TableSeparator()), self::infoRow([TH::cell(content: 'total PNL:', col: 8, align:'right'), new Pnl($totalPositionLoss + $totalPositionProfit)])]);

        $resultRows = [];
        $isPositionLocationPrinted = false;
        foreach ($rows as $row) {
            if (!$isPositionLocationPrinted && isset($row['atPrice'])) {
                if ($this->position->entryPrice > $row['atPrice']) {
                    if (!end($resultRows) instanceof TableSeparator) {
                        $resultRows[] = new TableSeparator();
                    }
                    $resultRows[] = [TH::cell(content: $this->position->entryPrice, col:2, fontColor: 'yellow', align: 'right'), TH::cell(content: '(position located here)', col:6, fontColor: 'yellow')];
                    $resultRows[] = new TableSeparator();
                    $isPositionLocationPrinted = true;
                }
            }

            $resultRows[] = $row['content'];
        }

        $table->addRows($resultRows)->render();

        return Command::SUCCESS;
    }

    private function processOrdersFromInitialPositionState(TradingSandbox $sandbox, array $orders): array
    {
        $groups = [];

        $key = -1;
        foreach ($orders as $order) {
            $lastType = $groups ? $groups[count($groups) - 1]['type'] : null;
            $class = get_class($order);
            $orderType = match ($class) {
                BuyOrder::class => OrderType::Add,
                Stop::class => OrderType::Stop,
            };

            if ($orderType !== $lastType) {
                $key++;
                $groups[$key] ??= ['type' => $orderType, 'items' => []];
            }

            $groups[$key]['items'][] = $order;
        }


        $rows = [];
        foreach ($groups as /*$rangeDesc => */ $group) {
            $groupType = $group['type'];

            match ($groupType) {
                OrderType::Add => $rows = array_merge($rows, $this->processBuyOrdersGroup($sandbox, $group['items'])),
                OrderType::Stop => $rows = array_merge($rows, $this->processStopOrdersGroup($sandbox, $group['items'])),
            };
        }

        return $rows;
    }

    /**
     * @param BuyOrder[] $orders
     */
    private function processBuyOrdersGroup(TradingSandbox $sandbox, array $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $positionAfterExec = null;

            $orderPrice = $order->getPrice();
            $commonCells = [sprintf('b.%d', $order->getId()), $orderPrice, sprintf('+ %s', $order->getVolume())];

            $result = null;
            $commentCellContent = [];

            $comments = [];
            if ($order->isForceBuyOrder())                  $comments[] = '!force buy!';
            if ($order->isOppositeBuyOrderAfterStopLoss())  $comments[] = 'opposite BuyOrder after SL';
            if (!$order->isWithOppositeOrder())             $comments[] = 'without Stop';

            if ($comments) {
                $commentCellContent[] = implode(', ', $comments);
            }

            $marketBuyEntryDto = MarketBuyEntryDto::fromBuyOrder($order);
            $previousSandboxState = $sandbox->getCurrentState();
            $positionBeforeExec = $previousSandboxState->getPosition($this->getPositionSide());

            try {
                $result = [];
                if ($positionBeforeExec && !$positionBeforeExec->isSupportPosition()) {
                    PositionLiquidationIsAfterOrderPriceAssertion::create($positionBeforeExec, $order)->check();
                    /**
                     * @todo | check even for support (e.g. PushBuyOrdersHandler will skip order if support size is already enough for support main position)
                     * @see \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler::isNeedIgnoreBuy
                     */
                    $this->marketBuyCheckService->doChecks($marketBuyEntryDto, $this->createTicker($orderPrice), $sandbox->getCurrentState(), $positionBeforeExec);
                }

                // @todo handle case when position became support position from main

                $oppositeSide = $this->getPositionSide()->getOpposite();
                if ($positionBeforeExec?->isSupportPosition()) {
                    $oppositePositionBeforeExec = $sandbox->getCurrentState()->getPosition($oppositeSide);
                }

                $sandbox->processOrders($order);
                $sandboxStateAfterExec = $sandbox->getCurrentState();
//                $currentMainPosition = $sandboxStateAfterExec->getMainPosition();

                $positionAfterExec = $sandboxStateAfterExec->getPosition($this->getPositionSide());
                if ($positionBeforeExec?->isSupportPosition()) {
                    $oppositePositionAfterExec = $sandbox->getCurrentState()->getPosition($oppositeSide);
                }

                $result = array_merge($commonCells, ['', ' =>', $positionAfterExec->size, $this->priceFormatter->format($positionAfterExec->entryPrice)]);

                $liquidationCellContent = '';
                if ($positionAfterExec->isSupportPosition() && isset($oppositePositionBeforeExec) && isset($oppositePositionAfterExec)) {
                    $liquidationCellContent .= sprintf(' %s [to %s liq.distance] => %s', $this->getLiquidationPriceDiffWithPrev($oppositePositionBeforeExec, $oppositePositionAfterExec), $oppositeSide->title(), $this->priceFormatter->format($oppositePositionAfterExec->liquidationPrice));
                } else {
                    $liquidationCellContent = $this->priceFormatter->format($positionAfterExec->liquidationPrice);
//                  if ($this->isShowStateChangesEnabled()) ...

                    if ($positionBeforeExec) {
                        $liquidationCellContent .= sprintf(' ( %s )', $this->getLiquidationPriceDiffWithPrev($positionBeforeExec, $positionAfterExec));
                    }

                }

                $result[] = $liquidationCellContent;
                $result[2] = self::buyOrderCell($result[2]);
            } catch (SandboxInsufficientAvailableBalanceException $e) {
                $commentCellContent[] = sprintf('cannot buy (%s)', $e->getMessage());
            } catch (BuyIsNotSafeException $e) {
                $commentCellContent[] = sprintf('won\'t be executed (%s)', $e->getMessage());
            } catch (SandboxPositionLiquidatedBeforeOrderPriceException) {
                $this->printPositionLiquidationRow($positionBeforeExec, $rows);
                $rows[] = self::orderRow($orderPrice, array_merge($commonCells, [TH::cell('', 5)]));
                continue;
            }

            if (!$result) {
                $result = array_merge($commonCells, ['', '', '', '', '']); # in case of error
            }

            if ($commentCellContent) {
                $result[] = implode(' | ', $commentCellContent);
            } else {
                $result[] = '';
            }

            if (isset($positionAfterExec) && $this->isShowCumulativeStateChangesEnabled()) {
                if (!$positionAfterExec->isSupportPosition()) {
                    $result[] = sprintf('total % 7.2f added to initial liq.distance', $this->getLiquidationPriceDiffWithPrev($this->position, $positionAfterExec));
                } else {
                    $result[] = sprintf('total % 7.2f added to %s liq.distance', $this->getLiquidationPriceDiffWithPrev($this->position->oppositePosition, $oppositePositionAfterExec), $oppositePositionBeforeExec->getCaption());
                }
            }


            $rows[] = self::orderRow($orderPrice, $result);
        }

        return $rows;
    }

    /**
     * @param Stop[] $orders
     */
    private function processStopOrdersGroup(TradingSandbox $sandbox, array $orders): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $orderPrice = $order->getPrice();

            # ID, price, volume
            $commonCells = array_map(static fn(string $content) => self::stopOrderCell($content), [sprintf('s.%d', $order->getId()), $orderPrice, sprintf('- %s', $order->getVolume())]);

            $positionBeforeExec = $sandbox->getCurrentState()->getPosition($this->getPositionSide());
            // case when position initially is not support
            $oppositeSide = $this->getPositionSide()->getOpposite();
            if ($positionBeforeExec?->isSupportPosition()) {
                $oppositePositionBeforeExec = $sandbox->getCurrentState()->getPosition($oppositeSide);
            }
            try {
                $sandbox->processOrders($order);
                $newState = $sandbox->getCurrentState();
            } catch (SandboxPositionLiquidatedBeforeOrderPriceException|SandboxPositionNotFoundException $e) {
                if ($e instanceof SandboxPositionLiquidatedBeforeOrderPriceException) {
                    $this->printPositionLiquidationRow($positionBeforeExec, $rows);
                }
                $result = array_merge($commonCells, [TH::cell('', 5)]);
                $rows[] = self::orderRow($orderPrice, $result);
                continue;
            }

            $commonCells[] = $this->pnlFormatter->format($order->getPnlUsd($this->position));

            $positionAfterExec = $newState->getPosition($this->getPositionSide());
            if ($positionBeforeExec?->isSupportPosition()) {
                $oppositePositionAfterExec = $sandbox->getCurrentState()->getPosition($oppositeSide);
            }

            if (!$positionAfterExec && $positionBeforeExec) {
                $result = array_merge($commonCells, [' =>', TH::cell(content: 'position closed', col: 3, align: 'center')]);
                $rows[] = self::infoRow(new TableSeparator());
                $rows[] = self::orderRow($orderPrice, $result);
                $rows[] = self::infoRow(new TableSeparator());
                // liq.distance added to opposite
                continue;
            } elseif (!$positionBeforeExec && !$positionAfterExec) {
                $result = array_merge($commonCells, [TH::cell(content: '', col: 5)]);
                $rows[] = self::orderRow($orderPrice, $result);
                continue;
            }


            $result = array_merge($commonCells, [' =>', $positionAfterExec->size, $this->priceFormatter->format($positionAfterExec->entryPrice)]);

            $liquidationCellContent = '';
            if ($this->position->isSupportPosition()) {
                $liquidationCellContent .= sprintf(' %s [to %s liq.distance] => %s', $this->getLiquidationPriceDiffWithPrev($oppositePositionBeforeExec, $oppositePositionAfterExec), $oppositeSide->title(), $this->priceFormatter->format($oppositePositionAfterExec->liquidationPrice));
            } else {
                $liquidationCellContent = $this->priceFormatter->format($positionAfterExec->liquidationPrice);
//            if ($this->isShowStateChangesEnabled()) {
                $liquidationCellContent .= sprintf(' ( %s )', $this->getLiquidationPriceDiffWithPrev($positionBeforeExec, $positionAfterExec));
//                $liquidationDiff->deltaForPositionLoss($this->getPositionSide(), $rangeStops->getAvgPrice())->setOutputDecimalsPrecision(8);
//                if ($resultPosition->isSupportPosition() && !$positionBeforeRange->isSupportPosition()) $format .= ' | became support?';
//            }
            }


            $result[] = $liquidationCellContent;

            $comments = [];
            if ($order->isTakeProfitOrder()) {
                $comments[] = 'TakeProfit order';
            } elseif ($order->isCloseByMarketContextSet()) {
                $comments[] = '!by market!';
            } else {
                $comments[] = 'Conditional order';
            }

            if (!$order->isWithOppositeOrder()) {
                $comments[] = 'without opposite BO';
            }

            // opposite

            if ($comments) {
                $result[] = implode(' | ', $comments);
            } else {
                $result[] = '';
            }


            if ($this->isShowCumulativeStateChangesEnabled()) {
                if (!$positionAfterExec->isSupportPosition()) {
                    $result[] = sprintf('total % 7.2f added to initial liq.distance', $this->getLiquidationPriceDiffWithPrev($this->position, $positionAfterExec));
                } else {
                    $result[] = sprintf('total % 7.2f added to %s liq.distance', $this->getLiquidationPriceDiffWithPrev($this->position->oppositePosition, $oppositePositionAfterExec), $oppositePositionBeforeExec->getCaption());
                }
            }

            $rows[] = self::orderRow($orderPrice, $result);
        }

        return $rows;
    }

    private static function buyOrderCell(string $content): TableCell
    {
        return TH::cell(content: $content, fontColor: 'green');
    }

    private static function stopOrderCell(string $content): TableCell
    {
        return TH::cell(content: $content, backgroundColor: 'bright-red');
    }

    private static function infoRow(mixed $content, float $atPrice = null): array
    {
        $row = ['def' => self::INFO_ROW_DEF, 'content' => $content];
        if ($atPrice !== null) {
            $row['atPrice'] = $atPrice;
        }

        return $row;
    }

    private static function orderRow(float $atPrice, mixed $content): array
    {
        return ['def' => self::ORDER_ROW_DEF, 'content' => $content, 'atPrice' => $atPrice];
    }

    private function getLiquidationPriceDiffWithPrev(Position $positionBefore, Position $positionAfter): string
    {
        $liquidationPriceMoveFromPrev = PriceMovement::fromToTarget($positionBefore->liquidationPrice, $positionAfter->liquidationPrice);
        $liquidationPriceDiffWithPrev = $liquidationPriceMoveFromPrev->deltaForPositionLoss($positionBefore->side);
        if ($liquidationPriceDiffWithPrev > 0) {
            $liquidationPriceDiffWithPrev = '+' . $this->priceFormatter->format($liquidationPriceDiffWithPrev);
        }

        return $liquidationPriceDiffWithPrev;
    }

    private function createTicker(float|Price $price): Ticker
    {
        $price = Price::toObj($price);

        return new Ticker($this->getSymbol(), $price, $price, $price);
    }

    private function isShowReasonEnabled(): bool
    {
        return $this->paramFetcher->getBoolOption(self::SHOW_STATE_CHANGES);
    }

    private function isShowStateChangesEnabled(): bool
    {
        return $this->paramFetcher->getBoolOption(self::SHOW_STATE_CHANGES);
    }

    private function isShowCumulativeStateChangesEnabled(): bool
    {
        return $this->paramFetcher->getBoolOption(self::SHOW_CUMULATIVE_STATE_CHANGES);
    }

    private bool $isPositionLiquidationPrinted = false;

    private function printPositionLiquidationRow(Position $position, array &$rows): void
    {
        if (!$this->isPositionLiquidationPrinted) {
            $rows[] = self::infoRow(new TableSeparator());
            $rows[] = self::infoRow([TH::cell($this->priceFormatter->format($position->liquidationPrice), 2, align: 'right', fontColor: 'bright-red'), TH::cell(content: 'position liquidation', col: 6, fontColor: 'bright-red', align: 'center')]);
            $rows[] = self::infoRow(new TableSeparator());
            $this->isPositionLiquidationPrinted = true;
        }
    }

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly StopRepository           $stopRepository,
        private readonly BuyOrderRepository       $buyOrderRepository,
        PositionServiceInterface                  $positionService,
        private readonly TradingSandboxFactory    $tradingSandboxFactory,
        private readonly MarketBuyCheckService    $marketBuyCheckService,
        string                                    $name = null,
    ) {
        $this->withPositionService($positionService);

        parent::__construct($name);
    }
}
