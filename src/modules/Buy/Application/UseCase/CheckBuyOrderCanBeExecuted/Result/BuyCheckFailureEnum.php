<?php

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result;

use App\Trading\SDK\Check\Contract\Dto\Out\TradingCheckFailedReason;

enum BuyCheckFailureEnum implements TradingCheckFailedReason
{
    case FurtherLiquidationIsTooClose;
    case BuyOrderPlacedTooFarFromPositionEntry;
}
