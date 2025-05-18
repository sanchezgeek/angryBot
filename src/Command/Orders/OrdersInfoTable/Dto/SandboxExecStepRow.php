<?php

declare(strict_types=1);

namespace App\Command\Orders\OrdersInfoTable\Dto;

use App\Application\UseCase\Trading\Sandbox\Dto\Out\ExecutionStepResult;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\OrderExecutionResult;
use App\Domain\Price\SymbolPrice;

class SandboxExecStepRow implements OrdersInfoTableRowAtPriceInterface
{
    public function __construct(public ExecutionStepResult $stepResult)
    {
    }

    public function getRowUpperPrice(): SymbolPrice
    {
        if ($this->stepResult->isOnlySingleItem()) {
            $order = $this->stepResult->getSingleItem()->order;

            return $order->symbol->makePrice($order->price);
        }

        $prices = array_map(static fn (OrderExecutionResult $executionResult) => $executionResult->order->price, $this->stepResult->getItems());

        return $this->stepResult->getFirstItem()->order->symbol->makePrice(max($prices));
    }
}
