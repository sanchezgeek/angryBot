<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Application\Service\Exchange\Exchange\InstrumentInfoDto;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Price\PriceRange;

interface ExchangeServiceInterface
{
    public function ticker(SymbolInterface $symbol): Ticker;

    /**
     * @return ActiveStopOrder[]
     */
    public function activeConditionalOrders(?SymbolInterface $symbol = null, ?PriceRange $priceRange = null): array;

    public function closeActiveConditionalOrder(ActiveStopOrder $order): void;

    public function getInstrumentInfo(SymbolInterface|string $symbol): InstrumentInfoDto;
}
