<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\PriceRange;

interface ExchangeServiceInterface
{
    public function ticker(Symbol $symbol): Ticker;

    /**
     * @return ActiveStopOrder[]
     */
    public function activeConditionalOrders(Symbol $symbol, ?PriceRange $priceRange = null): array;

    public function closeActiveConditionalOrder(ActiveStopOrder $order): void;
}
