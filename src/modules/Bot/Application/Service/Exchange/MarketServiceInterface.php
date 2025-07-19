<?php

namespace App\Bot\Application\Service\Exchange;

use App\Trading\Domain\Symbol\SymbolInterface;

interface MarketServiceInterface
{
    public function getPreviousPeriodFundingRate(SymbolInterface $symbol, int $limit = 1): float;

    public function isNowFundingFeesPaymentTime(): bool;
}
