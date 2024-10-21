<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Output;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\ExecutionStepResult;
use App\Bot\Domain\Position;
use App\Domain\Pnl\Helper\PnlFormatter;
use App\Domain\Price\Helper\PriceFormatter;
use App\Domain\Price\PriceMovement;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\Color;

abstract class AbstractExecStepResultTableRowBuilder
{
    public function __construct(
        protected readonly ?PnlFormatter   $pnlFormatter = null,
        protected readonly ?PriceFormatter $priceFormatter = null,
    ) {
    }

    protected static function highlightedOrderCell(ExecutionStepResult $step, string $content, ?CellStyle $forceStyle = null): Cell|string
    {
        if (!$step->isOnlySingleItem()) {
            return $content;
        }

        $order = $step->getSingleItem()->order;

        # highlight cell if order has been executed
        return $step->hasOrdersExecuted() ? match (true) {
            $order instanceof SandboxStopOrder => new Cell($content, $forceStyle ?? new CellStyle(backgroundColor: Color::BRIGHT_RED)),
            $order instanceof SandboxBuyOrder => new Cell($content, $forceStyle ?? new CellStyle(fontColor: Color::GREEN)),
        } : new Cell($content, new CellStyle(fontColor: Color::GRAY));
    }

    protected static function formatOrderId(SandboxStopOrder|SandboxBuyOrder $order): string
    {
        return match (true) {
            $order instanceof SandboxStopOrder => sprintf('s.%d', $order->sourceOrder->getId()),
            $order instanceof SandboxBuyOrder => sprintf('b.%d', $order->sourceOrder->getId()),
        };
    }

    protected function getLiquidationPriceDiffWithPrev(Position $positionBefore, Position $positionAfter): string
    {
        $liquidationPriceMoveFromPrev = PriceMovement::fromToTarget($positionBefore->liquidationPrice, $positionAfter->liquidationPrice);
        $liquidationPriceDiffWithPrev = $liquidationPriceMoveFromPrev->deltaForPositionLoss($positionBefore->side);
        if ($liquidationPriceDiffWithPrev === 0.00) {
            $liquidationPriceDiffWithPrev = 0;
        }

        if ($liquidationPriceDiffWithPrev > 0) {
            $liquidationPriceDiffWithPrev = '+' . $this->priceFormatter->format($liquidationPriceDiffWithPrev);
        }

        return (string)$liquidationPriceDiffWithPrev;
    }

    protected function getOrderInfo(SandboxStopOrder|SandboxBuyOrder $order): array
    {
        $sourceOrder = $order->sourceOrder;

        $info = [];
        if ($order instanceof SandboxStopOrder) {

            $info[] = match (true) {
                $sourceOrder->isTakeProfitOrder() => 'TakeProfit order',
                $sourceOrder->isCloseByMarketContextSet() => '!by market!',
                default => 'Conditional order'
            };
            !$sourceOrder->isWithOppositeOrder() && $info[] = 'without opposite BO';
        } else {
            $sourceOrder->isForceBuyOrder() && $info[] = '!force buy!';
            $sourceOrder->isOppositeBuyOrderAfterStopLoss() && $info[] = 'opposite BuyOrder after SL';
            !$sourceOrder->isWithOppositeOrder() && $info[] = 'without Stop';
        }
        // opposite?

        return $info;
    }
}
