<?php

declare(strict_types=1);

namespace App\Command\Orders\OrdersInfoTable\Dto;

use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Domain\Ticker;
use App\Domain\Price\SymbolPrice;

class InitialStateRow implements OrdersInfoTableRowAtPriceInterface
{
    public function __construct(public Ticker $ticker, public SandboxState $initialSandboxState)
    {
    }

    public function getRowUpperPrice(): SymbolPrice
    {
        return $this->ticker->lastPrice;
    }
}
