<?php

declare(strict_types=1);

namespace App\Command\Orders\OrdersInfoTable\Dto;

use App\Bot\Domain\Position;
use App\Domain\Price\Price;

class PositionLiquidationRow implements OrdersInfoTableRowAtPriceInterface
{
    public function __construct(public Position $liquidatedPosition)
    {
    }

    public function getRowUpperPrice(): Price
    {
        return $this->liquidatedPosition->liquidationPrice();
    }
}
