<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Output;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\ExecutionStepResult;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Domain\Pnl\Helper\PnlFormatter;
use App\Domain\Price\Helper\PriceFormatter;
use App\Domain\Price\PriceMovement;
use App\Output\Table\Dto\Cell;
use App\Output\Table\Dto\Style\CellStyle;
use App\Output\Table\Dto\Style\Enum\Color;
use LogicException;

abstract class AbstractExecStepResultTableRowBuilder
{
    public function __construct(
        protected readonly ?PnlFormatter   $pnlFormatter = null,
        protected readonly ?PriceFormatter $priceFormatter = null,
    ) {
    }

    protected static function highlightedSingleOrderCell(ExecutionStepResult $step, mixed $content, ?CellStyle $forceStyle = null): Cell
    {
        if (!$step->isOnlySingleItem()) {
            throw new LogicException('Wrong method call');
        }

        $order = $step->getSingleItem()->order;

        # highlight cell if order has been executed
        return $step->hasOrdersExecuted() ? match (true) {
            $order instanceof SandboxStopOrder => new Cell($content, $forceStyle ?? new CellStyle(backgroundColor: Color::BRIGHT_RED)),
            $order instanceof SandboxBuyOrder => new Cell($content, $forceStyle ?? new CellStyle(fontColor: Color::GREEN)),
        } : new Cell($content, new CellStyle(fontColor: Color::GRAY));
    }

    protected static function highlightedMultipleOrdersCell(ExecutionStepResult $step, mixed $content, ?CellStyle $fallbackStyle = null): Cell
    {
        if ($step->isOnlySingleItem()) {
            throw new LogicException('Wrong method call');
        }

        if (!$step->hasOrdersExecuted()) {
            return new Cell($content, new CellStyle(fontColor: Color::GRAY));
        }

        $executedOrderTypes = self::getExecutedThroughStepOrdersType($step);
        $isOnlySingleItem = count($executedOrderTypes) === 1;
        $style = match (true) {
            $isOnlySingleItem && $executedOrderTypes[0] === OrderType::Stop => new CellStyle(backgroundColor: Color::BRIGHT_RED),
            $isOnlySingleItem && $executedOrderTypes[0] === OrderType::Add => new CellStyle(fontColor: Color::GREEN),
            default => $fallbackStyle ?: new CellStyle(),
        };

        return new Cell($content, $style);
    }

    /**
     * @return OrderType[]
     */
    private static function getExecutedThroughStepOrdersType(ExecutionStepResult $step): array
    {
        $types = [];

        foreach ($step->getItems() as $item) {

            if (!$item->isOrderExecuted()) {
                continue;
            }

            $type = match (true) {
                $item->order instanceof SandboxStopOrder => OrderType::Stop->value,
                $item->order instanceof SandboxBuyOrder => OrderType::Add->value,
            };
            $types[$type] = true;
        }

        return array_map(static fn(string $type) => OrderType::from($type), array_keys($types));
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

    protected function getSourceOrderInfo(BuyOrder|Stop $sourceOrder): array
    {
        $info = [];
        if ($sourceOrder instanceof Stop) {
            $info[] = match (true) {
                $sourceOrder->isTakeProfitOrder() => 'TakeProfit order',
                $sourceOrder->isCloseByMarketContextSet() => '!by market!',
                default => sprintf('Conditional order (td=%.2f)', $this->priceFormatter->format($sourceOrder->getTriggerDelta())),
            };
            !$sourceOrder->isWithOppositeOrder() && $info[] = 'without opposite BO';
            $sourceOrder->isOrderPushedToExchange() && $info[] = 'PUSHED TO EXCHANGE';
//            $sourceOrder->getExchangeOrderId() && $info[] = sprintf('%s', $sourceOrder->getExchangeOrderId());
            // @todo | move to context
            $sourceOrder->getContext('fromExchangeWithoutExistedStop') && $info[] = 'fromExchange (without ExistedStop)';
        } else {
            $sourceOrder->isForceBuyOrder() && $info[] = '!force!';

            if ($sourceOrder->isOppositeBuyOrderAfterStopLoss()) {
                $info[] = sprintf('after SL (s.%d, %s)', $sourceOrder->getOppositeStopId(), $sourceOrder->isOppositeStopExecuted() ? 'executed' : 'active');
            }

            !$sourceOrder->isWithOppositeOrder() && $info[] = 'without Stop';
        }
        // opposite?

        return $info;
    }
}
