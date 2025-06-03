<?php

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;

interface MarketServiceInterface
{
    public function getPreviousPeriodFundingRate(SymbolInterface $symbol): float;

    public function isNowFundingFeesPaymentTime(): bool;
}
