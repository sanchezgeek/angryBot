<?php

declare(strict_types=1);

namespace App\Command\Orders\OrdersInfoTable\Dto;

use App\Application\UseCase\Trading\Sandbox\Dto\Out\ExecutionStepResult;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\OrderExecutionResult;
use App\Domain\Price\Price;

class SandboxExecStepRow implements OrdersInfoTableRowAtPriceInterface
{
    public function __construct(public ExecutionStepResult $stepResult)
    {
    }

    public function getRowUpperPrice(): Price
    {
        if ($this->stepResult->isOnlySingleItem()) {
            return Price::float($this->stepResult->getSingleItem()->order->price);
        }

        $prices = array_map(static fn (OrderExecutionResult $executionResult) => $executionResult->order->price, $this->stepResult->getItems());

        return Price::float(max($prices));
    }
}
