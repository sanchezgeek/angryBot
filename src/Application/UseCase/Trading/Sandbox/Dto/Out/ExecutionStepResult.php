<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto\Out;

use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Domain\Position\ValueObject\Side;
use LogicException;
use RuntimeException;

/**
 * One interface?
 */
class ExecutionStepResult
{
    /** @var OrderExecutionResult[] */
    private array $items;

    public function addItem(OrderExecutionResult $item): self
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * @return OrderExecutionResult[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getFirstItem(): ?OrderExecutionResult
    {
        return $this->items[0];
    }

    public function getLastItem(): ?OrderExecutionResult
    {
        return $this->items[array_key_last($this->items)];
    }

    public function itemsCount(): int
    {
        return count($this->items);
    }

    /**
     * @todo | Remove after some Formatter
     */
    public function isOnlySingleItem(): bool
    {
        return count($this->items) === 1;
    }

    /**
     * @todo | Remove after some Formatter
     */
    public function getSingleItem(): OrderExecutionResult
    {
        if (!$this->isOnlySingleItem()) {
            throw new RuntimeException('Wrong usage of ::getSingleItem method');
        }

        if (!$this->itemsCount()) {
            throw new RuntimeException('There are no items');
        }

        return $this->items[0];
    }

    /**
     * @todo | Or check state changes? | Or maybe strictly not (because result state for some reasons may don't differ from initial even if orders was executed)
     */
    public function hasOrdersExecuted(): bool
    {
        foreach ($this->items as $item) {
            if ($item->isOrderExecuted()) {
                return true;
            }
        }

        return false;
    }

    public function getStateBefore(): SandboxState
    {
        return $this->items[0]->inputState;
    }

    public function getStateAfter(): SandboxState
    {
        return $this->items[count($this->items) - 1]->outputState;
    }

    public function getTotalPnl(): ?float
    {
        $totalPnl = null;
        foreach ($this->items as $item) {
            if ($item->isOrderExecuted()) {
                $totalPnl += $item->pnl;
            }
        }

        return $totalPnl;
    }

    public function getTotalVolumeExecuted(): ?float
    {
        $totalVolumeExecuted = 0;
        foreach ($this->filterItems(static fn(OrderExecutionResult $result) => $result->isOrderExecuted()) as $item) {
            $totalVolumeExecuted += $item->order->signedVolume();
        }

        return $totalVolumeExecuted;
    }

    public function getTotalVolume(): ?float
    {
        $totalVolume = 0;
        foreach ($this->filterItems() as $item) {
            $totalVolume += $item->order->signedVolume();
        }

        return $totalVolume;
    }

    public function getExecutedCount(): int
    {
        $executedCount = 0;
        foreach ($this->items as $item) {
            ($item->isOrderExecuted()) && $executedCount++;
        }

        return $executedCount;
    }

    /**
     * @return OrderExecutionResult[]
     */
    public function filterItems(?callable $callback = null, bool $checkOrdersOnOneSide = true): array
    {
        $result = [];

        $lastOrderSide = null;
        foreach ($this->items as $item) {
            $order = $item->order;
            if ($checkOrdersOnOneSide && $lastOrderSide !== null && $lastOrderSide !== $order->positionSide) {
                throw new LogicException('Bad method call: orders executed on step must be on one side');
            }
            $lastOrderSide = $order->positionSide;

            if ($callback !== null && !$callback($item)) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }

    public function isPositionBeingClosedThroughStep(Side $positionSide): bool
    {
        return $this->getStateBefore()->getPosition($positionSide) !== null && $this->getStateAfter()->getPosition($positionSide) === null;
    }

    public function isPositionBeingOpenedThroughStep(Side $positionSide): bool
    {
        return $this->getStateBefore()->getPosition($positionSide) === null && $this->getStateAfter()->getPosition($positionSide) !== null;
    }

    public function getPositionFlowComments(): array
    {
        return [];
    }
}
