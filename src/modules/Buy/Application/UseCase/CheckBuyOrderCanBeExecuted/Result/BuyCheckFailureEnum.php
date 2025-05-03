<?php

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result;

use App\Trading\Application\Check\Contract\TradingCheckFailedReason;

enum BuyCheckFailureEnum implements TradingCheckFailedReason
{
    case TooManyTries;
    case PreviousCheckNegativeResultUsed;

    case FurtherLiquidationIsTooClose;
}
