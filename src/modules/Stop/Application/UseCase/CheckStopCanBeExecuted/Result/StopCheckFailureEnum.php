<?php

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Result;

use App\Trading\SDK\Check\Contract\Dto\Out\TradingCheckFailedReason;

enum StopCheckFailureEnum implements TradingCheckFailedReason
{
    case FurtherMainPositionLiquidationIsTooClose;
}
