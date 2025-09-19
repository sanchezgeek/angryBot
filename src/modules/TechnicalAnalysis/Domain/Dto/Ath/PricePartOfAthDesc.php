<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Domain\Dto\Ath;

enum PricePartOfAthDesc: string
{
    case MovedOverHigh = 'moved-over-high';
    case InBetween = 'in-between';
    case MovedOverLow = 'moved-over-low';
}
