<?php

declare(strict_types=1);

namespace App\Command\Orders\OrdersInfoTable\Dto;

use App\Bot\Domain\Position;
use App\Domain\Price\SymbolPrice;

class PositionLiquidationRow implements OrdersInfoTableRowAtPriceInterface
{
    public function __construct(public Position $liquidatedPosition)
    {
    }

    public function getRowUpperPrice(): SymbolPrice
    {
        return $this->liquidatedPosition->liquidationPrice();
    }
}
