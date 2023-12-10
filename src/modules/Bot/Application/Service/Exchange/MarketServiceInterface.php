<?php

namespace App\Bot\Application\Service\Exchange;

use App\Bot\Domain\ValueObject\Symbol;

interface MarketServiceInterface
{
    public function getPreviousPeriodFundingRate(Symbol $symbol): float;
}
