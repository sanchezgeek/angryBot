<?php

namespace App\Bot\Application\Service\Exchange;

use App\Trading\Domain\Symbol\SymbolInterface;

interface MarketServiceInterface
{
    public function getPreviousPeriodFundingRate(SymbolInterface $symbol): float;
    public function getPreviousFundingRatesHistory(SymbolInterface $symbol, int $limit): array;

    public function isNowFundingFeesPaymentTime(): bool;
}
