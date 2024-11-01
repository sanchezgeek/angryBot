<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Output;

use App\Application\UseCase\Trading\MarketBuy\Exception\BuyIsNotSafeException;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\ExecutionStepResult;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\OrderExecutionResult;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionLiquidatedBeforeOrderPriceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionNotFoundException;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Domain\Order\Contract\OrderTypeAwareInterface;
use App\Domain\Pnl\Helper\PnlFormatter;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceFormatter;
use App\Domain\Price\Price;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\DataRow;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\Color;
use App\Output\Table\Dto\Style\RowStyle;

/**
 * @todo in other mode it must be separated rows by default
 */
final class ExecStepResultDefaultTableRowBuilder extends AbstractExecStepResultTableRowBuilder
{
    public const ID_COL = 'id';
    public const PRICE_COL = 'price';
    public const VOLUME_COL = 'volume';
    public const PNL_COL = 'pnl';
    public const STATE_WRAPPER_COL = 'stateWrapper';
    public const POSITION_SIZE_COL = 'pos.size';
    public const POSITION_ENTRY_COL = 'pos.entry';
    public const POSITION_LIQUIDATION_COL = 'pos.liquidation';
    public const COMMENT_COL = 'comment';

    private array $positionNotFoundOrLiquidatedRowCells;

    private array $stateChangedRowCells;
    private array $positionClosedRowCells;

    public function __construct(
        private readonly Ticker $ticker,
        private readonly Side $targetPositionSide,
        ?PnlFormatter $pnlFormatter = null,
        ?PriceFormatter $priceFormatter = null,
        ?array $enabledColumns = null,
    ) {
        parent::__construct($pnlFormatter, $priceFormatter);

        $this->stateChangedRowCells = [
            self::ID_COL => function (ExecutionStepResult $step) {
                if ($step->isOnlySingleItem()) {
                    $content = self::formatOrderId($step->getSingleItem()->order);
                    if ($step->getSingleItem()->order instanceof SandboxStopOrder) {
                        return self::highlightedSingleOrderCell($step, $content);
                    }
                } else {
                    $totalBuyItemsQnt = count($step->filterItems(static fn(OrderExecutionResult $result) => $result->order->getOrderType() === OrderType::Add));
                    $totalStopItemsQnt = count($step->filterItems(static fn(OrderExecutionResult $result) => $result->order->getOrderType() === OrderType::Stop));
                    $executedBuyItemsQnt = count($step->filterItems(static fn(OrderExecutionResult $result) => $result->isOrderExecuted() && $result->order->getOrderType() === OrderType::Add));
                    $executedStopItemsQnt = count($step->filterItems(static fn(OrderExecutionResult $result) => $result->isOrderExecuted() && $result->order->getOrderType() === OrderType::Stop));

                    $extendedDesc = [];

                    $execDesc = [];
                    ($executedBuyItemsQnt) && $execDesc[] = sprintf('%db', $executedBuyItemsQnt);
                    ($executedStopItemsQnt) && $execDesc[] = sprintf('%ds', $executedStopItemsQnt);
                    $execDesc = implode('+', $execDesc);

                    $totalDesc = [];
                    ($totalBuyItemsQnt) && $totalDesc[] = sprintf('%db', $totalBuyItemsQnt);
                    ($totalStopItemsQnt) && $totalDesc[] = sprintf('%ds', $totalStopItemsQnt);
                    $totalDesc = implode('+', $totalDesc);

                    $execDesc && ($execDesc .= ' exec.') && $extendedDesc[] = $execDesc;

                    if ($step->getExecutedCount() === $step->itemsCount()) {
                        $content = $execDesc;
                    } else {
                        $totalDesc && ($totalDesc .= ' total') && $extendedDesc[] = $totalDesc;

                        $content = implode(' / ', $extendedDesc);
                    }
                }

                return !$step->hasOrdersExecuted() ? new Cell($content, new CellStyle(fontColor: Color::GRAY)) : $content;
            },
            self::PRICE_COL => function (ExecutionStepResult $step) {
                if ($step->isOnlySingleItem()) {
                    $content = implode(', ', array_map(static fn (OrderExecutionResult $execResult) => $execResult->order->price, $step->getItems()));
                    if ($step->getSingleItem()->order instanceof SandboxStopOrder) {
                        return self::highlightedSingleOrderCell($step, $content);
                    }
                } else {
                    $fromPrice = $step->getFirstItem()->order->price;
                    $toPrice = $step->getLastItem()->order->price;
                    if ($this->ticker->getMinPrice()->lessOrEquals($fromPrice)) {
                        [$fromPrice, $toPrice] = [$toPrice, $fromPrice];
                    }

                    $content = sprintf('%s - %s', $this->priceFormatter->format($fromPrice), $this->priceFormatter->format($toPrice));
                }

                return !$step->hasOrdersExecuted() ? new Cell($content, new CellStyle(fontColor: Color::GRAY)) : $content;
            },
            self::VOLUME_COL => function (ExecutionStepResult $step) {
                if ($step->isOnlySingleItem()) {
                    $order = $step->getSingleItem()->order;
                    $content = sprintf('%s %s', $order instanceof SandboxStopOrder ? '-' : '+', $order->volume);
                    return self::highlightedSingleOrderCell($step, $content);
                } else {
                    # @todo Mb get only for $this->targetPositionSide? Or do some previous check before do work on ExecutionStepResult (orders must be on one side)
                    if ($step->hasOrdersExecuted()) {
                        $volume = $step->getTotalVolumeExecuted();
                    } else {
                        $volume = $step->getTotalVolume();
                    }

                    [$sign, $style] = match(true) {
                        $volume < 0 =>  ['- ', new CellStyle(fontColor: Color::BRIGHT_RED)],
                        $volume > 0 => ['+ ', new CellStyle(fontColor: Color::BRIGHT_GREEN)],
                        default => ''
                    };

                    $content = sprintf('%s%s', $sign, abs($volume) ?: ($step->hasOrdersExecuted() ? '=> 0.0' : ''));

                    return self::highlightedMultipleOrdersCell($step, $content, $style);
                }
            },
            self::PNL_COL => function (ExecutionStepResult $step) {
                if (!($totalPnl = $step->getTotalPnl())) {
                    return '';
                }

                return $this->pnlFormatter ? $this->pnlFormatter->format($totalPnl) : $totalPnl;
            },
            self::STATE_WRAPPER_COL => function (ExecutionStepResult $step) {
                return $step->hasOrdersExecuted() ? '=>' : '';
            },
            self::POSITION_SIZE_COL => function (ExecutionStepResult $step) {
                if (!$step->hasOrdersExecuted()) {
                    return '';
                }
                return $step->getStateAfter()->getPosition($this->targetPositionSide)?->size;
            },
            self::POSITION_ENTRY_COL => function (ExecutionStepResult $step) {
                if (!$step->hasOrdersExecuted()) {
                    return null;
                }
                $newEntry = $step->getStateAfter()->getPosition($this->targetPositionSide)?->entryPrice;
                if (!$newEntry) {
                    return null;
                }

                $oldEntry = $step->getStateBefore()->getPosition($this->targetPositionSide)?->entryPrice;
                return $oldEntry === $newEntry ? null : $this->priceFormatter->format($newEntry);

            },
            self::POSITION_LIQUIDATION_COL => function (ExecutionStepResult $step) {
                // or check it just by states diff?
                if (!$step->hasOrdersExecuted()) {
                    return null;
                }

                $positionBefore = $step->getStateBefore()->getPosition($this->targetPositionSide);
                $positionAfter = $step->getStateAfter()->getPosition($this->targetPositionSide);

                $cellStyle = CellStyle::default();

                // @todo | case when position initially is not support ?
                if ($positionAfter?->isSupportPosition()) {
                    $mainPositionSide = $this->targetPositionSide->getOpposite();
                    if (!$positionBefore?->isSupportPosition()) {
                        $oppositePositionAfterExec = $step->getStateAfter()->getPosition($mainPositionSide);
                        return new Cell(
                            sprintf('became support (%s.liquidation = %s)', $mainPositionSide->title(), $this->priceFormatter->format($oppositePositionAfterExec->liquidationPrice)),
                            new CellStyle(fontColor: Color::CYAN)
                        );
                    }

                    [$diff, $info] = $this->formatInfoAboutMainPositionLiquidationChanges(
                        $step,
                        $mainPositionSide
                    );

                    if (!$diff) {
                        return '';
                    }

                    $cellStyle = match (true) {
                        $diff > 0 => new CellStyle(fontColor: Color::BRIGHT_GREEN),
                        $diff < 0 => new CellStyle(fontColor: Color::BRIGHT_RED),
                        default => CellStyle::default()
                    };
                    return self::highlightedSingleOrderCell($step, $info, $cellStyle);
                } elseif ($positionAfter) {
                    $resultText = $this->priceFormatter->format($positionAfter->liquidationPrice);
                    if ($positionAfter->isMainPosition() && !$positionBefore?->isMainPosition()) {
                        $resultText .= ' (became main)';
                        $cellStyle = new CellStyle(fontColor: Color::BRIGHT_RED);
                    } elseif (!(
                        # skip if liquidationPrice has been appeared only now
                        $positionAfter->liquidationPrice && !$positionBefore?->liquidationPrice
                    )) {
                        $resultText .= sprintf(' ( %s )', $this->getLiquidationPriceDiffWithPrev($positionBefore, $positionAfter));
                    }
                }

                return isset($resultText) ? new Cell($resultText, $cellStyle) : '';
            },
            self::COMMENT_COL => function (ExecutionStepResult $step, RowStyle $rowStyle) {
                $info = [];

                if ($step->isOnlySingleItem()) {
                    $additionalInfo = [];

                    $orderExecutionResult = $step->getSingleItem();
                    if ($additionalOrderInfo = $this->getOrderInfo($orderExecutionResult->order)) {
                        $additionalInfo[] = implode(', ', $additionalOrderInfo);
                    }

                    if ($orderExecutionResult->failReason?->exception instanceof BuyIsNotSafeException) {
                        $additionalInfo[] = sprintf('won\'t be executed (%s)', $orderExecutionResult->failReason->exception->getMessage());
                    } elseif ($orderExecutionResult->failReason?->exception instanceof SandboxInsufficientAvailableBalanceException) {
                        $additionalInfo[] =  sprintf('cannot buy (%s)', $orderExecutionResult->failReason->exception->getMessage());
                    }

                    if ($additionalInfo) {
                        $info[] = implode(' | ', $additionalInfo);
                    }
                }

                if ($step->isPositionBeingOpenedThroughStep($this->targetPositionSide)) {
                    $info[] = 'POSITION OPENED => don\'t forget to remove stale stops before it';
                }

                if (count($info) > 1) {
                    $rowStyle->separated = true;
                }

                return implode(PHP_EOL, $info);
            }
        ];

        if ($enabledColumns) {
            $this->stateChangedRowCells = array_intersect_key($this->stateChangedRowCells, array_flip($enabledColumns));
        }

        $this->positionClosedRowCells = [
            $this->stateChangedRowCells[self::ID_COL],
            $this->stateChangedRowCells[self::PRICE_COL],
            $this->stateChangedRowCells[self::VOLUME_COL],
            $this->stateChangedRowCells[self::PNL_COL],
            $this->stateChangedRowCells[self::STATE_WRAPPER_COL],
            function (ExecutionStepResult $step) {
                $cells[] = $mainCell = Cell::default('       POSITION CLOSED')->addStyle(CellStyle::right())->setColspan(2);

                $positionBefore = $step->getStateBefore()->getPosition($this->targetPositionSide);
                if ($positionBefore && $positionBefore->isSupportPosition()) {
                    [$diff, $info] = $this->formatInfoAboutMainPositionLiquidationChanges($step, $this->targetPositionSide->getOpposite());
                    $cells[] = self::highlightedSingleOrderCell(
                        $step,
                        $info,
                        $diff > 0 ? new CellStyle(fontColor: Color::BRIGHT_GREEN) : new CellStyle(fontColor: Color::BRIGHT_RED)
                    );
                } else {
                    $mainCell->style->addColspan(1);
                }

                return $cells;
            }
        ];

        $this->positionNotFoundOrLiquidatedRowCells = [
            $this->stateChangedRowCells[self::ID_COL],
            $this->stateChangedRowCells[self::PRICE_COL],
            $this->stateChangedRowCells[self::VOLUME_COL],
            function () {
                return Cell::restColumnsMerged();
            },
            $this->stateChangedRowCells[self::COMMENT_COL],
        ];
    }

    public function build(ExecutionStepResult $step): DataRow
    {
        $rowStyle = RowStyle::default();
        $formatters = $this->stateChangedRowCells;
        if ($step->isPositionBeingClosedThroughStep($this->targetPositionSide)) {
            $formatters = $this->positionClosedRowCells;
            $rowStyle = RowStyle::separated();
        } elseif ($step->isPositionBeingOpenedThroughStep($this->targetPositionSide)) {
            $rowStyle = RowStyle::separated();
        } elseif (
            !$step->hasOrdersExecuted()
            && $step->getFirstItem()->failReason->isExceptionOneOf([SandboxPositionNotFoundException::class, SandboxPositionLiquidatedBeforeOrderPriceException::class])
        ) {
            // position not found
            $formatters = $this->positionNotFoundOrLiquidatedRowCells;
        }

        if (!$step->hasOrdersExecuted()) {
            $rowStyle->fontColor = Color::GRAY;
        }

        $cells = [];
        foreach ($formatters as $formatter) {
            $cell = $formatter($step, $rowStyle);
            if (is_array($cell)) {
                $cells = array_merge($cells, $cell);
            } else {
                $cells[] = $cell instanceof Cell ? $cell : new Cell($cell);
            }
        }

        return new DataRow($rowStyle, ...$cells);
    }

    private function formatInfoAboutMainPositionLiquidationChanges(ExecutionStepResult $step, Side $mainPositionSide): array
    {
        $oppositePositionBeforeExec = $step->getStateBefore()->getPosition($mainPositionSide);
        $oppositePositionAfterExec = $step->getStateAfter()->getPosition($mainPositionSide);
        $diff = $this->getLiquidationPriceDiffWithPrev($oppositePositionBeforeExec, $oppositePositionAfterExec);
        return [(float)$diff, sprintf(' %s to %s.liq.distance => %s', $diff, $mainPositionSide->title(), $this->priceFormatter->format($oppositePositionAfterExec->liquidationPrice))];
    }
}
