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
use App\Domain\Pnl\Helper\PnlFormatter;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceFormatter;
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
        private Side $targetPositionSide,
        ?PnlFormatter $pnlFormatter = null,
        ?PriceFormatter $priceFormatter = null,
        ?array $enabledColumns = null,
    ) {
        parent::__construct($pnlFormatter, $priceFormatter);

        $this->stateChangedRowCells = [
            self::ID_COL => function (ExecutionStepResult $step) {
                $content = implode(', ', array_map(static fn (OrderExecutionResult $execResult) => self::formatOrderId($execResult->order), $step->getItems()));
                if ($step->isOnlySingleItem() && $step->getSingleItem()->order instanceof SandboxStopOrder) {
                    return self::highlightedOrderCell($step, $content);
                }

                return !$step->hasOrdersExecuted() ? new Cell($content, new CellStyle(fontColor: Color::GRAY)) : $content;
            },
            self::PRICE_COL => function (ExecutionStepResult $step) {
                $content = implode(', ', array_map(static fn (OrderExecutionResult $execResult) => $execResult->order->price, $step->getItems()));
                if ($step->isOnlySingleItem() && $step->getSingleItem()->order instanceof SandboxStopOrder) {
                    return self::highlightedOrderCell($step, $content);
                }

                return !$step->hasOrdersExecuted() ? new Cell($content, new CellStyle(fontColor: Color::GRAY)) : $content;
            },
            self::VOLUME_COL => function (ExecutionStepResult $step) {
                $content = [];
                foreach ($step->getItems() as $item) {
                    $content[] = sprintf('%s %s', $item->order instanceof SandboxStopOrder ? '-' : '+', $item->order->volume);
                }
                $content = implode(', ', $content);

                return self::highlightedOrderCell($step, $content);
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
                    [$diff, $info] = $this->formatInfoAboutMainPositionLiquidationChanges(
                        $step,
                        $this->targetPositionSide->getOpposite()
                    );

                    return self::highlightedOrderCell($step, $info, match (true) {
                        $diff > 0 => new CellStyle(fontColor: Color::BRIGHT_GREEN),
                        $diff < 0 => new CellStyle(fontColor: Color::BRIGHT_RED),
                        default => CellStyle::default()
                    });
                } else {
                    $resultText = $this->priceFormatter->format($positionAfter->liquidationPrice);
                    if ($positionAfter?->isMainPosition() && !$positionBefore?->isMainPosition()) {
                        $resultText .= ' (became main)';
                        $cellStyle = new CellStyle(fontColor: Color::BRIGHT_RED);
                    } else {
                        $resultText .= sprintf(' ( %s )', $this->getLiquidationPriceDiffWithPrev($positionBefore, $positionAfter));
                    }
                }

                return new Cell($resultText, $cellStyle);
            },
            self::COMMENT_COL => function (ExecutionStepResult $step, RowStyle $rowStyle) {
                $info = [];

                if ($step->isOnlySingleItem()) {
                    $orderExecutionResult = $step->getSingleItem();
                    $additionalOrderInfo = $this->getOrderInfo($orderExecutionResult->order);
                    $additionalInfo = implode(', ', $additionalOrderInfo);

                    if ($orderExecutionResult->failReason?->exception instanceof BuyIsNotSafeException) {
                        $additionalInfo .= sprintf(' | won\'t be executed (%s)', $orderExecutionResult->failReason->exception->getMessage());
                    } elseif ($orderExecutionResult->failReason?->exception instanceof SandboxInsufficientAvailableBalanceException) {
                        $additionalInfo .= sprintf(' | cannot buy (%s)', $orderExecutionResult->failReason->exception->getMessage());
                    }

                    if ($additionalInfo) {
                        $info[] = $additionalInfo;
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
                    $cells[] = self::highlightedOrderCell(
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
//        } elseif (!$sandboxExecutionStep->hasOrdersExecuted()) {
        } elseif (
            !$step->hasOrdersExecuted()
            && $step->isOnlySingleItem()
            && $step->getSingleItem()->failReason->isExceptionOneOf([SandboxPositionNotFoundException::class, SandboxPositionLiquidatedBeforeOrderPriceException::class])
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
