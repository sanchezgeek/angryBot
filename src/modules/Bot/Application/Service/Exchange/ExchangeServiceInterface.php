<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Application\Service\Exchange\Exchange\InstrumentInfoDto;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Domain\Price\PriceRange;
use App\Trading\Domain\Symbol\SymbolInterface;

interface ExchangeServiceInterface
{
    public function ticker(SymbolInterface $symbol): Ticker;

    /**
     * @return ActiveStopOrder[]
     */
    public function activeConditionalOrders(?SymbolInterface $symbol = null, ?PriceRange $priceRange = null): array;

    public function closeActiveConditionalOrder(ActiveStopOrder $order): void;
}
