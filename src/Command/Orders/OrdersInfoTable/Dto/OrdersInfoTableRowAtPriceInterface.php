<?php

declare(strict_types=1);

namespace App\Command\Orders\OrdersInfoTable\Dto;

use App\Domain\Price\SymbolPrice;

interface OrdersInfoTableRowAtPriceInterface
{
    public function getRowUpperPrice(): SymbolPrice;
}
