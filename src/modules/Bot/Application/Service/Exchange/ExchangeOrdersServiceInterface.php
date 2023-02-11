<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\ValueObject\Symbol;

interface ExchangeOrdersServiceInterface
{
    /**
     * @return ActiveStopOrder[]
     */
    public function getActiveConditionalOrders(Symbol $symbol): array;

    public function closeActiveConditionalOrder(ActiveStopOrder $order);
}
